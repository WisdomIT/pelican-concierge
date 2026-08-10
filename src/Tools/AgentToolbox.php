<?php

namespace WisdomIT\Concierge\Tools;

use App\Enums\SubuserPermission;
use App\Models\Server;
use App\Models\User;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Repositories\Daemon\DaemonServerRepository;
use Throwable;
use WisdomIT\Concierge\Catalog\GameCatalog;
use WisdomIT\Concierge\Models\ConciergeInstallCheck;
use App\Enums\ServerState;
use App\Models\Allocation;
use App\Models\Backup;
use App\Helpers\Utilities;
use App\Models\Schedule;
use App\Models\ServerVariable;
use App\Models\Task;
use App\Repositories\Daemon\DaemonBackupRepository;
use App\Services\Backups\InitiateBackupService;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use App\Services\Servers\BuildModificationService;
use WisdomIT\Concierge\Models\ConciergeBackupWatch;
use WisdomIT\Concierge\Services\ModInstaller;
use WisdomIT\Concierge\Services\PlayerCount;
use WisdomIT\Concierge\Catalog\JavaRuntime;
use WisdomIT\Concierge\Support\CronSchedule;
use WisdomIT\Concierge\Support\FilePaths;
use WisdomIT\Concierge\Support\PortPool;
use WisdomIT\Concierge\Support\Tenancy;
use WisdomIT\Concierge\Support\ServerLinks;
use WisdomIT\Concierge\Services\ServerProvisioner;
use WisdomIT\Concierge\Support\ConsoleLog;
use WisdomIT\Concierge\Support\SecretMasker;

/**
 * 도구 모음 — 정의와 실행이 한 곳에 있다.
 *
 * 설계 원칙 (#13):
 *  - Pelican 내부 서비스를 직접 호출한다. REST API 를 경유하지 않는다.
 *  - **모든 도구가 `serverFor()` 를 지난다.** 모델이 준 서버 식별자는 힌트일 뿐이고,
 *    요청자의 접근 가능 목록에서 다시 찾은 뒤 **그 도구에 필요한 권한까지** 확인한다.
 *  - 서버에서 읽어온 텍스트는 모델에 넘기기 전에 **반드시** 시크릿을 가린다.
 *  - 도구는 의미 단위여야 한다. 내부 API 를 그대로 노출하지 않는다.
 *  - 상태를 바꾸는 도구(`CONFIRM_TOOLS`)는 실행 전에 확인 카드를 거친다. 모델 재량 금지.
 *
 * 아직 없는 것: 파일 생성·삭제, 자원 한도 변경, 서버 삭제.
 */
final class AgentToolbox
{
    /** 모델에 넘기는 텍스트 상한. 넘으면 뒤쪽(최근)을 남긴다 — 로그는 끝이 중요하다. */
    private const MAX_TEXT = 8000;

    /** 데몬에서 내려받는 파일 크기 상한. 큰 월드 파일을 통째로 끌어오지 않기 위함. */
    private const MAX_FILE_BYTES = 65536;

    private const MAX_DIRECTORY_ENTRIES = 200;

    /** 콘솔에서 가져올 줄 수. 죽는 이유는 보통 마지막 수십 줄에 있다. */
    private const CONSOLE_LINES = 200;

    /**
     * 이번 호출에서 마지막으로 해석된 서버.
     *
     * 실패 결과에도 서버를 붙이기 위해 둔다 — **실패했을 때야말로 어느 서버인지 알아야 한다.**
     * 예외 경로에서는 반환값이 없어 서버를 알 방법이 이것밖에 없다.
     */
    private ?int $lastServerId = null;

    /** 도구 선별용 캐시 — 한 요청에서 여러 번 묻는다. */
    private ?int $serverCount = null;

    private ?bool $hasModServer = null;

    /** @var ?array<int, string> */
    private ?array $grantedPermissions = null;

    private readonly GameCatalog $catalog;

    public function __construct(private readonly User $user)
    {
        $this->catalog = new GameCatalog();
    }

    /**
     * Anthropic API 에 넘길 도구 정의.
     *
     * description 은 **언제 부르는지**까지 적는다 — 무엇을 하는지만 적으면 호출률이 떨어진다.
     *
     * @return array<int, array<string, mixed>>
     */
    /**
     * 서버가 하나도 없는 사용자에게 줄 도구 (#47).
     *
     * 나머지 서버 도구 30여 종은 **부를 대상이 없다** — 정의만 30KB 를 차지하며 매 요청
     * 실려 간다. 상황에 맞는 것만 준다.
     */
    private const NO_SERVER_TOOLS = ['list_my_servers', 'list_available_games', 'create_server', 'suggest_page'];

    /** 모드·플러그인 도구. 쓸 수 있는 서버가 하나도 없으면 뺀다. */
    private const MOD_TOOLS = ['search_mods', 'install_mod', 'list_installed_mods', 'uninstall_mod', 'update_mod'];

    public function definitions(): array
    {
        return array_values(array_filter(
            $this->allDefinitions(),
            fn (array $tool) => $this->isRelevant((string) $tool['name']),
        ));
    }

    /**
     * 이 사용자에게 의미 있는 도구인가 (#47).
     *
     * ⚠ 도구를 빼면 **시스템 프롬프트의 "할 수 있는 것" 절과 어긋날 수 있다.** 없는 도구를
     *   있다고 말하면 사용자가 헛되이 기다린다 — `contextNote()` 가 그 상황을 프롬프트에 알린다.
     */
    private function isRelevant(string $name): bool
    {
        if ($this->serverCount() === 0) {
            return in_array($name, self::NO_SERVER_TOOLS, true);
        }

        if (in_array($name, self::MOD_TOOLS, true) && !$this->hasModServer()) {
            return false;
        }

        // ⚠ 어느 서버에서도 못 쓰는 도구는 주지 않는다(#47·#48). 주면 모델이 불러 보고
        //   "권한 없음"으로 실패한다 — 왕복이 낭비되고 사용자는 기다린다. 실제로 그랬다.
        $permission = self::TOOL_PERMISSIONS[$name] ?? null;

        return $permission === null || in_array($permission->value, $this->grantedPermissions(), true);
    }

    /**
     * 접근 가능한 서버 **중 하나에서라도** 가진 권한들.
     *
     * 서버마다 권한이 다를 수 있어서 합집합으로 본다 — 한 서버에서만 되는 도구도 줘야 한다.
     * 그 도구를 다른 서버에 쓰면 serverFor() 가 그때 막는다.
     *
     * @return array<int, string>
     */
    private function grantedPermissions(): array
    {
        if ($this->grantedPermissions !== null) {
            return $this->grantedPermissions;
        }

        $granted = [];

        foreach ($this->user->accessibleServers()->get() as $server) {
            foreach (self::TOOL_PERMISSIONS as $permission) {
                if (!in_array($permission->value, $granted, true) && $this->user->can($permission, $server)) {
                    $granted[] = $permission->value;
                }
            }
        }

        return $this->grantedPermissions = $granted;
    }

    /**
     * 도구를 상황에 따라 뺀 사실을 모델에게 알린다. 없으면 null.
     *
     * 이게 없으면 모델이 "지워드릴게요" 해놓고 도구가 없어 아무것도 못 한다.
     */
    public function contextNote(): ?string
    {
        if ($this->serverCount() === 0) {
            return 'This user has **no servers at all**, so the server tools were not given to you — '
                . 'focus on helping them create one, and do not talk about touching an existing server.';
        }

        // 친구(서브유저)는 받은 권한만큼만 도구를 갖는다 — 없는 기능을 약속하지 않게 알린다.
        $missing = array_diff(
            array_map(fn ($p) => $p->value, array_unique(array_values(self::TOOL_PERMISSIONS), SORT_REGULAR)),
            $this->grantedPermissions(),
        );

        if ($missing !== []) {
            return 'This user is **a guest invited to the server, not its owner**, so they hold only some permissions. '
                . 'Offer only what the given tools can do; for anything they lack permission for, tell them to '
                . 'ask the server owner for it. (Missing: ' . implode(', ', $missing) . ')';
        }

        if (!$this->hasModServer()) {
            return 'None of this user\'s servers can take mods or plugins, so those tools were not given to you. '
                . 'Explain that using mods means creating a new server on an egg like Paper, Fabric or Forge.';
        }

        return null;
    }

    private function serverCount(): ?int
    {
        return $this->serverCount ??= $this->user->accessibleServers()->count();
    }

    /** 모드·플러그인을 설치할 수 있는 서버가 하나라도 있는가. */
    private function hasModServer(): bool
    {
        return $this->hasModServer ??= $this->user->accessibleServers()->get()
            ->contains(fn (Server $server) => ModInstaller::providerFor($server) !== null);
    }

    /** @return array<int, array<string, mixed>> */
    private function allDefinitions(): array
    {
        $serverProperty = [
            'server' => [
                'type' => 'string',
                'description' => 'Server identifier: the id from list_my_servers, or the server name.',
            ],
        ];

        return [
            [
                'name' => 'list_my_servers',
                'description' => 'Servers this user can reach, with game, connection address and assigned resources. '
                    . 'Call this before working on a specific server, or to check how many they have.',
                'inputSchema' => ['type' => 'object', 'properties' => (object) [], 'required' => []],
            ],
            [
                'name' => 'get_server_status',
                'description' => 'Live state: power, CPU, memory, disk, ports. Call this first for "it will not start" or "it is slow".',
                'inputSchema' => ['type' => 'object', 'properties' => $serverProperty, 'required' => ['server']],
            ],
            [
                'name' => 'read_server_console',
                'description' => 'Recent console output. **Look here first** when a server will not start or dies instantly: '
                    . 'if it dies before the app boots there is no log file, but the launch command and errors are here.',
                'inputSchema' => ['type' => 'object', 'properties' => $serverProperty, 'required' => ['server']],
            ],
            [
                'name' => 'list_server_files',
                'description' => 'List files in a folder. Use it before read_server_file when you do not know the exact path.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => $serverProperty + [
                        'path' => ['type' => 'string', 'description' => 'Folder path. Server root is "/". Example: "/logs"'],
                    ],
                    'required' => ['server', 'path'],
                ],
            ],
            [
                'name' => 'read_server_file',
                'description' => 'Read a file: logs (/logs/latest.log), config files (server.properties, eula.txt). '
                    . 'Large files come back tail-first.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => $serverProperty + [
                        'path' => ['type' => 'string', 'description' => 'File path. Example: "/logs/latest.log"'],
                    ],
                    'required' => ['server', 'path'],
                ],
            ],
            [
                'name' => 'get_install_logs',
                'description' => 'Log of the install script that ran when the server was created. Use it when a new server '
                    . 'will not start or looks empty, to see whether the install failed.',
                'inputSchema' => ['type' => 'object', 'properties' => $serverProperty, 'required' => ['server']],
            ],

            // ── 여기부터는 상태를 바꾼다. 실행 전 사용자 확인 카드를 거친다. ──
            [
                'name' => 'start_server',
                'description' => 'Start a stopped server. A confirmation card runs first, so call it directly — do not ask twice.',
                'inputSchema' => ['type' => 'object', 'properties' => $serverProperty, 'required' => ['server']],
            ],
            [
                'name' => 'stop_server',
                'description' => 'Stop a running server. Anyone connected is dropped. Confirmation card runs first — do not ask twice.',
                'inputSchema' => ['type' => 'object', 'properties' => $serverProperty, 'required' => ['server']],
            ],
            [
                'name' => 'restart_server',
                'description' => 'Restart a server: to apply config changes, or when it seems stuck. '
                    . 'Confirmation card runs first — do not ask twice.',
                'inputSchema' => ['type' => 'object', 'properties' => $serverProperty, 'required' => ['server']],
            ],
            [
                'name' => 'list_available_games',
                'description' => 'Games that can be created, each with its sizes (player counts) and the questions to ask. '
                    . 'Call this **first** when someone wants a server; create_server needs its ids.',
                'inputSchema' => ['type' => 'object', 'properties' => (object) [], 'required' => []],
            ],
            [
                'name' => 'create_server',
                'description' => 'Create a game server using ids from list_available_games. Resources, ports and technical '
                    . 'settings are decided for you — **never ask the user about memory or ports.** Ask only what '
                    . 'that game requires in its questions list and pass it in answers. Confirmation card runs first.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'game' => ['type' => 'string', 'description' => 'game id from list_available_games'],
                        'size' => ['type' => 'string', 'description' => 'size id, chosen by player count'],
                        'name' => ['type' => 'string', 'description' => 'Server name — pass it **only if the user chose one**. Omitted: a default like "Paper 26.2" is generated and the user can edit it on the card.'],
                        'answers' => [
                            'type' => 'object',
                            'description' => 'Answers keyed by the env name from questions. Optional ones may be omitted.',
                            'additionalProperties' => ['type' => 'string'],
                        ],
                    ],
                    'required' => ['game', 'size'],
                ],
            ],
            [
                'name' => 'accept_minecraft_eula',
                'description' => 'Accept the Minecraft EULA by setting eula=true in eula.txt. Use it when Minecraft exits '
                    . 'quietly on start and the log mentions the EULA. Accepting is the owner decision, so a card appears.',
                'inputSchema' => ['type' => 'object', 'properties' => $serverProperty, 'required' => ['server']],
            ],
            [
                'name' => 'replace_in_server_file',
                'description' => 'Find and replace inside a text config file (small edits like a server.properties value). '
                    . '**Read the real contents with read_server_file first and copy them exactly.** find must match '
                    . 'in exactly one place — widen it with surrounding text if not. The card shows the change.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => $serverProperty + [
                        'path' => ['type' => 'string', 'description' => 'File path. Example: "/server.properties"'],
                        'find' => ['type' => 'string', 'description' => 'Text to replace, exactly as it appears. Must match in one place only.'],
                        'replace' => ['type' => 'string', 'description' => 'Replacement text. Empty string deletes it.'],
                    ],
                    'required' => ['server', 'path', 'find', 'replace'],
                ],
            ],
            [
                'name' => 'list_schedules',
                'description' => 'Scheduled tasks: when they run, what they do, whether they are active.',
                'inputSchema' => ['type' => 'object', 'properties' => $serverProperty, 'required' => ['server']],
            ],
            [
                'name' => 'create_schedule',
                'description' => 'Create a scheduled task. Write times in **Korea time (KST)** — conversion is automatic.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        ...$serverProperty,
                        'name' => ['type' => 'string', 'description' => 'Schedule name'],
                        'action' => [
                            'type' => 'string',
                            'enum' => ['restart', 'stop', 'start', 'backup', 'command'],
                            'description' => 'What it does',
                        ],
                        'payload' => ['type' => 'string', 'description' => 'Command to send when action is command'],
                        'minute' => ['type' => 'string', 'description' => 'minute 0-59 (default 0)'],
                        'hour' => ['type' => 'string', 'description' => 'hour 0-23, Korea time'],
                        'day_of_week' => ['type' => 'string', 'description' => 'day of week 0=Sun..6=Sat, * for daily'],
                        'day_of_month' => ['type' => 'string', 'description' => 'day of month 1-31, * for daily'],
                        'only_when_online' => ['type' => 'boolean', 'description' => 'run only while online (default true)'],
                    ],
                    'required' => ['server', 'name', 'action', 'hour'],
                ],
            ],
            [
                'name' => 'toggle_schedule',
                'description' => 'Enable or disable a schedule without deleting it.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        ...$serverProperty,
                        'schedule' => ['type' => 'string', 'description' => 'name or id'],
                        'active' => ['type' => 'boolean', 'description' => 'true to enable, false to pause'],
                    ],
                    'required' => ['server', 'schedule', 'active'],
                ],
            ],
            [
                'name' => 'delete_schedule',
                'description' => 'Delete a schedule.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        ...$serverProperty,
                        'schedule' => ['type' => 'string', 'description' => 'name or id'],
                    ],
                    'required' => ['server', 'schedule'],
                ],
            ],
            [
                'name' => 'list_server_variables',
                'description' => 'Startup variables (version and so on). Secrets are masked.',
                'inputSchema' => ['type' => 'object', 'properties' => $serverProperty, 'required' => ['server']],
            ],
            [
                'name' => 'update_server_variable',
                'description' => 'Change a startup variable (version and so on). '
                    . '🔴 A version only applies **after a reinstall** — changing the value alone does nothing. Offer a backup first.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        ...$serverProperty,
                        'variable' => ['type' => 'string', 'description' => 'env_variable name'],
                        'value' => ['type' => 'string', 'description' => 'New value'],
                    ],
                    'required' => ['server', 'variable', 'value'],
                ],
            ],
            [
                'name' => 'send_console_command',
                'description' => 'Send a command to a running server (whitelist, op, save and so on). '
                    . '⚠ Output arrives asynchronously — sending is not success. For power, use stop_server / restart_server.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        ...$serverProperty,
                        'command' => ['type' => 'string', 'description' => 'One command line (no leading / for Minecraft)'],
                    ],
                    'required' => ['server', 'command'],
                ],
            ],
            [
                'name' => 'list_installed_mods',
                'description' => 'Installed mods/plugins with versions and whether an update exists.',
                'inputSchema' => ['type' => 'object', 'properties' => $serverProperty, 'required' => ['server']],
            ],
            [
                'name' => 'uninstall_mod',
                'description' => 'Remove an installed mod/plugin.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        ...$serverProperty,
                        'mod' => ['type' => 'string', 'description' => 'name or id'],
                    ],
                    'required' => ['server', 'mod'],
                ],
            ],
            [
                'name' => 'update_mod',
                'description' => 'Update a mod/plugin to the latest version (Minecraft only).',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        ...$serverProperty,
                        'mod' => ['type' => 'string', 'description' => 'name or id'],
                    ],
                    'required' => ['server', 'mod'],
                ],
            ],
            [
                'name' => 'write_server_file',
                'description' => 'Create a text file or **overwrite it whole**. For partial edits use replace_in_server_file.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        ...$serverProperty,
                        'path' => ['type' => 'string', 'description' => 'Path relative to the server folder'],
                        'content' => ['type' => 'string', 'description' => 'Full contents'],
                    ],
                    'required' => ['server', 'path', 'content'],
                ],
            ],
            [
                'name' => 'create_server_directory',
                'description' => 'Create a folder.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        ...$serverProperty,
                        'path' => ['type' => 'string', 'description' => 'Folder path'],
                    ],
                    'required' => ['server', 'path'],
                ],
            ],
            [
                'name' => 'download_to_server',
                'description' => 'Download a file into the server from a known distributor over https (Modrinth, GitHub, '
                    . 'SpigotMC, CurseForge, PaperMC, Fabric, Forge, uMod). Other hosts are rejected — point at the files page instead.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        ...$serverProperty,
                        'url' => ['type' => 'string', 'description' => 'https URL'],
                        'directory' => ['type' => 'string', 'description' => 'Target folder (root if empty)'],
                    ],
                    'required' => ['server', 'url'],
                ],
            ],
            [
                'name' => 'delete_server_files',
                'description' => 'Delete files or folders (resetting a world and so on). 🔴 Cannot be undone — offer a backup first. '
                    . 'Executables and whole plugins/mods folders are rejected.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        ...$serverProperty,
                        'paths' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Paths to delete',
                        ],
                    ],
                    'required' => ['server', 'paths'],
                ],
            ],
            [
                'name' => 'rename_server_file',
                'description' => 'Rename or move a file or folder.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        ...$serverProperty,
                        'from' => ['type' => 'string', 'description' => 'Current path'],
                        'to' => ['type' => 'string', 'description' => 'New path'],
                    ],
                    'required' => ['server', 'from', 'to'],
                ],
            ],
            [
                'name' => 'list_backups',
                'description' => 'Backups: when, how large, and whether they can be restored.',
                'inputSchema' => ['type' => 'object', 'properties' => $serverProperty, 'required' => ['server']],
            ],
            [
                'name' => 'create_backup',
                'description' => 'Create a backup. Offer it before anything risky (deletion, version change). You are told when it finishes.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        ...$serverProperty,
                        'name' => ['type' => 'string', 'description' => 'Name (auto if empty)'],
                    ],
                    'required' => ['server'],
                ],
            ],
            [
                'name' => 'restore_backup',
                'description' => 'Roll back to a backup. **Overwrites the current files** — anything since is lost. '
                    . 'Check the point in time with list_backups first.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        ...$serverProperty,
                        'backup' => ['type' => 'string', 'description' => 'backup id or name'],
                    ],
                    'required' => ['server', 'backup'],
                ],
            ],
            [
                'name' => 'update_server_resources',
                'description' => 'Change a server\'s memory, disk or CPU within the owner\'s personal allowance. '
                    . 'Growing needs free allowance; shrinking frees it for other servers. '
                    . '⚠ Applies on the next restart. Disk cannot go below what is already used.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        ...$serverProperty,
                        'memory_mb' => ['type' => 'integer', 'description' => 'New memory limit in MiB (omit to keep)'],
                        'disk_mb' => ['type' => 'integer', 'description' => 'New disk limit in MiB (omit to keep)'],
                        'cpu_percent' => ['type' => 'integer', 'description' => 'New CPU limit in % (100 = one core, omit to keep)'],
                    ],
                    'required' => ['server'],
                ],
            ],
            [
                'name' => 'add_server_port',
                'description' => 'Add one port (web map, RCON and so on). The number is chosen automatically. '
                    . '⚠ **It only opens externally after a restart.**',
                'inputSchema' => ['type' => 'object', 'properties' => $serverProperty, 'required' => ['server']],
            ],
            [
                'name' => 'remove_server_port',
                'description' => 'Remove one port. The primary port cannot be removed. Check what was using it first.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        ...$serverProperty,
                        'port' => ['type' => 'integer', 'description' => 'Port to remove (see ports in get_server_status)'],
                    ],
                    'required' => ['server', 'port'],
                ],
            ],
            [
                'name' => 'search_mods',
                'description' => 'Search mods/plugins installable on this server. Minecraft searches Modrinth and returns '
                    . '**only what matches the server version and loader**; Rust (oxide) searches uMod. '
                    . 'Call this first for "what mods are there?" or "recommend a map plugin". '
                    . 'The slug in the results is what install_mod takes.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        ...$serverProperty,
                        'search' => ['type' => 'string', 'description' => 'Search term (English works best). Empty returns the most popular.'],
                    ],
                    'required' => ['server'],
                ],
            ],
            [
                'name' => 'install_mod',
                'description' => 'Install a mod/plugin found by search. Version compatibility and file placement are handled. '
                    . 'Confirmation card runs first — do not ask twice. Pass the slug from search_mods.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        ...$serverProperty,
                        'mod' => ['type' => 'string', 'description' => 'slug from search_mods results'],
                    ],
                    'required' => ['server', 'mod'],
                ],
            ],
            [
                'name' => 'suggest_page',
                'description' => 'Put a button to a panel screen in the chat, for things **the user must do themselves**. '
                    . 'Nothing is executed. **Use this instead of describing a path in words.** '
                    . 'Do not use it for things you can do yourself — call that tool instead.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        ...$serverProperty,
                        'page' => [
                            'type' => 'string',
                            'enum' => ServerLinks::pageNames(),
                            'description' => 'console=console and logs, files=file manager, startup=startup options, '
                                . 'backups=backups, allocations=ports, settings=server settings',
                        ],
                    ],
                    'required' => ['server', 'page'],
                ],
            ],
        ];
    }

    /** 상태를 바꾸는 도구 → 실행 전 확인 카드를 요구한다. */
    private const CONFIRM_TOOLS = [
        'start_server', 'stop_server', 'restart_server',
        'accept_minecraft_eula', 'replace_in_server_file',
        'create_server', 'install_mod',
        'add_server_port', 'remove_server_port', 'update_server_resources',
        'create_backup', 'restore_backup',
        'send_console_command', 'update_server_variable',
        'create_schedule', 'toggle_schedule', 'delete_schedule',
        'uninstall_mod', 'update_mod',
        'write_server_file', 'create_server_directory', 'download_to_server',
        'delete_server_files', 'rename_server_file',
    ];

    /**
     * 서버당 포트 상한 (#27).
     *
     * UCS 한도에는 포트 개념이 없다 — 노드 풀이 100개뿐이라 상한 없이 열면 한 서버가
     * 풀을 잠식할 수 있다. 실측 최대 요구(ARK 4개) + 모드용 여유 1개.
     */
    private const MAX_PORTS_PER_SERVER = 5;

    /**
     * 게임 버전을 담는 변수들. 이 값이 바뀌면 **실행 이미지도 함께** 바꿔야 한다 —
     * 마인크래프트는 버전이 Java 를 결정한다(카탈로그 java_from, 개설과 같은 규칙).
     */
    private const VERSION_VARIABLES = ['MINECRAFT_VERSION', 'MC_VERSION', 'VANILLA_VERSION'];

    private const POWER_ACTIONS = [
        'start_server' => 'start',
        'stop_server' => 'stop',
        'restart_server' => 'restart',
    ];

    /**
     * 도구별로 요구하는 서브유저 권한.
     *
     * ⚠ `accessibleServers()` 는 **접근 가능 여부**만 본다. 서브유저는 서버에 접근할 수 있어도
     *   파일을 읽거나 전원을 만질 권한은 없을 수 있다. 이 표가 없으면 에이전트가 화면에서는
     *   막혀 있는 일을 대신 해주는 우회로가 된다.
     */
    private const TOOL_PERMISSIONS = [
        'read_server_console' => SubuserPermission::ControlConsole,
        'get_install_logs' => SubuserPermission::ControlConsole,
        'list_server_files' => SubuserPermission::FileRead,
        'read_server_file' => SubuserPermission::FileReadContent,
        'start_server' => SubuserPermission::ControlStart,
        'stop_server' => SubuserPermission::ControlStop,
        'restart_server' => SubuserPermission::ControlRestart,
        'accept_minecraft_eula' => SubuserPermission::FileUpdate,
        'replace_in_server_file' => SubuserPermission::FileUpdate,
        // 설치는 파일을 새로 만든다. 화면의 Modrinth 탭과 같은 수준의 제약이다.
        'install_mod' => SubuserPermission::FileCreate,
        'send_console_command' => SubuserPermission::ControlConsole,
        'list_schedules' => SubuserPermission::ScheduleRead,
        'create_schedule' => SubuserPermission::ScheduleCreate,
        'toggle_schedule' => SubuserPermission::ScheduleUpdate,
        'delete_schedule' => SubuserPermission::ScheduleDelete,
        'list_server_variables' => SubuserPermission::StartupRead,
        'update_server_variable' => SubuserPermission::StartupUpdate,
        'list_installed_mods' => SubuserPermission::FileRead,
        'uninstall_mod' => SubuserPermission::FileDelete,
        'update_mod' => SubuserPermission::FileCreate,
        'write_server_file' => SubuserPermission::FileCreate,
        'create_server_directory' => SubuserPermission::FileCreate,
        'download_to_server' => SubuserPermission::FileCreate,
        'delete_server_files' => SubuserPermission::FileDelete,
        'rename_server_file' => SubuserPermission::FileUpdate,
        'list_backups' => SubuserPermission::BackupRead,
        'create_backup' => SubuserPermission::BackupCreate,
        'restore_backup' => SubuserPermission::BackupRestore,
        'add_server_port' => SubuserPermission::AllocationCreate,
        'remove_server_port' => SubuserPermission::AllocationDelete,
    ];

    public function requiresConfirmation(string $name): bool
    {
        return in_array($name, self::CONFIRM_TOOLS, true);
    }

    /**
     * 확인 카드에 띄울 내용. **모델이 쓴 문장이 아니라 우리가 조회한 사실**을 보여준다 —
     * 모델이 "안전한 작업입니다" 같은 말로 사용자를 유도할 수 없어야 한다.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ServerNotFoundException|ToolPermissionException
     */
    public function card(string $name, array $input): array
    {
        return Tenancy::without(fn () => $this->cardInner($name, $input));
    }

    /** @param array<string, mixed> $input */
    private function cardInner(string $name, array $input): array
    {
        // 개설은 아직 서버가 없다 — 다른 도구와 달리 대상 서버를 해석할 게 없다.
        if ($name === 'create_server') {
            return $this->createServerCard($input);
        }

        $server = $this->serverFor($name, $input);

        $common = [
            'tool' => $name,
            'server_id' => $server->id,
            'title' => trans('concierge::strings.card_title_' . $name),
            'confirm' => trans('concierge::strings.card_confirm_' . $name),
        ];

        if (isset(self::POWER_ACTIONS[$name])) {
            return $common + [
                'lines' => [
                    ['label' => trans('concierge::strings.card_server'), 'value' => $server->name],
                    ['label' => trans('concierge::strings.card_game'), 'value' => $server->egg?->name ?? '-'],
                    ['label' => trans('concierge::strings.card_current_state'), 'value' => trans('concierge::strings.state_' . $this->powerState($server))],
                ],
                'note' => $name === 'start_server' ? null : trans('concierge::strings.card_note_disconnect'),
                'danger' => $name !== 'start_server',
            ];
        }

        if ($name === 'create_schedule') {
            [$parts, $action, $payload] = $this->schedulePlan($input);

            return $common + [
                'lines' => [
                    ['label' => trans('concierge::strings.card_server'), 'value' => $server->name],
                    ['label' => trans('concierge::strings.card_schedule_name'), 'value' => trim((string) ($input['name'] ?? ''))],
                    // 🔴 크론 문자열이 아니라 **사람이 읽는 문장**을 보여준다. 사용자가 확인하는
                    //    것은 표현식이 아니라 "언제 도는가"다.
                    ['label' => trans('concierge::strings.card_schedule_when'), 'value' => CronSchedule::describe(CronSchedule::validate([
                        'cron_minute' => $parts['cron_minute'], 'cron_hour' => (string) ($input['hour'] ?? '*'),
                        'cron_day_of_month' => $parts['cron_day_of_month'], 'cron_month' => '*',
                        'cron_day_of_week' => $parts['cron_day_of_week'],
                    ]))],
                    ['label' => trans('concierge::strings.card_schedule_action'), 'value' => trans("concierge::strings.schedule_action_{$action}") . ($payload !== '' ? " ({$payload})" : '')],
                ],
                'note' => trans('concierge::strings.card_note_schedule'),
                'danger' => false,
            ];
        }

        if ($name === 'toggle_schedule' || $name === 'delete_schedule') {
            $schedule = $this->scheduleFor($server, (string) ($input['schedule'] ?? ''));

            return $common + [
                'lines' => [
                    ['label' => trans('concierge::strings.card_server'), 'value' => $server->name],
                    ['label' => trans('concierge::strings.card_schedule_name'), 'value' => $schedule->name],
                    ['label' => trans('concierge::strings.card_schedule_when'), 'value' => CronSchedule::describeSchedule($schedule)],
                ],
                'danger' => $name === 'delete_schedule',
            ];
        }

        if ($name === 'update_server_variable') {
            [$variable, $value] = $this->variableChange($server, $input);
            $isVersion = in_array($variable->env_variable, self::VERSION_VARIABLES, true);

            return $common + [
                'lines' => [
                    ['label' => trans('concierge::strings.card_server'), 'value' => $server->name],
                    ['label' => trans('concierge::strings.card_variable'), 'value' => $variable->variable?->name ?? $variable->env_variable],
                    ['label' => trans('concierge::strings.card_variable_from'), 'value' => (string) $variable->server_value],
                    ['label' => trans('concierge::strings.card_variable_to'), 'value' => $value],
                ],
                // 🔴 버전 변경은 재설치가 뒤따라야 의미가 있다 — 카드에서 그 사실을 미리 말한다.
                'note' => trans($isVersion
                    ? 'concierge::strings.card_note_variable_version'
                    : 'concierge::strings.card_note_variable'),
                'danger' => $isVersion,
            ];
        }

        if ($name === 'send_console_command') {
            $command = $this->consoleCommand($server, (string) ($input['command'] ?? ''));

            return $common + [
                'lines' => [
                    ['label' => trans('concierge::strings.card_server'), 'value' => $server->name],
                    // 🔴 **명령 원문이 이 카드의 전부다.** 무엇이 실행되는지가 확인의 대상이다.
                    ['label' => trans('concierge::strings.card_command'), 'value' => $command],
                ],
                'note' => trans('concierge::strings.card_note_command'),
                'danger' => false,
            ];
        }

        if ($name === 'uninstall_mod' || $name === 'update_mod') {
            $plan = $name === 'uninstall_mod'
                ? (new ModInstaller())->planRemoval($server, (string) ($input['mod'] ?? ''))
                : (new ModInstaller())->plan($server, (string) ($input['mod'] ?? ''));

            return $common + [
                'lines' => [
                    ['label' => trans('concierge::strings.card_server'), 'value' => $server->name],
                    ['label' => trans('concierge::strings.card_mod'), 'value' => $plan['title']],
                    ['label' => trans('concierge::strings.card_mod_version'), 'value' => $plan['version']],
                    ['label' => trans('concierge::strings.card_file'), 'value' => $plan['filename']],
                ],
                'note' => $plan['provider'] === 'modrinth' ? trans('concierge::strings.card_note_mod_restart') : null,
                'danger' => $name === 'uninstall_mod',
            ];
        }

        if ($name === 'write_server_file') {
            // ⚠ 정규화를 **카드 전에** 한다 — 보여준 경로와 실제 대상이 같아야 한다.
            $path = FilePaths::assertWritable((string) ($input['path'] ?? ''));
            $exists = $this->fileExists($server, $path);

            return $common + [
                'lines' => [
                    ['label' => trans('concierge::strings.card_server'), 'value' => $server->name],
                    ['label' => trans('concierge::strings.card_file'), 'value' => $path],
                    ['label' => trans('concierge::strings.card_file_action'), 'value' => trans($exists
                        ? 'concierge::strings.card_file_overwrite'
                        : 'concierge::strings.card_file_new')],
                    ['label' => trans('concierge::strings.card_file_size'), 'value' => strlen((string) ($input['content'] ?? '')) . ' B'],
                ],
                'note' => $exists ? trans('concierge::strings.card_note_overwrite') : null,
                'danger' => $exists,
            ];
        }

        if ($name === 'create_server_directory') {
            return $common + [
                'lines' => [
                    ['label' => trans('concierge::strings.card_server'), 'value' => $server->name],
                    ['label' => trans('concierge::strings.card_folder'), 'value' => FilePaths::assertWritable((string) ($input['path'] ?? ''))],
                ],
                'danger' => false,
            ];
        }

        if ($name === 'download_to_server') {
            $url = FilePaths::assertDownloadable((string) ($input['url'] ?? ''));

            return $common + [
                'lines' => [
                    ['label' => trans('concierge::strings.card_server'), 'value' => $server->name],
                    // 전체 URL 을 보여준다 — 무엇을 받는지가 이 카드의 전부다.
                    ['label' => trans('concierge::strings.card_download_url'), 'value' => $url],
                    ['label' => trans('concierge::strings.card_folder'), 'value' => FilePaths::label((string) ($input['directory'] ?? ''))],
                ],
                'note' => trans('concierge::strings.card_note_download'),
                'danger' => false,
            ];
        }

        if ($name === 'delete_server_files') {
            $paths = $this->deletablePaths($input);

            return $common + [
                'lines' => [
                    ['label' => trans('concierge::strings.card_server'), 'value' => $server->name],
                    // 🔴 **대상과 개수를 확정해 보여준다.** 되돌릴 수 없는 작업의 카드다.
                    ['label' => trans('concierge::strings.card_delete_targets'), 'value' => implode(', ', $paths)],
                    ['label' => trans('concierge::strings.card_delete_count'), 'value' => (string) count($paths)],
                ],
                'note' => trans('concierge::strings.card_note_delete_files'),
                'danger' => true,
            ];
        }

        if ($name === 'rename_server_file') {
            return $common + [
                'lines' => [
                    ['label' => trans('concierge::strings.card_server'), 'value' => $server->name],
                    ['label' => trans('concierge::strings.card_rename_from'), 'value' => FilePaths::assertWritable((string) ($input['from'] ?? ''))],
                    ['label' => trans('concierge::strings.card_rename_to'), 'value' => FilePaths::assertWritable((string) ($input['to'] ?? ''))],
                ],
                'danger' => false,
            ];
        }

        if ($name === 'create_backup') {
            $this->assertBackupRoom($server);

            return $common + [
                'lines' => [
                    ['label' => trans('concierge::strings.card_server'), 'value' => $server->name],
                    ['label' => trans('concierge::strings.card_backup_name'), 'value' => trim((string) ($input['name'] ?? '')) ?: trans('concierge::strings.card_backup_auto')],
                    ['label' => trans('concierge::strings.card_backup_slots'), 'value' => $server->backups()->where('is_successful', true)->count() . ' / ' . $server->backup_limit],
                ],
                'note' => trans('concierge::strings.card_note_backup'),
                'danger' => false,
            ];
        }

        if ($name === 'restore_backup') {
            $backup = $this->backupFor($server, (string) ($input['backup'] ?? ''));

            return $common + [
                'lines' => [
                    ['label' => trans('concierge::strings.card_server'), 'value' => $server->name],
                    ['label' => trans('concierge::strings.card_backup_name'), 'value' => $backup->name],
                    // 🔴 **어느 시점으로 돌아가는지가 이 카드의 전부다.** 되돌린 뒤의 진행은 사라진다.
                    ['label' => trans('concierge::strings.card_backup_made_at'), 'value' => (string) $backup->created_at],
                    ['label' => trans('concierge::strings.card_power_state'), 'value' => trans($this->powerState($server) === 'running'
                        ? 'concierge::strings.state_running'
                        : 'concierge::strings.state_offline')],
                ],
                'note' => trans('concierge::strings.card_note_restore'),
                'danger' => true,
            ];
        }

        if ($name === 'update_server_resources') {
            $change = $this->resourcePlan($server, $input);

            $lines = [['label' => trans('concierge::strings.card_server'), 'value' => $server->name]];

            foreach ($change['diff'] as $field => [$from, $to]) {
                $lines[] = [
                    'label' => trans("concierge::strings.card_resource_{$field}"),
                    'value' => sprintf('%s → %s', $from, $to),
                ];
            }

            $lines[] = [
                'label' => trans('concierge::strings.card_allowance_after'),
                'value' => $change['allowance_after'],
            ];

            return $common + [
                'lines' => $lines,
                'note' => trans('concierge::strings.card_note_resources'),
                'danger' => $change['shrinks'],
            ];
        }

        if ($name === 'add_server_port') {
            $allocation = $this->planPortAddition($server);

            return $common + [
                'lines' => [
                    ['label' => trans('concierge::strings.card_server'), 'value' => $server->name],
                    ['label' => trans('concierge::strings.card_new_port'), 'value' => (string) $allocation->port],
                    ['label' => trans('concierge::strings.card_current_ports'), 'value' => $server->allocations->pluck('port')->implode(', ')],
                ],
                'note' => trans('concierge::strings.card_note_port_restart'),
                'danger' => false,
            ];
        }

        if ($name === 'remove_server_port') {
            $allocation = $this->removablePort($server, (int) ($input['port'] ?? 0));

            return $common + [
                'lines' => [
                    ['label' => trans('concierge::strings.card_server'), 'value' => $server->name],
                    ['label' => trans('concierge::strings.card_remove_port'), 'value' => (string) $allocation->port],
                ],
                'note' => trans('concierge::strings.card_note_port_remove'),
                'danger' => true,
            ];
        }

        // 모드 설치 — 카드에서 **무엇이, 어떤 버전으로** 설치되는지 확정해 보여준다.
        if ($name === 'install_mod') {
            $plan = (new ModInstaller())->plan($server, (string) ($input['mod'] ?? ''));

            return $common + [
                'lines' => [
                    ['label' => trans('concierge::strings.card_server'), 'value' => $server->name],
                    ['label' => trans('concierge::strings.card_mod'), 'value' => $plan['title']],
                    ['label' => trans('concierge::strings.card_mod_version'), 'value' => $plan['version']],
                    ['label' => trans('concierge::strings.card_file'), 'value' => $plan['filename']],
                ],
                'note' => $plan['provider'] === 'modrinth' ? trans('concierge::strings.card_note_mod_restart') : null,
                'danger' => false,
            ];
        }

        // 파일 수정 — 바뀔 내용을 **카드에서 눈으로 확인**할 수 있어야 한다.
        // 이게 확인 카드의 핵심이다. "파일을 고칠까요?"만 묻는 건 확인이 아니다.
        [$path, $find, $replace] = $this->editArguments($name, $input);
        $edit = $this->planEdit($server, $path, $find, $replace);

        return $common + [
            'lines' => [
                ['label' => trans('concierge::strings.card_server'), 'value' => $server->name],
                ['label' => trans('concierge::strings.card_file'), 'value' => $path],
            ],
            'diff' => ['before' => $edit['before'], 'after' => $edit['after']],
            'note' => null,
            'danger' => false,
        ];
    }

    /**
     * 도구별 수정 인자를 (경로, 찾을 내용, 바꿀 내용)으로 정규화한다.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: string, 1: string, 2: string}
     *
     * @throws ToolInputException
     */
    private function editArguments(string $name, array $input): array
    {
        if ($name === 'accept_minecraft_eula') {
            // 인자를 모델이 정하지 않는다 — 무엇을 어떻게 바꿀지 우리가 고정한다.
            return ['/eula.txt', 'eula=false', 'eula=true'];
        }

        $path = trim((string) ($input['path'] ?? ''));
        $find = (string) ($input['find'] ?? '');
        $replace = (string) ($input['replace'] ?? '');

        if ($path === '' || $find === '') {
            throw new ToolInputException('Both path and find are required.');
        }

        return [$path, $find, $replace];
    }

    /**
     * 수정 계획을 세운다. 실제로 쓰지는 않는다 — 카드와 실행이 **같은 함수**를 써야
     * 사용자가 본 것과 실행되는 것이 어긋나지 않는다.
     *
     * @return array{content: string, before: string, after: string}
     *
     * @throws ToolInputException
     */
    private function planEdit(Server $server, string $path, string $find, string $replace): array
    {
        /** @var DaemonFileRepository $repository */
        $repository = app(DaemonFileRepository::class);

        try {
            $content = $repository->setServer($server)->getContent($path, self::MAX_FILE_BYTES);
        } catch (Throwable) {
            // 파일이 없는 경우가 대부분이다(서버가 한 번도 제대로 뜨지 않았으면 설정 파일도 없다).
            // 데몬 오류 원문을 모델에 넘겨봐야 도움이 안 된다 — 다음 수를 알려준다.
            throw new ToolInputException(
                "Cannot read {$path}. The file does not exist yet or the path is wrong — check with list_server_files.",
            );
        }

        // 바이너리를 문자열 치환하면 파일이 조용히 망가진다.
        if (str_contains($content, "\0")) {
            throw new ToolInputException('Not a text file, so it cannot be edited.');
        }

        $occurrences = substr_count($content, $find);

        if ($occurrences === 0) {
            throw new ToolInputException("\"{$find}\" was not found in the file. Read the real contents with read_server_file first.");
        }

        // 여러 군데면 어디를 고칠지 확정할 수 없다. 사용자가 카드에서 본 것과 다른 곳이
        // 바뀌면 안 되므로, 모델에게 더 구체적으로 지정하라고 돌려준다.
        if ($occurrences > 1) {
            throw new ToolInputException("\"{$find}\" appears in {$occurrences} places, so the target is ambiguous. Widen it with surrounding text.");
        }

        $offset = strpos($content, $find);
        $lineStart = ($pos = strrpos(substr($content, 0, $offset), "\n")) === false ? 0 : $pos + 1;
        $lineEnd = ($pos = strpos($content, "\n", $offset + strlen($find))) === false ? strlen($content) : $pos;

        $before = substr($content, $lineStart, $lineEnd - $lineStart);

        return [
            'content' => substr_replace($content, $replace, $offset, strlen($find)),
            'before' => $before,
            'after' => str_replace($find, $replace, $before),
        ];
    }

    /** @param array<string, mixed> $input */
    public function run(string $name, array $input): ToolCallResult
    {
        return Tenancy::without(fn () => $this->runInner($name, $input));
    }

    /** @param array<string, mixed> $input */
    private function runInner(string $name, array $input): ToolCallResult
    {
        $this->lastServerId = null;

        try {
            return match ($name) {
                'list_my_servers' => $this->listMyServers($input),
                'get_server_status' => $this->getServerStatus($input),
                'list_server_files' => $this->listServerFiles($input),
                'read_server_file' => $this->readServerFile($input),
                'read_server_console' => $this->readServerConsole($input),
                'get_install_logs' => $this->getInstallLogs($input),
                'start_server', 'stop_server', 'restart_server' => $this->power($name, $input),
                'accept_minecraft_eula', 'replace_in_server_file' => $this->applyEdit($name, $input),
                'list_available_games' => $this->listAvailableGames($input),
                'create_server' => $this->createServer($input),
                'suggest_page' => $this->suggestPage($input),
                'search_mods' => $this->searchMods($input),
                'list_schedules' => $this->listSchedules($input),
                'create_schedule' => $this->createSchedule($input),
                'toggle_schedule' => $this->toggleSchedule($input),
                'delete_schedule' => $this->deleteSchedule($input),
                'list_server_variables' => $this->listServerVariables($input),
                'update_server_variable' => $this->updateServerVariable($input),
                'send_console_command' => $this->sendConsoleCommand($input),
                'list_installed_mods' => $this->listInstalledMods($input),
                'uninstall_mod' => $this->uninstallMod($input),
                'update_mod' => $this->updateMod($input),
                'write_server_file' => $this->writeServerFile($input),
                'create_server_directory' => $this->createServerDirectory($input),
                'download_to_server' => $this->downloadToServer($input),
                'delete_server_files' => $this->deleteServerFiles($input),
                'rename_server_file' => $this->renameServerFile($input),
                'list_backups' => $this->listBackups($input),
                'create_backup' => $this->createBackup($input),
                'restore_backup' => $this->restoreBackup($input),
                'update_server_resources' => $this->updateServerResources($input),
                'add_server_port' => $this->addServerPort($input),
                'remove_server_port' => $this->removeServerPort($input),
                'install_mod' => $this->installMod($input),
                default => ToolCallResult::error($name, $input, "Unknown tool: {$name}"),
            };
        } catch (ToolException $exception) {
            return ToolCallResult::error($name, $input, $exception->getMessage(), $this->lastServerId);
        } catch (Throwable $exception) {
            // 데몬이 꺼져 있거나 파일이 없는 경우가 대부분이다. 모델이 다음 수를 두게 사실만 알려준다.
            report($exception);

            return ToolCallResult::error(
                $name,
                $input,
                'Tool execution failed: ' . $exception->getMessage(),
                $this->lastServerId,
            );
        }
    }

    /**
     * 카드와 실행이 **같은 목록**을 보게 한다 — 정규화·차단 규칙을 두 번 적용하면 어긋난다.
     *
     * @param  array<string, mixed>  $input
     * @return array<int, string>
     *
     * @throws ToolInputException
     */
    private function deletablePaths(array $input): array
    {
        $raw = $input['paths'] ?? [];

        if (is_string($raw)) {
            $raw = [$raw];
        }

        $paths = [];

        foreach ((array) $raw as $path) {
            $paths[] = FilePaths::assertDeletable((string) $path);
        }

        if ($paths === []) {
            throw new ToolInputException('Specify what to delete.');
        }

        return array_values(array_unique($paths));
    }

    /** 파일이 이미 있는지. 카드가 "새로 만들기"인지 "덮어쓰기"인지 가른다. */
    private function fileExists(Server $server, string $path): bool
    {
        $directory = str_contains($path, '/') ? dirname($path) : '/';

        try {
            foreach (app(DaemonFileRepository::class)->setServer($server)->getDirectory($directory) as $entry) {
                if (($entry['name'] ?? null) === basename($path)) {
                    return true;
                }
            }
        } catch (Throwable) {
            // 폴더가 아직 없으면 그것도 "없음"이다.
        }

        return false;
    }

    /**
     * 예약의 크론·작업을 정리한다. 카드와 실행이 **같은 규칙**을 쓴다.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: array<string, string>, 1: string, 2: string}
     *
     * @throws ToolInputException
     */
    private function schedulePlan(array $input): array
    {
        $action = strtolower(trim((string) ($input['action'] ?? '')));
        $payload = trim((string) ($input['payload'] ?? ''));

        if (!in_array($action, ['restart', 'stop', 'start', 'backup', 'command'], true)) {
            throw new ToolInputException("'{$action}' cannot be scheduled. Choose one of restart, stop, start, backup, command.");
        }

        if ($action === 'command' && $payload === '') {
            throw new ToolInputException('Specify the command to send in payload.');
        }

        // 사용자가 말한 시각은 **한국 시간**이다 — 저장은 앱 타임존으로 옮긴다(CronSchedule).
        $parts = CronSchedule::toStorage([
            'cron_minute' => (string) ($input['minute'] ?? '0'),
            'cron_hour' => (string) ($input['hour'] ?? '*'),
            'cron_day_of_month' => (string) ($input['day_of_month'] ?? '*'),
            'cron_month' => '*',
            'cron_day_of_week' => (string) ($input['day_of_week'] ?? '*'),
        ]);

        return [$parts, $action, $payload];
    }

    /**
     * 이름이나 id 로 예약을 찾는다.
     *
     * @throws ToolInputException
     */
    private function scheduleFor(Server $server, string $needle): Schedule
    {
        $needle = trim($needle);

        $schedule = $server->schedules()
            ->where(fn ($query) => $query->where('name', $needle)->orWhere('id', is_numeric($needle) ? (int) $needle : 0))
            ->first();

        if ($schedule === null) {
            throw new ToolInputException("There is no schedule called '{$needle}'. Check list_schedules first.");
        }

        return $schedule;
    }

    /** @param array<string, mixed> $input */
    private function listSchedules(array $input): ToolCallResult
    {
        $server = $this->serverFor('list_schedules', $input);

        $schedules = $server->schedules()->with('tasks')->get()->map(fn (Schedule $s) => [
            'id' => $s->id,
            'name' => $s->name,
            // 크론 문자열은 모델에게도 사람 말로 준다 — 그대로 사용자에게 옮겨 말하게 된다.
            'when' => CronSchedule::describeSchedule($s),
            'active' => (bool) $s->is_active,
            'only_when_online' => (bool) $s->only_when_online,
            'next_run_at' => (string) $s->next_run_at,
            'tasks' => $s->tasks->map(fn (Task $t) => trim($t->action . ' ' . $t->payload))->all(),
        ])->all();

        return new ToolCallResult('list_schedules', $input, $this->json([
            'server' => $server->name,
            'schedules' => $schedules,
        ]), $server->id);
    }

    /** @param array<string, mixed> $input */
    private function createSchedule(array $input): ToolCallResult
    {
        $server = $this->serverFor('create_schedule', $input);
        [$parts, $action, $payload] = $this->schedulePlan($input);

        $schedule = Schedule::create([
            'server_id' => $server->id,
            'name' => trim((string) ($input['name'] ?? '')) ?: 'Scheduled task',
            'is_active' => true,
            // 꺼진 서버에 명령을 보내도 사라진다 — 기본값은 "켜져 있을 때만"이 안전하다.
            'only_when_online' => (bool) ($input['only_when_online'] ?? true),
            // 🔴 **next_run_at 을 채우지 않으면 영영 안 돈다.** 스케줄러는 이 값으로 실행
            //    대상을 고른다(ProcessRunnableCommand: where next_run_at <= now). 화면도
            //    저장할 때 직접 계산해 넣는다 — 모델 이벤트가 대신 해 주지 않는다.
            'next_run_at' => Utilities::getScheduleNextRunDate(
                $parts['cron_minute'], $parts['cron_hour'], $parts['cron_day_of_month'],
                $parts['cron_month'], $parts['cron_day_of_week'],
            ),
            ...$parts,
        ]);

        Task::create([
            'schedule_id' => $schedule->id,
            'sequence_id' => 1,
            // 패널의 작업 종류: power / command / backup (Extensions\Tasks\Schemas).
            'action' => in_array($action, ['restart', 'stop', 'start'], true) ? 'power' : $action,
            'payload' => in_array($action, ['restart', 'stop', 'start'], true) ? $action : $payload,
            'time_offset' => 0,
            'continue_on_failure' => false,
        ]);

        return new ToolCallResult(
            'create_schedule',
            $input,
            sprintf(
                "Created the schedule '%s' — %s. Next run: %s.",
                $schedule->name,
                CronSchedule::describeSchedule($schedule),
                $schedule->next_run_at ?? 'soon',
            ),
            $server->id,
        );
    }

    /** @param array<string, mixed> $input */
    private function toggleSchedule(array $input): ToolCallResult
    {
        $server = $this->serverFor('toggle_schedule', $input);
        $schedule = $this->scheduleFor($server, (string) ($input['schedule'] ?? ''));
        $active = (bool) ($input['active'] ?? true);

        $schedule->update([
            'is_active' => $active,
            // 멈춰 있는 동안 다음 실행 시각이 과거가 됐을 수 있다 — 다시 켤 때 새로 잡는다.
            'next_run_at' => $active ? Utilities::getScheduleNextRunDate(
                $schedule->cron_minute, $schedule->cron_hour, $schedule->cron_day_of_month,
                $schedule->cron_month, $schedule->cron_day_of_week,
            ) : $schedule->next_run_at,
        ]);

        return new ToolCallResult(
            'toggle_schedule',
            $input,
            sprintf("Schedule '%s' is now %s.", $schedule->name, $active ? 'active again' : 'paused'),
            $server->id,
        );
    }

    /** @param array<string, mixed> $input */
    private function deleteSchedule(array $input): ToolCallResult
    {
        $server = $this->serverFor('delete_schedule', $input);
        $schedule = $this->scheduleFor($server, (string) ($input['schedule'] ?? ''));
        $name = $schedule->name;

        $schedule->delete();

        return new ToolCallResult('delete_schedule', $input, "Deleted the schedule '{$name}'.", $server->id);
    }

    /**
     * 바꿀 변수와 값을 검증한다. 카드와 실행이 **같은 규칙**을 쓴다.
     *
     * @param  array<string, mixed>  $input
     * ⚠ 돌려주는 것은 `ServerVariable` 이 **아니라 `EggVariable`** 이다.
     *   `$server->variables` 는 egg 변수 목록에 server_variables 를 조인해 `server_value`
     *   별칭을 붙인 것이다 — 여기에 대고 update() 하면 **egg 정의(모든 서버 공용)** 를
     *   고치려 든다. 값은 반드시 server_variables 쪽에 써야 한다.
     *
     * @return array{0: \App\Models\EggVariable, 1: string}
     *
     * @throws ToolInputException
     */
    private function variableChange(Server $server, array $input): array
    {
        $name = trim((string) ($input['variable'] ?? ''));
        $value = trim((string) ($input['value'] ?? ''));

        $variable = $server->variables->firstWhere('env_variable', $name);

        if ($variable === null) {
            throw new ToolInputException("There is no variable called '{$name}'. Check list_server_variables first.");
        }

        // ⚠ 화면에서 못 고치는 변수는 에이전트로도 못 고쳐야 한다 — 아니면 여기가 우회로가 된다.
        if (!$variable->user_editable) {
            throw new ToolInputException("'{$name}' cannot be changed by users — an admin has to do it.");
        }

        if ($value === '') {
            throw new ToolInputException('Specify the new value.');
        }

        return [$variable, $value];
    }

    /** @param array<string, mixed> $input */
    private function listServerVariables(array $input): ToolCallResult
    {
        $server = $this->serverFor('list_server_variables', $input);
        $masker = SecretMasker::forServer($server);

        $variables = $server->variables
            // 사용자에게 안 보이는 변수는 모델에게도 주지 않는다.
            ->filter(fn ($v) => (bool) $v->user_viewable)
            ->map(fn ($v) => [
                'env_variable' => $v->env_variable,
                'name' => $v->variable?->name ?? $v->env_variable,
                'value' => $masker->mask((string) $v->server_value),
                'editable' => (bool) $v->user_editable,
            ])->values()->all();

        return new ToolCallResult('list_server_variables', $input, $this->json([
            'server' => $server->name,
            'variables' => $variables,
            'notice' => 'Changing a version variable only takes effect after a reinstall.',
        ]), $server->id);
    }

    /** @param array<string, mixed> $input */
    private function updateServerVariable(array $input): ToolCallResult
    {
        $server = $this->serverFor('update_server_variable', $input);
        [$variable, $value] = $this->variableChange($server, $input);

        // 비밀 변수면(#11) 값을 스크럽 대상으로 수집하고, 기록에 남는 입력·출력도 가린다.
        $isSecret = SecretMasker::isSecretEnv($server, $variable->env_variable);

        if ($isSecret && $value !== '') {
            $this->secretValues[] = $value;
        }

        // 값은 **서버별 행**에 쓴다(위 주석). egg 정의는 건드리지 않는다.
        ServerVariable::query()->updateOrCreate(
            ['server_id' => $server->id, 'variable_id' => $variable->id],
            ['variable_value' => $value],
        );

        // 마인크래프트는 **버전이 Java 를 결정한다**(카탈로그 java_from). 버전만 바꾸고 이미지를
        // 그대로 두면 새 버전이 옛 Java 로 돌아 기동에 실패한다 — 개설과 같은 규칙을 쓴다.
        $imageNote = '';

        if (in_array($variable->env_variable, self::VERSION_VARIABLES, true)) {
            $image = JavaRuntime::imageFor($value);

            if ($image !== null && $image !== $server->image) {
                $server->update(['image' => $image]);
                $imageNote = ' The runtime image (Java) was switched to match that version too.';
            }
        }

        if ($isSecret) {
            $input['value'] = SecretMasker::PLACEHOLDER;
        }

        return new ToolCallResult(
            'update_server_variable',
            $input,
            sprintf(
                "Set %s to '%s'.%s ⚠ **This is not applied yet** — only the value changed. A reinstall is what "
                . 'fetches the new files. Offer a backup first, and since you cannot reinstall yourself, '
                . 'open the settings screen with suggest_page.',

                $variable->env_variable,
                $isSecret ? SecretMasker::PLACEHOLDER : $value,
                $imageNote,
            ),
            $server->id,
        );
    }

    /**
     * 상태 응답에 넣을 접속자 필드 (#53). 위젯·유휴 판정과 같은 서비스다.
     *
     * @return array<string, mixed>
     */
    private function playerFields(Server $server, string $state): array
    {
        if ($state !== 'running') {
            return [];
        }

        // 폴백은 조용히 두지 않는다(#15) — 게임은 셀 수 있는데 플러그인이 없어서 못 세는
        // 경우, 모델이 그 사실을 알아야 "관리자가 설치하면 됩니다"를 안내할 수 있다.
        if (app(PlayerCount::class)->unavailableReason($server) === 'plugin') {
            return ['players_note' => 'This game supports player counting, but the Player Counter plugin is not installed or is disabled on this panel — counts are unavailable until an admin installs/enables it.'];
        }

        if (!app(PlayerCount::class)->supports($server)) {
            return [];
        }

        $details = app(PlayerCount::class)->details($server);

        if (!is_array($details)) {
            return [];
        }

        $fields = [
            'current_players' => (int) ($details['current_players'] ?? 0),
            'max_players' => (int) ($details['max_players'] ?? 0),
        ];

        $names = array_column($details['players'] ?? [], 'name');

        if ($names !== []) {
            $fields['player_names'] = array_slice($names, 0, 16);
        }

        return $fields;
    }

    /**
     * 보낼 명령을 다듬고 막을 것을 막는다.
     *
     * ⚠ 전원 명령은 **전용 도구가 따로 있다.** 콘솔로 stop 을 보내면 우리 감시(#7·#18)가
     *   모르는 채로 서버가 꺼진다 — 도구를 통해야 알림과 카드가 맞물린다.
     *
     * @throws ToolInputException
     */
    private function consoleCommand(Server $server, string $command): string
    {
        // 마인크래프트 콘솔은 앞의 / 를 받지 않는다. 모델이 붙여 오는 경우가 잦다.
        $command = ltrim(trim($command), '/');

        if ($command === '') {
            throw new ToolInputException('Specify the command to send.');
        }

        if (str_contains($command, "\n")) {
            throw new ToolInputException('Only one command line can be sent at a time.');
        }

        $first = strtolower(strtok($command, ' '));

        if (in_array($first, ['stop', 'restart', 'shutdown', 'quit', 'exit', 'end'], true)) {
            throw new ToolInputException(
                'Do not send power commands through the console — use stop_server / restart_server so the state can be watched and reported.',
            );
        }

        if ($this->powerState($server) !== 'running') {
            throw new ToolInputException('The server is off, so the command cannot be sent. Offer to start it first with start_server.');
        }

        return $command;
    }

    /** @param array<string, mixed> $input */
    private function sendConsoleCommand(array $input): ToolCallResult
    {
        $server = $this->serverFor('send_console_command', $input);
        $command = $this->consoleCommand($server, (string) ($input['command'] ?? ''));

        $server->send($command);

        return new ToolCallResult(
            'send_console_command',
            $input,
            "Sent '{$command}'. Output lands in the console a few seconds later — read_server_console then if it matters.",
            $server->id,
        );
    }

    /** @param array<string, mixed> $input */
    private function listInstalledMods(array $input): ToolCallResult
    {
        $server = $this->serverFor('list_installed_mods', $input);

        return new ToolCallResult('list_installed_mods', $input, $this->json((new ModInstaller())->listInstalled($server)), $server->id);
    }

    /** @param array<string, mixed> $input */
    private function uninstallMod(array $input): ToolCallResult
    {
        $server = $this->serverFor('uninstall_mod', $input);

        return new ToolCallResult('uninstall_mod', $input, (new ModInstaller())->remove($server, (string) ($input['mod'] ?? '')), $server->id);
    }

    /** @param array<string, mixed> $input */
    private function updateMod(array $input): ToolCallResult
    {
        $server = $this->serverFor('update_mod', $input);

        return new ToolCallResult('update_mod', $input, (new ModInstaller())->update($server, (string) ($input['mod'] ?? '')), $server->id);
    }

    /** @param array<string, mixed> $input */
    private function writeServerFile(array $input): ToolCallResult
    {
        $server = $this->serverFor('write_server_file', $input);
        $path = FilePaths::assertWritable((string) ($input['path'] ?? ''));

        app(DaemonFileRepository::class)->setServer($server)->putContent($path, (string) ($input['content'] ?? ''));

        return new ToolCallResult(
            'write_server_file',
            $input,
            "Saved {$path}. If it is a config file a restart is needed — call restart_server next.",
            $server->id,
        );
    }

    /** @param array<string, mixed> $input */
    private function createServerDirectory(array $input): ToolCallResult
    {
        $server = $this->serverFor('create_server_directory', $input);
        $path = FilePaths::assertWritable((string) ($input['path'] ?? ''));

        // 데몬 API 는 (이름, 부모 경로)로 받는다.
        app(DaemonFileRepository::class)->setServer($server)
            ->createDirectory(basename($path), str_contains($path, '/') ? dirname($path) : '/');

        return new ToolCallResult('create_server_directory', $input, "Created the folder {$path}.", $server->id);
    }

    /** @param array<string, mixed> $input */
    private function downloadToServer(array $input): ToolCallResult
    {
        $server = $this->serverFor('download_to_server', $input);
        $url = FilePaths::assertDownloadable((string) ($input['url'] ?? ''));
        $directory = FilePaths::normalize((string) ($input['directory'] ?? ''));

        app(DaemonFileRepository::class)->setServer($server)->pull($url, $directory ?: '/');

        return new ToolCallResult(
            'download_to_server',
            $input,
            sprintf(
                // ⚠ pull 은 비동기다 — 응답이 와도 파일은 몇 초 뒤에 생긴다(#16 실측).
                'Started the download into %s (it finishes in a few seconds). Plugins and mods need a restart to apply.',
                $directory ?: 'the server folder',
            ),
            $server->id,
        );
    }

    /** @param array<string, mixed> $input */
    private function deleteServerFiles(array $input): ToolCallResult
    {
        $server = $this->serverFor('delete_server_files', $input);
        $paths = $this->deletablePaths($input);

        try {
            app(DaemonFileRepository::class)->setServer($server)->deleteFiles('/', $paths);
        } catch (Throwable $exception) {
            throw new ToolInputException(
                'Could not delete — check the paths with list_server_files first: ' . implode(', ', $paths),
            );
        }

        return new ToolCallResult(
            'delete_server_files',
            $input,
            sprintf('Deleted %d item(s): %s', count($paths), implode(', ', $paths)),
            $server->id,
        );
    }

    /** @param array<string, mixed> $input */
    private function renameServerFile(array $input): ToolCallResult
    {
        $server = $this->serverFor('rename_server_file', $input);
        $from = FilePaths::assertWritable((string) ($input['from'] ?? ''));
        $to = FilePaths::assertWritable((string) ($input['to'] ?? ''));

        // ⚠ 데몬은 원본이 없어도 **성공을 돌려준다**(실측). 확인하지 않으면 아무 일도 없이
        //   "옮겼습니다"라고 말하게 된다.
        if (!$this->fileExists($server, $from)) {
            throw new ToolInputException("'{$from}' does not exist. Check the path with list_server_files first.");
        }

        try {
            app(DaemonFileRepository::class)->setServer($server)->renameFiles('/', [['from' => $from, 'to' => $to]]);
        } catch (Throwable $exception) {
            throw new ToolInputException("Could not move it — check that '{$from}' exists with list_server_files.");
        }

        return new ToolCallResult('rename_server_file', $input, "Moved {$from} to {$to}.", $server->id);
    }

    /**
     * 백업을 하나 더 만들 자리가 있는지 본다.
     *
     * ⚠ 한도가 차면 InitiateBackupService 가 예외를 던진다 — 카드를 띄우기 **전에** 막아야
     *   사용자가 승인 버튼을 눌렀다가 실패를 보지 않는다.
     *
     * @throws ToolInputException
     */
    private function assertBackupRoom(Server $server): void
    {
        $used = $server->backups()->where('is_successful', true)->count();

        if ($server->backup_limit <= 0) {
            throw new ToolInputException('This server is not allowed any backups. An admin has to grant a backup limit.');
        }

        if ($used >= $server->backup_limit) {
            throw new ToolInputException(sprintf(
                'The backup limit (%d) is full. An old one has to go before a new one can be made — point at the backups screen.',
                $server->backup_limit,
            ));
        }
    }

    /**
     * id 나 이름으로 백업을 찾는다. **되돌릴 수 있는 것만** 돌려준다.
     *
     * @throws ToolInputException
     */
    private function backupFor(Server $server, string $needle): Backup
    {
        $needle = trim($needle);

        $backup = $server->backups()
            ->where(fn ($query) => $query->where('uuid', $needle)->orWhere('name', $needle))
            ->latest('id')
            ->first();

        if ($backup === null) {
            throw new ToolInputException("There is no backup called '{$needle}'. Check list_backups first.");
        }

        if (!$backup->is_successful || $backup->completed_at === null) {
            throw new ToolInputException("'{$backup->name}' is still being made or failed, so it cannot be restored.");
        }

        return $backup;
    }

    /** @param array<string, mixed> $input */
    private function listBackups(array $input): ToolCallResult
    {
        $server = $this->serverFor('list_backups', $input);

        $backups = $server->backups()->latest('id')->limit(20)->get()->map(fn (Backup $b) => [
            'id' => $b->uuid,
            'name' => $b->name,
            'created_at' => (string) $b->created_at,
            'size_mb' => (int) round($b->bytes / 1024 / 1024),
            // 실패했거나 만들어지는 중인 백업은 되돌릴 수 없다 — 모델이 그걸 알아야 한다.
            'restorable' => $b->is_successful && $b->completed_at !== null,
            'locked' => (bool) $b->is_locked,
        ])->all();

        return new ToolCallResult('list_backups', $input, $this->json([
            'server' => $server->name,
            'limit' => $server->backup_limit,
            'used' => $server->backups()->where('is_successful', true)->count(),
            'backups' => $backups,
        ]), $server->id);
    }

    /** @param array<string, mixed> $input */
    private function createBackup(array $input): ToolCallResult
    {
        $server = $this->serverFor('create_backup', $input);
        $this->assertBackupRoom($server);

        $name = trim((string) ($input['name'] ?? '')) ?: null;

        try {
            $backup = app(InitiateBackupService::class)->handle($server, $name);
        } catch (TooManyRequestsHttpException $exception) {
            // 스로틀에 걸린 것이다. 원문을 그대로 넘기면 사용자에게 영어 예외가 보인다.
            throw new ToolInputException('A backup was just made, so another one cannot start yet. Try again shortly.');
        }

        // ⚠ 약속("끝나면 알려드립니다")을 지킬 수단을 여기서 남긴다. 감시가 없으면
        //   그런 말을 하게 두면 안 된다.
        ConciergeBackupWatch::create([
            'server_id' => $server->id,
            // 알림은 **시킨 사람에게** 간다(#48).
            'user_id' => $this->user->id,
            'backup_uuid' => $backup->uuid,
            'kind' => ConciergeBackupWatch::KIND_BACKUP,
        ]);

        return new ToolCallResult(
            'create_backup',
            $input,
            sprintf(
                "Started the backup '%s'. It takes minutes, and **you will be told when it finishes** — "
                . 'do not check completion now; just say you will report back.',

                $backup->name,
            ),
            $server->id,
        );
    }

    /** @param array<string, mixed> $input */
    private function restoreBackup(array $input): ToolCallResult
    {
        $server = $this->serverFor('restore_backup', $input);
        $backup = $this->backupFor($server, (string) ($input['backup'] ?? ''));

        // 설치·다른 복원이 도는 중이면 패널이 거절한다. 먼저 걸러 사람 말로 알려준다.
        if ($server->status !== null) {
            throw new ToolInputException('The server is busy with another job (install or restore), so it cannot be restored now.');
        }

        // ⚠ 상태를 먼저 찍어야 그 사이 다른 작업이 끼어들지 않는다. 다만 데몬 호출이 실패하면
        //   **되돌려야 한다** — 안 그러면 서버가 '복원 중'에 갇혀 켜지지도 않는다(관리자만 해제 가능).
        $server->update(['status' => ServerState::RestoringBackup]);

        try {
            app(DaemonBackupRepository::class)->setServer($server)->restore($backup, null, false);
        } catch (Throwable $exception) {
            $server->update(['status' => null]);

            throw new ToolInputException('Could not start the restore. Try again shortly.');
        }

        ConciergeBackupWatch::create([
            'server_id' => $server->id,
            // 알림은 **시킨 사람에게** 간다(#48).
            'user_id' => $this->user->id,
            'backup_uuid' => $backup->uuid,
            'kind' => ConciergeBackupWatch::KIND_RESTORE,
        ]);

        return new ToolCallResult(
            'restore_backup',
            $input,
            sprintf(
                "Started restoring to '%s' (%s). It is **asynchronous** and takes minutes; you will be told when "
                . 'it finishes. The server cannot be started until then.',

                $backup->name,
                $backup->created_at,
            ),
            $server->id,
        );
    }

    /**
     * 🔴 **한도를 함께 넘겨야 한다.** BuildModificationService 는 넘기지 않은
     *    database_limit·allocation_limit·backup_limit 를 **0 으로 덮어쓴다**
     *    (`Arr::get($data, 'backup_limit', 0)`). 포트만 바꾸려 했는데 백업 한도가 조용히
     *    0 이 되어 사용자가 백업을 못 만들게 됐다 — 실제로 그렇게 만들었다.
     *
     * @param array<string, mixed> $data
     */
    private function modifyBuild(Server $server, array $data): void
    {
        app(BuildModificationService::class)->handle($server, $data + [
            'database_limit' => $server->database_limit,
            'allocation_limit' => $server->allocation_limit,
            'backup_limit' => $server->backup_limit,
        ]);
    }

    /**
     * 자원 변경 계획 (#30, a안: 1인 한도 안 재배분 자유).
     *
     * 카드와 실행이 **같은 검증**을 쓴다. 규칙:
     *  - 🔴 **소유자만.** "1인 한도"의 1인은 소유자다 — 친구가 남의 한도를 재배분하면 안 된다.
     *    서브유저 권한 체계에도 자원 변경 항목이 없다(화면과 동수준)
     *  - 확대는 소유자의 남은 한도 안에서 (UCS UserResourceLimits — 개설과 같은 검사)
     *  - 축소는 카탈로그 게임별 **최소 크기(sizes 최솟값)** 아래로 못 내린다 — 좀보이드 8GB
     *    미만 OOM(실측) 같은 함정을 사용자가 밟지 않게
     *  - 메모리 0(무제한)은 금지 — 자바 서버 -Xmx0M 고장(실측)
     *  - 디스크는 지금 쓰는 용량 아래로 못 내린다
     *  - 노드 총량은 beta36 부터 패널이 집행한다(#2478 수정 확인) — 여기서 중복 검사하지 않는다
     *
     * @param  array<string, mixed>  $input
     * @return array{data: array<string, int>, diff: array<string, array{string, string}>, shrinks: bool, allowance_after: string}
     *
     * @throws ToolInputException
     */
    private function resourcePlan(Server $server, array $input): array
    {
        if ($server->owner_id !== $this->user->id) {
            throw new ToolInputException('Only the server owner can change its resources — they come out of the owner\'s personal allowance.');
        }

        $fields = [
            'memory' => ['input' => 'memory_mb', 'unit' => 'MB'],
            'disk' => ['input' => 'disk_mb', 'unit' => 'MB'],
            'cpu' => ['input' => 'cpu_percent', 'unit' => '%'],
        ];

        $data = [];
        $diff = [];
        $shrinks = false;

        foreach ($fields as $field => $meta) {
            $value = $input[$meta['input']] ?? null;

            if ($value === null || (int) $value === (int) $server->{$field}) {
                $data[$field] = (int) $server->{$field};

                continue;
            }

            $value = (int) $value;

            if ($value <= 0) {
                // 0 은 "무제한"인데 자바 서버는 -Xmx0M 이 되어 고장난다(실측). 음수는 논외.
                throw new ToolInputException("{$field} must be a positive number — 0 (unlimited) breaks Java servers.");
            }

            $data[$field] = $value;
            $diff[$field] = [$server->{$field} . $meta['unit'], $value . $meta['unit']];
            $shrinks = $shrinks || $value < $server->{$field};
        }

        if ($diff === []) {
            throw new ToolInputException('Nothing would change — pass at least one of memory_mb, disk_mb, cpu_percent with a different value.');
        }

        $this->assertAboveGameMinimum($server, $data);
        $this->assertDiskFits($server, $data['disk']);
        $allowanceLeft = $this->assertWithinOwnerAllowance($server, $data);

        return [
            'data' => $data,
            'diff' => $diff,
            'shrinks' => $shrinks,
            'allowance_after' => $allowanceLeft,
        ];
    }

    /**
     * 카탈로그 최소 크기 아래로 내리지 못하게 한다.
     *
     * @param  array<string, int>  $data
     *
     * @throws ToolInputException
     */
    private function assertAboveGameMinimum(Server $server, array $data): void
    {
        $game = $this->catalog->findByEggName($server->egg?->name ?? '');
        $sizes = $game['sizes'] ?? [];

        if ($sizes === []) {
            return;
        }

        $minMemory = min(array_column($sizes, 'memory'));

        if ($data['memory'] < $minMemory) {
            throw new ToolInputException(sprintf(
                'This game needs at least %dMB of memory (measured — below that it crashes or OOMs). Requested: %dMB.',
                $minMemory,
                $data['memory'],
            ));
        }
    }

    /**
     * 디스크를 이미 쓰는 용량 아래로 내리면 다음 부팅부터 쓰기가 실패한다.
     *
     * @throws ToolInputException
     */
    private function assertDiskFits(Server $server, int $diskMb): void
    {
        if ($diskMb >= (int) $server->disk) {
            return;
        }

        try {
            $usage = app(DaemonServerRepository::class)->setServer($server)->getDetails()['utilization']['disk_bytes'] ?? null;
        } catch (Throwable) {
            $usage = null;
        }

        // ⚠ wings 는 꺼진 서버의 디스크를 집계하지 않는다(실측 — 276MB 가 9B 로 보였다).
        //   켜져 있을 때의 값만 믿고, 못 읽으면 축소를 막는 쪽이 안전하다.
        if ($usage === null || $usage < 1024) {
            throw new ToolInputException(
                'Disk usage cannot be read reliably while the server is off — start it first, or keep the current disk size.',
            );
        }

        $usedMb = (int) ceil($usage / 1024 / 1024);

        if ($diskMb < $usedMb + 512) {
            throw new ToolInputException(sprintf(
                'The server already uses %dMB — the disk limit must stay above that (with ~512MB headroom).',
                $usedMb,
            ));
        }
    }

    /**
     * a안(#30): 소유자의 1인 한도 안이면 된다. 노드 총량은 패널이 집행한다.
     *
     * @param  array<string, int>  $data
     * @return string  변경 후 남는 한도 요약 (카드 표시용)
     *
     * @throws ToolInputException
     */
    private function assertWithinOwnerAllowance(Server $server, array $data): string
    {
        $class = 'Boy132\\UserCreatableServers\\Models\\UserResourceLimits';

        if (!\WisdomIT\Concierge\Support\OptionalPlugins::usable('user-creatable-servers')) {
            return '-';
        }

        $limits = $class::query()->where('user_id', $server->owner_id)->first();

        if ($limits === null) {
            throw new ToolInputException('The owner has no resource allowance configured — an admin has to grant one.');
        }

        // 이 서버를 뺀 나머지 서버들의 사용량 + 변경 후 이 서버 = 한도 안이어야 한다.
        $others = $server->user->servers()->where('id', '!=', $server->id);
        $short = [];

        foreach (['memory' => 'MB', 'disk' => 'MB', 'cpu' => '%'] as $field => $unit) {
            $would = (int) $others->clone()->sum($field) + $data[$field];

            if ($would > $limits->{$field}) {
                $short[] = sprintf('%s (%d%s over)', $field, $would - $limits->{$field}, $unit);
            }
        }

        if ($short !== []) {
            throw new ToolInputException(
                'That would exceed the owner\'s allowance: ' . implode(', ', $short)
                . '. Shrink another server or ask an admin for a bigger allowance.',
            );
        }

        $left = [];

        foreach (['memory' => 'MB', 'disk' => 'MB', 'cpu' => '%'] as $field => $unit) {
            $left[] = sprintf('%s %d%s', $field, $limits->{$field} - ((int) $others->clone()->sum($field) + $data[$field]), $unit);
        }

        return implode(' / ', $left);
    }

    /** @param array<string, mixed> $input */
    private function updateServerResources(array $input): ToolCallResult
    {
        $server = $this->serverFor('update_server_resources', $input);
        $change = $this->resourcePlan($server, $input);

        // 한도 필드를 함께 넘긴다 — 안 넘기면 0 으로 지워진다(#27 에서 실측한 함정).
        $this->modifyBuild($server, $change['data']);

        return new ToolCallResult(
            'update_server_resources',
            $input,
            sprintf(
                'Resources updated: %s. ⚠ **Not applied yet** — the container picks the new limits up on the '
                . 'next restart, so call restart_server next. Remaining allowance: %s.',
                implode(', ', array_map(fn ($f, $c) => "{$f} {$c[0]}→{$c[1]}", array_keys($change['diff']), $change['diff'])),
                $change['allowance_after'],
            ),
            $server->id,
        );
    }

    /**
     * 추가할 빈 할당을 고른다. 카드와 실행이 같은 규칙(풀에서 가장 낮은 빈 포트)을 쓴다.
     *
     * @throws ToolException
     */
    private function planPortAddition(Server $server): Allocation
    {
        if ($server->allocations()->count() >= self::MAX_PORTS_PER_SERVER) {
            throw new ToolInputException(sprintf(
                'This server already uses %d ports (the per-server cap is %d). An unused one has to be removed first.',
                $server->allocations()->count(),
                self::MAX_PORTS_PER_SERVER,
            ));
        }

        $allocation = PortPool::firstFree($server->node_id);

        if ($allocation === null) {
            throw new ToolInputException('No ports are left in the allowed range. This needs an admin.');
        }

        return $allocation;
    }

    /**
     * 제거 가능한 할당인지 검증한다. **대표 포트는 제거 불가** — 접속 주소가 사라진다.
     *
     * @throws ToolException
     */
    private function removablePort(Server $server, int $port): Allocation
    {
        $allocation = $server->allocations->firstWhere('port', $port);

        if ($allocation === null) {
            throw new ToolInputException("This server has no port {$port}. Check ports in get_server_status.");
        }

        if ($allocation->id === $server->allocation_id) {
            throw new ToolInputException('The primary port (the one players connect to) cannot be removed.');
        }

        return $allocation;
    }

    /** @param array<string, mixed> $input */
    private function addServerPort(array $input): ToolCallResult
    {
        $server = $this->serverFor('add_server_port', $input);
        $allocation = $this->planPortAddition($server);

        // BuildModificationService 가 wings 에 sync 까지 보낸다. 다만 **컨테이너 포트 바인딩은
        // stop→start 재시작 때 컨테이너가 재생성되며 반영된다** — sync 만으로는 안 열린다(실측).
        $this->modifyBuild($server, ['add_allocations' => [$allocation->id]]);

        return new ToolCallResult(
            'add_server_port',
            $input,
            sprintf(
                'Added port %d. ⚠ It is not reachable from outside yet — **it opens after a restart.** '
                . 'Offer the restart, and if the game or mod config needs this number, guide that too.',
                $allocation->port,
            ),
            $server->id,
        );
    }

    /** @param array<string, mixed> $input */
    private function removeServerPort(array $input): ToolCallResult
    {
        $server = $this->serverFor('remove_server_port', $input);
        $allocation = $this->removablePort($server, (int) ($input['port'] ?? 0));

        $this->modifyBuild($server, ['remove_allocations' => [$allocation->id]]);

        return new ToolCallResult(
            'remove_server_port',
            $input,
            sprintf('Removed port %d. It takes effect on the next restart.', $allocation->port),
            $server->id,
        );
    }

    /** @param array<string, mixed> $input */
    private function searchMods(array $input): ToolCallResult
    {
        $server = $this->serverFor('search_mods', $input);
        $found = (new ModInstaller())->search($server, (string) ($input['search'] ?? ''));

        return new ToolCallResult(
            'search_mods',
            $input,
            json_encode($found, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]',
            $server->id,
        );
    }

    /** @param array<string, mixed> $input */
    private function installMod(array $input): ToolCallResult
    {
        $server = $this->serverFor('install_mod', $input);
        $message = (new ModInstaller())->install($server, (string) ($input['mod'] ?? ''));

        return new ToolCallResult('install_mod', $input, $message, $server->id);
    }

    /**
     * 화면 버튼만 띄운다. **아무것도 실행하지 않는다.**
     *
     * 확인 카드가 필요 없는 이유는 상태를 바꾸지 않기 때문이다 — 사용자가 누를지 말지 정한다.
     * 서버 접근 권한은 그대로 확인한다: 남의 서버 링크를 띄우면 이름이 새어 나간다.
     *
     * @param array<string, mixed> $input
     */
    private function suggestPage(array $input): ToolCallResult
    {
        $server = $this->serverFor('suggest_page', $input);
        $page = (string) ($input['page'] ?? '');

        if (!in_array($page, ServerLinks::pageNames(), true)) {
            return ToolCallResult::error('suggest_page', $input, 'No such screen: ' . $page, $server->id);
        }

        return new ToolCallResult(
            'suggest_page',
            $input,
            "Showed the user a button to the {$page} screen for '{$server->name}'. "
                . 'Do not describe the path in words as well.',

            $server->id,
        );
    }

    /** @param array<string, mixed> $input */
    private function listMyServers(array $input): ToolCallResult
    {
        $servers = $this->user->accessibleServers()->with(['egg', 'allocation'])->get();

        $rows = $servers->map(fn (Server $server) => [
            'id' => $server->uuid_short,
            'name' => $server->name,
            'game' => $server->egg?->name,
            'address' => $server->allocation?->address,
            'memory_mb' => $server->memory,
            'disk_mb' => $server->disk,
            'cpu_percent' => $server->cpu,
            // 설치 중이면 데몬 상태를 물어봐야 소용없다 — 모델이 먼저 알아야 한다.
            'install_state' => $server->status?->value ?? 'installed',
        ])->all();

        return new ToolCallResult('list_my_servers', $input, $this->json([
            'count' => count($rows),
            'servers' => $rows,
        ]));
    }

    /** @param array<string, mixed> $input */
    private function getServerStatus(array $input): ToolCallResult
    {
        $server = $this->serverFor('get_server_status', $input);

        /** @var DaemonServerRepository $repository */
        $repository = app(DaemonServerRepository::class);
        $details = $repository->setServer($server)->getDetails();

        $utilization = $details['utilization'] ?? [];

        return new ToolCallResult('get_server_status', $input, $this->json([
            'id' => $server->uuid_short,
            'name' => $server->name,
            'power_state' => $details['state'] ?? 'unknown',
            'install_state' => $server->status?->value ?? 'installed',
            'address' => $server->allocation?->address,
            // 대표 포트 외 추가 포트까지. 포트 추가·제거(#27)의 근거 자료다.
            'ports' => $server->allocations->pluck('port')->values()->all(),
            // 접속자 수 (#53). 켜져 있고 쿼리 가능한 게임만 — 아니면 키 자체를 뺀다
            // (null 을 주면 모델이 "0명"과 혼동한다).
            ...$this->playerFields($server, $details['state'] ?? ''),
            // 배정된 한도. 실제 사용량(아래 *_used)과 다르다.
            //  ⚠ 0 은 "무제한"이다. 시작 명령이 이 값을 그대로 쓰는 egg 에서는 그게 곧 고장이다.
            'memory_limit_mb' => $server->memory,
            'disk_limit_mb' => $server->disk,
            'cpu_limit_percent' => $server->cpu,
            // 변수는 치환 전 형태다(치환된 형태에는 시크릿이 들어간다). 한도가 어디에 쓰이는지 보인다.
            'startup_command' => $server->startup,
            'cpu_percent_used' => $utilization['cpu_absolute'] ?? null,
            'memory_bytes_used' => $utilization['memory_bytes'] ?? null,
            'memory_bytes_limit' => $utilization['memory_limit_bytes'] ?? null,
            'disk_bytes_used' => $utilization['disk_bytes'] ?? null,
            'uptime_ms' => $utilization['uptime'] ?? null,
            // 설치가 실제로 끝났는지 용량으로 판정한 결과(#7). Pelican 의 install_state 는
            // 중간에 끊긴 설치도 installed 로 표시하므로 그것만 보면 안 된다.
            'install_check' => ConciergeInstallCheck::where('server_id', $server->id)->first()?->summary(),
        ]), $server->id);
    }

    /** @param array<string, mixed> $input */
    private function listServerFiles(array $input): ToolCallResult
    {
        $server = $this->serverFor('list_server_files', $input);
        $path = (string) ($input['path'] ?? '/');

        /** @var DaemonFileRepository $repository */
        $repository = app(DaemonFileRepository::class);
        $entries = $repository->setServer($server)->getDirectory($path);

        $rows = collect($entries)
            ->take(self::MAX_DIRECTORY_ENTRIES)
            ->map(fn (array $entry) => [
                'name' => $entry['name'] ?? null,
                'is_directory' => ($entry['mime'] ?? null) === 'inode/directory' || ($entry['directory'] ?? false),
                'size_bytes' => $entry['size'] ?? null,
                'modified_at' => $entry['modified_at'] ?? null,
            ])
            ->all();

        return new ToolCallResult('list_server_files', $input, $this->json([
            'path' => $path,
            'truncated' => count($entries) > self::MAX_DIRECTORY_ENTRIES,
            'entries' => $rows,
        ]), $server->id);
    }

    /** @param array<string, mixed> $input */
    private function readServerFile(array $input): ToolCallResult
    {
        $server = $this->serverFor('read_server_file', $input);
        $path = (string) ($input['path'] ?? '');

        if ($path === '') {
            return ToolCallResult::error('read_server_file', $input, 'path is required.', $server->id);
        }

        /** @var DaemonFileRepository $repository */
        $repository = app(DaemonFileRepository::class);
        $content = $repository->setServer($server)->getContent($path, self::MAX_FILE_BYTES);

        return new ToolCallResult(
            'read_server_file',
            $input,
            $this->tail($this->maskFor($server, $content)),
            $server->id,
        );
    }

    /** @param array<string, mixed> $input */
    private function readServerConsole(array $input): ToolCallResult
    {
        $server = $this->serverFor('read_server_console', $input);

        $lines = (new ConsoleLog())->setServer($server)->recentLines(self::CONSOLE_LINES);

        if ($lines === []) {
            return new ToolCallResult(
                'read_server_console',
                $input,
                'No console output. The server has never been started, or its container does not exist yet.',
                $server->id,
            );
        }

        // ⚠ 콘솔은 시작 명령을 그대로 출력한다 → 시크릿이 값으로 흘러나온다(#16).
        return new ToolCallResult(
            'read_server_console',
            $input,
            $this->tail($this->maskFor($server, implode("\n", $lines))),
            $server->id,
        );
    }

    /** @param array<string, mixed> $input */
    private function getInstallLogs(array $input): ToolCallResult
    {
        $server = $this->serverFor('get_install_logs', $input);

        /** @var DaemonServerRepository $repository */
        $repository = app(DaemonServerRepository::class);
        $logs = $repository->setServer($server)->getInstallLogs();

        return new ToolCallResult(
            'get_install_logs',
            $input,
            $this->tail($this->maskFor($server, $logs)),
            $server->id,
        );
    }

    /**
     * 카드에서 승인된 뒤에만 불린다.
     *
     * ⚠ 그래도 권한을 **여기서 다시** 검사한다. 카드를 띄운 시점과 누른 시점 사이에
     *   권한이 바뀌었을 수 있고, 확인 절차를 건너뛴 호출 경로가 생겨도 여기서 막힌다.
     *
     * @param array<string, mixed> $input
     */
    private function power(string $name, array $input): ToolCallResult
    {
        $server = $this->serverFor($name, $input);

        $action = self::POWER_ACTIONS[$name];
        $state = $this->powerState($server);

        // 이미 원하는 상태면 데몬을 찌르지 않는다 — 모델에게 사실만 알려주면 된다.
        if ($action === 'start' && $state === 'running') {
            return new ToolCallResult($name, $input, 'The server is already running.', $server->id);
        }

        if ($action === 'stop' && $state === 'offline') {
            return new ToolCallResult($name, $input, 'The server is already stopped.', $server->id);
        }

        /** @var DaemonServerRepository $repository */
        $repository = app(DaemonServerRepository::class);
        $repository->setServer($server)->power($action);

        return new ToolCallResult(
            $name,
            $input,
            // 전원 전환은 비동기다. "완료했습니다"라고 말하면 모델이 거짓을 옮긴다.
            "Sent '{$action}' to the server. It can take tens of seconds to take effect — "
                . 'check again with get_server_status later if it matters.',

            $server->id,
        );
    }

    /**
     * 카드에서 승인된 뒤에만 불린다.
     *
     * ⚠ 카드를 만들 때와 **같은 planEdit()** 을 다시 돌린다. 그 사이 파일이 바뀌었다면
     *   일치 개수가 달라져 여기서 막힌다 — 사용자가 본 것과 다른 수정이 나가지 않는다.
     *
     * @param array<string, mixed> $input
     */
    private function applyEdit(string $name, array $input): ToolCallResult
    {
        $server = $this->serverFor($name, $input);

        [$path, $find, $replace] = $this->editArguments($name, $input);
        $edit = $this->planEdit($server, $path, $find, $replace);

        /** @var DaemonFileRepository $repository */
        $repository = app(DaemonFileRepository::class);
        $repository->setServer($server)->putContent($path, $edit['content']);

        return new ToolCallResult(
            $name,
            $input,
            "Edited {$path}.\n- {$edit['before']}\n+ {$edit['after']}\n"
                . 'Config changes usually need a server restart to take effect.',

            $server->id,
        );
    }

    /** @param array<string, mixed> $input */
    private function listAvailableGames(array $input): ToolCallResult
    {
        // 개설이 막힌 사용자를 게임 고르기까지 데려갔다가 마지막에 거절하지 않는다(#17).
        if ($message = ServerProvisioner::creationGate($this->user)) {
            throw new ToolInputException($message);
        }

        $games = array_map(fn (array $g) => [
            'game' => $g['id'],
            'name' => $g['name'],
            'summary' => $g['summary'] ?? null,
            'sizes' => array_map(fn (array $s) => [
                'size' => $s['id'],
                'label' => $s['label'],
                'players' => $s['players'],
                'memory_mb' => $s['memory'],
                'disk_mb' => $s['disk'],
            ], $g['sizes'] ?? []),
            // 여기 없는 항목은 물어볼 필요가 없다. 나머지는 자동으로 채워진다.
            'questions' => array_map(fn (array $a) => array_filter([
                'env' => $a['env'],
                'label' => $a['label'],
                'type' => $a['type'] ?? 'text',
                'choices' => $a['choices'] ?? null,
                'default' => $a['default'] ?? null,
                'optional' => $a['optional'] ?? null,
                // 길이 제약을 모델에 알려야 실패 후 재시도가 아니라 처음부터 맞게 묻는다.
                'min_length' => $a['min'] ?? null,
                'max_length' => $a['max'] ?? null,
                // 질문에 딸린 안내문(#59 후속) — 모델이 물을 때 그대로 전한다.
                'note' => $a['note'] ?? null,
            ], fn ($v) => $v !== null), $g['ask'] ?? []),
            'notes' => $g['notes'] ?? null,
        ], $this->catalog->selfServiceGames());

        return new ToolCallResult('list_available_games', $input, $this->json([
            'count' => count($games),
            'games' => $games,
        ]));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function createServerCard(array $input): array
    {
        $plan = $this->provisioner()->plan(
            (string) ($input['game'] ?? ''),
            (string) ($input['size'] ?? ''),
            (string) ($input['name'] ?? ''),
            (array) ($input['answers'] ?? []),
        );

        $game = $plan['game'];
        $size = $plan['size'];

        // 값이 무엇인지 여기서 처음 확정된다 — 대화 스크럽(#11)의 수집 지점.
        $this->collectSecretAnswers($input, $game);

        $lines = [
            ['label' => trans('concierge::strings.card_game'), 'value' => $game['name']],
            ['label' => trans('concierge::strings.card_players'), 'value' => $size['label']],
            ['label' => trans('concierge::strings.card_resources'), 'value' => sprintf(
                '%s · %s',
                trans('concierge::strings.card_memory_value', ['gb' => round($size['memory'] / 1024, 1)]),
                trans('concierge::strings.card_disk_value', ['gb' => round($size['disk'] / 1024, 1)]),
            )],
        ];

        return [
            'tool' => 'create_server',
            'server_id' => null,
            'title' => trans('concierge::strings.card_title_create_server'),
            'lines' => $lines,
            // 이름은 줄이 아니라 **편집 필드**로 보여준다(#59) — 카드에서 바로 고칠 수 있다.
            'name_input' => ['label' => trans('concierge::strings.card_server_name'), 'value' => $plan['name']],
            // 설치가 오래 걸리는 게임은 진행이 안 보이면 실패로 오해한다(#7).
            'note' => $game['notes'][0] ?? trans('concierge::strings.card_note_install_time'),
            'confirm' => trans('concierge::strings.card_confirm_create_server'),
            'danger' => false,
        ];
    }

    /** @param array<string, mixed> $input */
    private function createServer(array $input): ToolCallResult
    {
        $provisioner = $this->provisioner();

        $plan = $provisioner->plan(
            (string) ($input['game'] ?? ''),
            (string) ($input['size'] ?? ''),
            (string) ($input['name'] ?? ''),
            (array) ($input['answers'] ?? []),
        );

        $server = $provisioner->create($plan);

        $game = $plan['game'];
        // 재개(카드 승인) 경로 — 카드를 만든 요청과 다른 요청이므로 여기서도 수집한다(#11).
        $this->collectSecretAnswers($input, $game);
        $ports = $plan['allocations']->pluck('port')->implode(', ');

        return new ToolCallResult(
            'create_server',
            // ⚠ 답변에는 비밀번호가 들어 있다. 로그 테이블로 그대로 흘러가면 안 된다.
            $this->maskAnswers($input, $game),
            $this->json(array_filter([
                'created' => true,
                'id' => $server->uuid_short,
                'name' => $server->name,
                'game' => $game['name'],
                'address' => $server->allocation?->address,
                'ports' => $ports,
                // 관리자가 UCS 없이 만들었다면 그 사실과 부작용을 답이 직접 말한다(#17).
                'without_ucs_note' => ServerProvisioner::noUcsCaveat(),
                'state' => 'installing',
                'notice' => 'The install has started. Downloading the game files takes several minutes, sometimes longer, '
                    . 'and the server starts by itself when done. Progress is visible via get_server_status.',
            ])),
            $server->id,
        );
    }

    /**
     * 카탈로그가 시크릿으로 선언한 답변을 가린다(#16). 모델은 이미 그 값을 알고 있지만,
     * **로그에 남기는 것은 별개 문제**다 — 관리자 화면에서 친구들의 비밀번호가 보이면 안 된다.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $game
     * @return array<string, mixed>
     */
    /**
     * 이 요청에서 값이 확인된 비밀들(#11). 서비스가 드레인해 ChatResult 로 올리고,
     * 사이드바가 저장 직전 마스킹과 대화 소급 스크럽에 쓴다.
     *
     * @var array<int, string>
     */
    private array $secretValues = [];

    /** @return array<int, string> */
    public function pullSecretValues(): array
    {
        $values = $this->secretValues;
        $this->secretValues = [];

        return $values;
    }

    /** @param array<string, mixed> $input  @param array<string, mixed> $game */
    private function collectSecretAnswers(array $input, array $game): void
    {
        foreach ($game['secrets'] ?? [] as $secret) {
            $value = (string) ($input['answers'][$secret] ?? '');

            if ($value !== '') {
                $this->secretValues[] = $value;
            }
        }
    }

    private function maskAnswers(array $input, array $game): array
    {
        foreach ($game['secrets'] ?? [] as $secret) {
            if (isset($input['answers'][$secret])) {
                $input['answers'][$secret] = SecretMasker::PLACEHOLDER;
            }
        }

        return $input;
    }

    private function provisioner(): ServerProvisioner
    {
        return new ServerProvisioner($this->user, $this->catalog);
    }

    /**
     * 서버를 찾고 **그 도구에 필요한 권한까지** 확인한다. 모든 도구가 이 문을 지난다.
     *
     * @param array<string, mixed> $input
     *
     * @throws ServerNotFoundException|ToolPermissionException
     */
    private function serverFor(string $name, array $input): Server
    {
        $server = $this->resolveServer($input);
        $this->assertPermission($name, $server);

        return $server;
    }

    /** @throws ToolPermissionException */
    private function assertPermission(string $name, Server $server): void
    {
        $permission = self::TOOL_PERMISSIONS[$name] ?? null;

        if ($permission && !$this->user->can($permission, $server)) {
            throw new ToolPermissionException(
                'You do not have permission for that action on this server. The user has to ask the server owner for it.',
            );
        }
    }

    private function powerState(Server $server): string
    {
        /** @var DaemonServerRepository $repository */
        $repository = app(DaemonServerRepository::class);

        return (string) ($repository->setServer($server)->getDetails()['state'] ?? 'unknown');
    }

    /**
     * ⚠ 여기가 권한 경계다. 모델이 준 문자열은 **힌트일 뿐**이고, 실제 서버는 언제나
     * 요청자의 접근 가능 목록 안에서만 찾는다. 남의 서버 id 를 지어내도 찾히지 않는다.
     *
     * @param array<string, mixed> $input
     *
     * @throws ServerNotFoundException
     */
    private function resolveServer(array $input): Server
    {
        $reference = trim((string) ($input['server'] ?? ''));

        if ($reference === '') {
            throw new ServerNotFoundException('server is required. Check list_my_servers first.');
        }

        $server = $this->user->accessibleServers()
            ->where(fn ($query) => $query
                ->where('uuid_short', $reference)
                ->orWhere('uuid', $reference)
                ->orWhereRaw('lower(name) = ?', [mb_strtolower($reference)]))
            ->with(['egg', 'allocation', 'node'])
            ->first();

        if (!$server) {
            throw new ServerNotFoundException(
                "No server matches \"{$reference}\". Get the exact id from list_my_servers.",
            );
        }

        $this->lastServerId = $server->id;

        return $server;
    }

    private function maskFor(Server $server, string $text): string
    {
        return SecretMasker::forServer($server)->mask($text);
    }

    /** 로그는 끝이 중요하다 — 넘치면 앞을 버린다. */
    private function tail(string $text): string
    {
        if (mb_strlen($text) <= self::MAX_TEXT) {
            return $text;
        }

        return "...(earlier output trimmed)\n" . mb_substr($text, -self::MAX_TEXT);
    }

    /** @param array<string, mixed> $data */
    private function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}';
    }
}
