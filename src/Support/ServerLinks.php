<?php

namespace WisdomIT\WisdomAiAssistant\Support;

use App\Filament\Server\Pages\Console;
use App\Filament\Server\Pages\Settings;
use App\Filament\Server\Pages\Startup;
use App\Filament\Server\Resources\Allocations\Pages\ListAllocations;
use App\Filament\Server\Resources\Backups\Pages\ListBackups;
use App\Filament\Server\Resources\Files\Pages\ListFiles;
use App\Models\Server;
use App\Models\User;
use Throwable;

/**
 * 대화 안에 띄우는 "이 화면으로 가기" 버튼.
 *
 * **왜 이제야 쓸모가 있나** — 예전에는 채팅이 전용 페이지라, 링크를 눌러 이동하면 대화가
 * 사라졌다. 사이드바가 상시로 떠 있고 대화가 유지되는 지금은 눌러도 맥락을 잃지 않는다.
 *
 * 두 경로로 만들어진다:
 *  1. **도구가 실제로 무언가를 했을 때** — `TOOL_PAGES` 에 따라 자동으로 붙는다
 *  2. **모델이 "가서 직접 하세요"라고 할 때** — `suggest_page` 도구로 모델이 직접 붙인다
 *
 * ⚠ **권한을 여기서 다시 확인한다.** 대화를 이어보면 옛 도구 이력에서 링크를 되살리는데,
 *   그 사이 서버가 지워졌거나 권한이 빠졌을 수 있다. `accessibleServers()` 안에서만 찾는다.
 */
final class ServerLinks
{
    /** 한 턴에 붙일 수 있는 버튼 수. 넘으면 대화가 버튼 밭이 된다. */
    private const MAX_PER_TURN = 3;

    /**
     * 화면 이름 → Filament 페이지. `suggest_page` 도구의 선택지이기도 하다.
     *
     * @var array<string, class-string>
     */
    private const PAGES = [
        'console' => Console::class,
        'files' => ListFiles::class,
        'startup' => Startup::class,
        'backups' => ListBackups::class,
        'allocations' => ListAllocations::class,
        'settings' => Settings::class,
        // 서버 삭제 버튼이 있는 화면. **UCS 플러그인 소유**라 문자열로 둔다 —
        // 플러그인이 빠지면 `make()` 의 class_exists 가 걸러낸다.
        'delete' => 'Boy132\\UserCreatableServers\\Filament\\Server\\Pages\\ServerResourcePage',
        // 모드 플러그인 화면들. 전부 남의 플러그인 소유 → 같은 이유로 문자열.
        //  ⚠ egg 태그·feature 조건이 안 맞으면 페이지 자체가 접근 거부한다(각 플러그인의 canAccess).
        //    조건은 wisdom-ai-assistant:egg-metadata 가 보정한다.
        'modrinth_plugins' => 'Boy132\\MinecraftModrinth\\Filament\\Server\\Pages\\MinecraftModrinthPluginPage',
        'modrinth_mods' => 'Boy132\\MinecraftModrinth\\Filament\\Server\\Pages\\MinecraftModrinthModPage',
        'umod' => 'Boy132\\RustUMod\\Filament\\Server\\Pages\\RustUModPluginsPage',
        'factorio_mods' => 'gOOvER\\FactorioModInstaller\\Filament\\Server\\Pages\\FactorioModInstaller',
    ];

    /**
     * 도구가 성공하면 자동으로 붙는 화면.
     *
     * 읽기 도구에는 붙이지 않는다 — 상태를 물을 때마다 버튼이 따라오면 잡음이다.
     * 무언가를 **바꾼** 뒤에만 "가서 확인하세요"가 의미가 있다.
     *
     * @var array<string, string>
     */
    private const TOOL_PAGES = [
        'create_server' => 'console',
        'start_server' => 'console',
        'stop_server' => 'console',
        'restart_server' => 'console',
        'add_server_port' => 'allocations',
        'remove_server_port' => 'allocations',
        'accept_minecraft_eula' => 'files',
        'replace_in_server_file' => 'files',
    ];

    /**
     * 도구 호출 목록에서 버튼을 만든다. 같은 화면이 여러 번 나오면 하나로 친다.
     *
     * @param  iterable<object{name?: string, tool_name?: string, serverId?: ?int, server_id?: ?int, isError?: bool, is_error?: bool}>  $calls
     * @return array<int, array{label: string, url: string}>
     */
    public static function forToolCalls(iterable $calls): array
    {
        $links = [];

        foreach ($calls as $call) {
            // 실행 결과 객체(ToolCallResult)와 저장된 행(WisdomAiAssistantToolCall)을 모두 받는다.
            $name = $call->name ?? $call->tool_name ?? '';
            $serverId = $call->serverId ?? $call->server_id ?? null;
            $failed = $call->isError ?? $call->is_error ?? false;

            // 실패한 도구에 "가서 보세요"를 붙이면 안 된다 — 아무 일도 일어나지 않았다.
            if ($failed || $serverId === null) {
                continue;
            }

            $page = $name === 'suggest_page'
                ? self::requestedPage($call)
                : (self::TOOL_PAGES[$name] ?? null);

            if ($page !== null && $link = self::make((int) $serverId, $page)) {
                $links[$link['url']] = $link;
            }
        }

        return array_slice(array_values($links), 0, self::MAX_PER_TURN);
    }

    /**
     * `suggest_page` 가 고른 화면.
     *
     * ⚠ 실행 결과 객체에서는 `input` 이 배열이지만, 저장된 행에서는 **JSON 문자열**이다
     *   (대화를 이어볼 때 이 경로로 들어온다).
     */
    private static function requestedPage(object $call): ?string
    {
        $input = $call->input ?? null;

        if (is_string($input)) {
            $input = json_decode($input, true);
        }

        return is_array($input) ? ($input['page'] ?? null) : null;
    }

    /**
     * 서버 하나에 대한 버튼 하나.
     *
     * @return ?array{label: string, url: string}
     */
    public static function make(int $serverId, string $page): ?array
    {
        $class = self::PAGES[$page] ?? null;

        // 플러그인이 빠졌거나 이름이 바뀌면 그 화면만 조용히 사라진다.
        if ($class === null || !class_exists($class)) {
            return null;
        }

        /** @var ?User $user */
        $user = auth()->user();

        /** @var ?Server $server */
        $server = $user?->accessibleServers()->find($serverId);

        if ($server === null) {
            return null;
        }

        try {
            return [
                'label' => trans("wisdom-ai-assistant::strings.link_{$page}", ['server' => $server->name]),
                'url' => $class::getUrl(panel: 'server', tenant: $server),
            ];
        } catch (Throwable) {
            // 패널 컨텍스트가 없거나 페이지가 사라진 경우. 버튼 하나 때문에 대화가 죽으면 안 된다.
            return null;
        }
    }

    /** `suggest_page` 도구가 받을 수 있는 화면 이름. */
    public static function pageNames(): array
    {
        return array_keys(self::PAGES);
    }
}
