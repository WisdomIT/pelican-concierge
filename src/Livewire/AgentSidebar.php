<?php

namespace WisdomIT\Concierge\Livewire;

use App\Enums\SubuserPermission;
use App\Models\Server;
use App\Repositories\Daemon\DaemonServerRepository;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;
use Illuminate\Support\Str;
use Throwable;
use App\Services\Servers\ReinstallServerService;
use WisdomIT\Concierge\Models\ConciergeBackupWatch;
use WisdomIT\Concierge\Models\ConciergeConversation;
use WisdomIT\Concierge\Models\ConciergeIdleWatch;
use WisdomIT\Concierge\Models\ConciergeInstallCheck;
use WisdomIT\Concierge\Models\ConciergeSettings;
use WisdomIT\Concierge\Models\ConciergeToolCall;
use WisdomIT\Concierge\Models\ConciergeUsage;
use WisdomIT\Concierge\Services\AnthropicChatService;
use WisdomIT\Concierge\Services\ChatResult;
use WisdomIT\Concierge\Support\Markdown;
use WisdomIT\Concierge\Support\ServerLinks;
use WisdomIT\Concierge\Support\Tenancy;

/**
 * 어느 화면에서든 떠 있는 에이전트 사이드바.
 *
 * 전용 페이지가 아니라 일반 Livewire 컴포넌트인 이유는 `PanelsRenderHook::BODY_END` 로
 * **모든 페이지에** 렌더되기 때문이다. Filament Page 는 패널 레이아웃에 묶여 있어 이 자리에
 * 들어갈 수 없다.
 *
 * ⚠ 화면을 옮겨도 살아남는 것은 blade 쪽 `@persist` 가 해 준다(Filament 는 SPA 모드다).
 *   다만 **서버 콘솔 페이지는 SPA 예외**라(`PanelProvider` 의 `->spa()`) 드나들 때 전체
 *   리로드가 나고 컴포넌트가 다시 마운트된다 — 그 구멍은 대화 이어보기가 메운다.
 */
class AgentSidebar extends Component
{
    /** 확인 카드를 띄운 뒤 사용자가 누를 때까지 재개 상태를 보관하는 시간. */
    private const PENDING_TTL_MINUTES = 30;

    /**
     * 재개 상태를 두는 캐시 스토어. **기본 스토어와 분리한다.**
     * `cache:clear` 는 기본 스토어만 비우므로, 여기 두면 배포에 휩쓸리지 않는다.
     * (스토어 정의는 `ConciergeProvider::boot()`)
     */
    public const PENDING_STORE = 'concierge';

    /** 실행하면 시간이 걸리는 도구 — 끝날 때까지 상태를 지켜본다. */
    private const WATCHED_TOOLS = ['start_server', 'restart_server', 'create_server'];

    /**
     * 감시 항목의 수명 상한(초).
     *
     * ⚠ 감시가 남아 있는 동안 폴링이 5초로 당겨진다. 항목이 영영 안 빠지면 **5초 폴링이
     *   영구화**된다 — 실제로 그렇게 됐다(#26): wings 에 없는 좀비 서버가 unknown 상태로
     *   끝까지 남았다. 어떤 경로로든 이 시간이 지나면 조용히 내린다.
     *   (가장 오래 걸리는 설치가 ARK ~10분 — 여유 있게 30분)
     */
    private const WATCH_TTL_SECONDS = 1800;

    /** 상태 조회가 이만큼 연속 실패하면 감시를 접는다 — 좀비·데몬 다운은 기다려도 안 온다. */
    private const WATCH_MAX_MISSES = 3;

    /** 사이드바에 띄울 대화 개수. 넘으면 오래된 것부터 목록에서만 사라진다(기록은 남는다). */
    private const CONVERSATION_LIST_LIMIT = 30;

    /**
     * 대화를 열 때 되살릴 턴 수.
     *
     * ⚠ **비용 상한이다.** `$messages` 는 화면 표시용이자 매 발화마다 모델에게 다시 보내는
     *   대화 맥락이다. 대화가 영구 보존되면서 맥락이 무한히 자랄 수 있게 됐다 — 예전에는
     *   새로고침이 대화를 끊어서 드러나지 않던 문제다. 오래된 턴은 화면에서도 함께 빠진다:
     *   **사용자가 보는 것과 모델이 받는 것을 다르게 두지 않는다.** 전체 기록은 관리자 화면에 남는다.
     */
    private const RESTORED_TURN_LIMIT = 20;

    /** @var array<int, array{role: string, text: string}> */
    public array $messages = [];

    public string $draft = '';

    /** 지금 열려 있는 대화의 id = `concierge_conversations.id`. */
    public string $conversationId = '';

    /**
     * 사이드바 목록. `[id, title, active]` 만 담는다 — 본문까지 프로퍼티에 넣으면
     * 모든 요청마다 전체 대화가 브라우저를 왕복한다.
     *
     * @var array<int, array{id: string, title: string}>
     */
    public array $conversations = [];

    /**
     * 사용자 확인을 기다리는 카드. 비어 있으면 대기 중인 것이 없다.
     *
     * ⚠ 재개에 필요한 **전체 상태는 여기 두지 않는다.** 도구 결과(로그 수천 자)가 통째로
     *   브라우저를 왕복하게 되고, 그 안에는 서버 파일 내용이 들어 있다. 상태는 서버 쪽
     *   캐시에 두고 여기에는 열쇠만 남긴다.
     *
     * @var array<string, mixed>
     */
    public array $pendingCard = [];

    public string $pendingToken = '';

    /** 카드의 편집 필드 값 (#59 — 지금은 개설 카드의 서버 이름 하나). */
    public string $cardName = '';

    /** 사이드바가 닫혀 있는 동안 에이전트가 말을 걸었다 → 런처에 점을 띄운다. */
    public bool $unread = false;

    /**
     * 지금 상태가 변하는 중인 서버들. 사이드바 하단에 실시간 카드로 뜬다.
     *
     * **왜 필요한가** — 켜기·개설은 즉시 끝나지 않는데, 화면에 아무 변화가 없으면 사용자는
     * "됐나?"를 물어볼 수밖에 없다. 물어보게 만드는 대신 진행을 보여주고, 끝나면 먼저 알린다.
     *
     * @var array<int, array{id: int, name: string, state: string}>
     */
    public array $watching = [];

    /**
     * 마지막으로 쓰던 대화를 열어준다. 없으면 새 대화로 시작한다.
     *
     * 상시 사이드바가 되면 "화면을 옮길 때마다 처음부터"는 쓸 수 없다 — 서버 콘솔 페이지는
     * SPA 예외라 실제로 전체 리로드가 난다(PanelProvider 의 `->spa()`).
     */
    public function mount(): void
    {
        $latest = ConciergeConversation::listFor((int) auth()->id())->first();

        $latest === null ? $this->startConversation() : $this->openConversation($latest->id);
    }

    /**
     * **에이전트가 먼저 말을 거는 자리.** 화면에서 주기적으로 부른다(`wire:poll`).
     *
     * ⚠ 사용자가 물어볼 때까지 기다리면 안 된다 — 설치는 몇 분 걸리고 그동안 사용자는 다른
     *   화면을 보고 있다. 실패를 알리는 시점이 "다음에 말을 걸었을 때"면 며칠 뒤일 수도 있다.
     *
     * ⚠ **한 번에 하나만 전한다.** 카드는 하나뿐이고, 여러 개를 쏟아내면 무엇에 답하는 건지
     *   알 수 없다. 나머지는 다음 폴링에서 간다.
     */
    public function checkNotices(): void
    {
        // 🔴 알림 경로도 **테넌시 밖**에서 돌아야 한다. 안 그러면 다른 서버 화면에서
        //    $server->allocation 이 null 이 되어 접속 주소가 '-' 로 나간다(실측, #27).
        Tenancy::without(fn () => $this->checkNoticesInner());
    }

    private function checkNoticesInner(): void
    {
        // 상태 카드는 카드 대기 여부와 무관하게 갱신한다 — 진행 상황은 계속 보여야 한다.
        $this->refreshWatching();

        // 이미 결정을 기다리는 카드가 있으면 새 알림으로 끼어들지 않는다.
        if ($this->pendingCard !== []) {
            $this->closeStaleCard();

            return;
        }

        $notice = ConciergeInstallCheck::undeliveredFor((int) auth()->id())->first();

        if ($notice === null) {
            // 한 번에 하나만 전한다 — 백업이 없으면 유휴로 넘어간다.
            if (!$this->deliverBackupNotice()) {
                $this->deliverIdleNotice();
            }

            return;
        }

        $server = $notice->server;

        // 성공은 알리기만 한다 — 결정할 것이 없으므로 카드도 없다.
        if ($notice->status === ConciergeInstallCheck::STATUS_OK) {
            $this->deliverNotice($server, trans('concierge::strings.install_ok_notice', [
                'server' => $server->name,
                'address' => $server->allocation?->address ?? '-',
                'state' => trans($this->isRunning($server)
                    ? 'concierge::strings.install_ok_running'
                    : 'concierge::strings.install_ok_offline'),
            ]), ServerLinks::forToolCalls([
                (object) ['name' => 'start_server', 'serverId' => $server->id, 'isError' => false],
            ]));

            // ⚠ **전한 뒤에 찍는다.** 먼저 찍으면 그 사이에 무엇이 실패했을 때 알림이 영영
            //   사라진다 — 중복보다 유실이 나쁘다.
            $notice->markNotified();

            return;
        }

        $this->deliverNotice($server, trans('concierge::strings.install_failed_notice', [
            'server' => $server->name,
            'reason' => (string) $notice->reason,
        ]), [], [
            'title' => trans('concierge::strings.card_title_reinstall'),
            'lines' => [
                ['label' => trans('concierge::strings.card_server'), 'value' => $server->name],
                ['label' => trans('concierge::strings.card_game'), 'value' => $server->egg?->name ?? '-'],
            ],
            'note' => trans('concierge::strings.card_note_reinstall'),
            'confirm' => trans('concierge::strings.card_confirm_reinstall'),
            'danger' => true,
            // 이 카드는 모델의 도구 루프에서 나온 것이 아니다 → 재개할 상태가 없다.
            'standalone' => 'reinstall:' . $server->id,
        ]);

        // 카드까지 세운 뒤에 찍는다(위 주석과 같은 이유).
        $notice->markNotified();
    }

    /**
     * 상태가 변하는 중인 서버를 감시 목록에 넣는다.
     *
     * ⚠ 여기 들어간 서버는 **끝나면 채팅으로 알린다.** 그래서 프롬프트가 "끝나면 알려드릴게요"
     *   라고 약속할 수 있다 — 약속을 지킬 수단이 없으면 그런 말을 하게 두면 안 된다.
     */
    private function watch(Server $server): void
    {
        foreach ($this->watching as $entry) {
            if ($entry['id'] === $server->id) {
                return;
            }
        }

        $this->watching[] = [
            'id' => $server->id,
            'name' => $server->name,
            'state' => 'starting',
            'since' => now()->timestamp,
            'misses' => 0,
        ];
    }

    /**
     * 감시 중인 서버의 현재 상태를 갱신하고, 끝난 것은 채팅으로 알린 뒤 목록에서 뺀다.
     *
     * ⚠ **켜짐만 끝이 아니다.** 켜는 중이던 서버가 꺼짐으로 가면 기동 실패다 — 그것도 알린다.
     *   조용히 사라지면 사용자는 켜진 줄 안다.
     */
    private function refreshWatching(): void
    {
        $remaining = [];

        foreach ($this->watching as $entry) {
            $server = Server::find($entry['id']);

            if ($server === null) {
                continue;
            }

            // 수명 초과 → 조용히 내린다. 어떤 상태든 예외 없이 — 남으면 5초 폴링이 영구화된다.
            if (now()->timestamp - ($entry['since'] ?? 0) > self::WATCH_TTL_SECONDS) {
                continue;
            }

            $state = $this->stateOf($server);

            // 설치 중에는 데몬이 offline 을 돌려준다 — 기동 실패로 오해하면 안 된다.
            if ($server->status?->value === 'installing') {
                $remaining[] = ['state' => 'installing', 'misses' => 0] + $entry;

                continue;
            }

            if ($state === 'running') {
                $this->announce(trans('concierge::strings.watch_started', [
                    'server' => $server->name,
                    'address' => $server->allocation?->address ?? '-',
                ]), $server);

                continue;
            }

            if ($state === 'offline') {
                // 켜는 중이었는데 꺼졌다 → 기동 실패. 알리고 내린다.
                if ($entry['state'] === 'starting') {
                    $this->announce(trans('concierge::strings.watch_stopped', ['server' => $server->name]), $server);
                }

                // 설치가 끝나 offline 이 된 경우는 설치 알림(#7)이 맡는다. 어느 쪽이든 감시는 끝.
                //  ⚠ 여기서 남겨두면 offline 항목이 영영 안 빠진다 — 5초 폴링 영구화의 한 경로였다.
                continue;
            }

            // 상태를 못 읽었다(좀비·데몬 다운). 몇 번은 일시 장애로 봐주되, 계속이면 접는다.
            if ($state === 'unknown') {
                $misses = ($entry['misses'] ?? 0) + 1;

                if ($misses >= self::WATCH_MAX_MISSES) {
                    continue;
                }

                $remaining[] = ['state' => 'unknown', 'misses' => $misses] + $entry;

                continue;
            }

            // starting / stopping — 계속 지켜본다.
            $remaining[] = ['state' => $state, 'misses' => 0] + $entry;
        }

        $this->watching = $remaining;
    }

    /** 에이전트가 먼저 건네는 한 마디. 그 서버를 다루던 대화로 간다(#29). */
    private function announce(string $text, Server $server): void
    {
        $this->deliverNotice($server, $text, ServerLinks::forToolCalls([
            (object) ['name' => 'start_server', 'serverId' => $server->id, 'isError' => false],
        ]));
    }

    private function stateOf(Server $server): string
    {
        try {
            return (string) (app(DaemonServerRepository::class)->setServer($server)->getDetails()['state'] ?? 'unknown');
        } catch (Throwable) {
            return 'unknown';
        }
    }

    /** 알릴 때의 전원 상태. 데몬이 답하지 않으면 "꺼짐"으로 보지 않고 그냥 false 로 둔다. */
    private function isRunning(Server $server): bool
    {
        try {
            return (app(DaemonServerRepository::class)->setServer($server)->getDetails()['state'] ?? '') === 'running';
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * 답이 필요 없어진 카드를 닫는다 (#18).
     *
     * ⚠ **모델 루프 밖의 카드(standalone)는 스스로 낡는다.** 카드를 띄운 근거가 사라져도
     *   카드는 화면에 남아 있어서, 사용자가 누르면 엉뚱한 일이 벌어지거나 거짓 안내를 받는다:
     *    - 유휴: 자동 정지가 감시만 지우고 카드는 남긴다 → 이미 꺼진 서버에 "더 켜두기"를
     *      눌러 "그대로 두고 지켜볼게요"를 듣는다
     *    - 재설치: 사용자가 화면에서 직접 다시 깐 뒤 옛 카드를 누르면 **또 깐다**
     *   근거가 사라졌으면 카드를 걷고 무슨 일이 있었는지 알린다.
     *
     * ⚠ 카드를 걷어야 **새 알림도 지나간다** — checkNotices 는 카드가 떠 있으면 끼어들지 않는다.
     */
    private function closeStaleCard(): void
    {
        $action = (string) ($this->pendingCard['standalone'] ?? '');

        [$prefix, $serverId] = str_contains($action, ':')
            ? [strtok($action, ':'), (int) substr($action, strpos($action, ':') + 1)]
            : ['', 0];

        $server = $serverId > 0
            // ⚠ **소유자로 찾지 않는다.** 친구(서브유저)도 카드를 받는다(#48).
            ? auth()->user()?->accessibleServers()->find($serverId)
            : null;

        $notice = match ($prefix) {
            'idle' => $this->staleIdleNotice($serverId, $server),
            'reinstall' => $this->staleReinstallNotice($serverId, $server),
            default => null,
        };

        if ($notice === null) {
            return;
        }

        $this->pendingCard = [];
        $this->pendingToken = '';
        ConciergeConversation::find($this->conversationId)?->clearPending();

        // 서버가 지워졌으면 알릴 대상도 없다 — 카드만 걷는다.
        if ($server !== null) {
            $this->deliverNotice($server, $notice['text'], $notice['links']);
        }
    }

    /**
     * 유휴 카드가 낡았는가. 낡았으면 전할 말, 아니면 null.
     *
     * @return ?array{text: string, links: array<int, array{label: string, url: string}>}
     */
    private function staleIdleNotice(int $serverId, ?Server $server): ?array
    {
        if (ConciergeIdleWatch::where('server_id', $serverId)->exists()) {
            return null;
        }

        if ($server === null) {
            return ['text' => '', 'links' => []];
        }

        // 꺼졌으면 자동 정지, 켜져 있으면 다시 쓰이기 시작한 것이다.
        $stopped = $this->stateOf($server) !== 'running';

        return [
            'text' => trans($stopped
                ? 'concierge::strings.idle_auto_stopped'
                : 'concierge::strings.idle_card_moot', ['server' => $server->name]),
            'links' => $stopped ? ServerLinks::forToolCalls([
                (object) ['name' => 'start_server', 'serverId' => $server->id, 'isError' => false],
            ]) : [],
        ];
    }

    /**
     * 재설치 카드가 낡았는가.
     *
     * 판정 레코드가 사라졌거나, 더는 '실패'가 아니거나(사용자가 직접 다시 깔아 정상 판정),
     * 새 판정이 아직 전달되기 전(notified_at 초기화)이면 이 카드는 근거를 잃었다.
     *
     * @return ?array{text: string, links: array<int, array{label: string, url: string}>}
     */
    private function staleReinstallNotice(int $serverId, ?Server $server): ?array
    {
        $check = ConciergeInstallCheck::where('server_id', $serverId)->first();

        if ($check !== null
            && $check->status === ConciergeInstallCheck::STATUS_FAILED
            && $check->notified_at !== null) {
            return null;
        }

        if ($server === null) {
            return ['text' => '', 'links' => []];
        }

        return [
            'text' => trans($check?->status === ConciergeInstallCheck::STATUS_OK
                ? 'concierge::strings.reinstall_card_resolved'
                : 'concierge::strings.reinstall_card_moot', ['server' => $server->name]),
            'links' => [],
        ];
    }

    /**
     * 백업·복원이 끝났다고 알린다 (#36). 전했으면 true.
     *
     * ⚠ 도구가 "끝나면 알려드립니다"라고 약속했다 — 이 메서드가 그 약속이다.
     */
    private function deliverBackupNotice(): bool
    {
        ConciergeBackupWatch::pruneStale();

        $watch = ConciergeBackupWatch::readyFor((int) auth()->id())->first();

        if ($watch === null || $watch->server === null) {
            return false;
        }

        $server = $watch->server;
        $isRestore = $watch->kind === ConciergeBackupWatch::KIND_RESTORE;
        $ok = $watch->succeeded();

        $key = match (true) {
            $isRestore && $ok => 'restore_done_notice',
            $isRestore => 'restore_failed_notice',
            $ok => 'backup_done_notice',
            default => 'backup_failed_notice',
        };

        $this->deliverNotice($server, trans("concierge::strings.{$key}", [
            'server' => $server->name,
            'backup' => $watch->backup()?->name ?? '-',
        ]));

        // ⚠ **전한 뒤에 찍는다** — 먼저 찍으면 전달에 실패했을 때 알림이 영영 사라진다.
        $watch->markNotified();

        return true;
    }

    /**
     * 유휴 서버를 알리고 정지 카드를 띄운다 (#18).
     *
     * ⚠ **알린 시각이 유예의 시작점이다.** 전한 뒤에 찍어야 사용자가 못 본 채로 시간이 흐르지
     *   않는다 — 그래서 판정 명령은 시각을 찍지 않고 여기서 찍는다.
     */
    private function deliverIdleNotice(): void
    {
        $settings = ConciergeSettings::current();
        $watch = ConciergeIdleWatch::undeliveredFor((int) auth()->id(), $settings->idle_minutes)->first();

        if ($watch === null || $watch->server === null) {
            return;
        }
        $server = $watch->server;

        $text = trans('concierge::strings.idle_notice', [
            'server' => $server->name,
            'minutes' => $watch->idleMinutes(),
            'action' => $settings->idle_stop_enabled
                ? trans('concierge::strings.idle_notice_will_stop', ['grace' => $settings->idle_grace_minutes])
                : trans('concierge::strings.idle_notice_no_stop'),
        ]);

        $this->deliverNotice($server, $text, [], [
            'title' => trans('concierge::strings.card_title_idle'),
            'lines' => [
                ['label' => trans('concierge::strings.card_server'), 'value' => $server->name],
                ['label' => trans('concierge::strings.card_game'), 'value' => $server->egg?->name ?? '-'],
            ],
            'note' => trans('concierge::strings.card_note_idle'),
            'confirm' => trans('concierge::strings.card_confirm_idle'),
            'cancel' => trans('concierge::strings.card_cancel_idle'),
            'danger' => true,
            'standalone' => 'idle:' . $server->id,
        ]);

        $watch->forceFill(['notified_at' => now()])->save();
    }

    public function markRead(): void
    {
        $this->unread = false;
    }

    /**
     * 선제 알림을 **그 서버를 다루던 대화**로 보낸다 (#29).
     *
     * ⚠ 현재 열린 대화에 꽂으면 안 된다 — 서버 여러 대를 대화 세션으로 나눠 관리하는
     *   사용자의 맥락을 침범한다. 원 대화는 `concierge_tool_calls`(server_id ↔
     *   conversation_id)에서 찾는다: 그 서버를 마지막으로 다룬 대화다.
     *
     * - 원 대화 == 현재 대화 → 지금까지처럼 화면에 바로 띄운다 (카드 포함)
     * - 원 대화 != 현재 대화 → 그 대화에 기록하고 **목록에 미읽음 표시만** 남긴다.
     *   카드는 대화 행(pending_card)에 실어 두어 그 대화를 열 때 뜬다
     * - 원 대화가 없으면(화면에서 만든 서버 등) 서버 이름으로 **전용 알림 대화**를 만든다
     *
     * ⚠ 사용자 발화가 없는 턴이다. `restoreMessages()` 가 빈 `user_message` 를 건너뛰므로
     *   복원해도 응답만 남는다(의도한 결과다).
     *
     * @param array<int, array{label: string, url: string}> $links
     * @param ?array<string, mixed> $card
     */
    private function deliverNotice(Server $server, string $text, array $links = [], ?array $card = null): void
    {
        $userId = (int) auth()->id();
        $title = trans('concierge::strings.notice_conversation_title', ['server' => $server->name]);

        // ⚠ 원 대화가 없을 때 **매번 새 대화를 만들면 안 된다.** 그 서버를 대화로 다룬 적이
        //   없는 사용자(친구가 화면에서만 쓰던 경우)는 알림이 올 때마다 목록에 같은 이름의
        //   대화가 쌓인다. 이미 있는 전용 알림 대화를 다시 쓴다.
        $target = $this->originConversationId($server)
            ?? ConciergeConversation::query()
                ->where('user_id', $userId)
                ->where('title', $title)
                ->latest('last_message_at')
                ->value('id')
            ?? ConciergeConversation::newId();

        ConciergeUsage::record($userId, ConciergeSettings::current(), [
            'conversation_id' => $target,
            'status' => ConciergeUsage::STATUS_OK,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'user_message' => null,
            'assistant_message' => $text,
        ]);

        // 새 대화라면 제목은 알림 본문이 아니라 **서버 이름 기반**으로 깔끔하게.
        $conversation = ConciergeConversation::ensure($target, $userId, $title);

        if ($card !== null) {
            // standalone 카드는 재개 상태(캐시)가 없다 — 행에 실어 두면 열 때 복원된다.
            $conversation->markPending('standalone', $card);
        }

        if ($target === $this->conversationId) {
            $this->messages[] = ['role' => 'assistant', 'text' => $text, 'links' => $links];

            if ($card !== null) {
                $this->pendingCard = $card;
                $this->pendingToken = '';
            }
        } else {
            // 현재 화면은 건드리지 않는다 — 목록의 그 대화에 점만 켠다.
            $conversation->forceFill(['notice_unread_at' => now()])->save();
        }

        $this->unread = true;
        $this->refreshConversations();
    }

    /**
     * 이 서버를 마지막으로 다룬 대화. 도구 이력에서 찾는다 — 별도 매핑 테이블이 필요 없다.
     * 대화가 지워졌으면 null (호출부가 전용 알림 대화를 만든다).
     */
    private function originConversationId(Server $server): ?string
    {
        $userId = (int) auth()->id();

        $conversationId = ConciergeToolCall::query()
            ->where('server_id', $server->id)
            ->whereNotNull('conversation_id')
            ->whereHas('usage', fn ($query) => $query->where('user_id', $userId))
            ->latest('id')
            ->value('conversation_id');

        if ($conversationId === null) {
            return null;
        }

        return ConciergeConversation::query()
            ->where('user_id', $userId)
            ->whereKey($conversationId)
            ->exists() ? $conversationId : null;
    }

    /** 사이드바의 "새 대화". 행은 첫 발화 때 생긴다(ConciergeConversation 주석 참고). */
    public function startConversation(): void
    {
        // ULID 라서 사전순 정렬이 곧 시간순이다 → 관리 화면에서 최신 대화부터 보인다.
        $this->conversationId = ConciergeConversation::newId();
        $this->messages = [];
        $this->pendingCard = [];
        $this->pendingToken = '';
        $this->draft = '';

        $this->refreshConversations();
    }

    /**
     * 사이드바에서 대화를 고른다.
     *
     * ⚠ **소유자 확인이 여기 있다.** id 는 브라우저에서 오므로, 없는 것과 남의 것을
     *   구분하지 않고 똑같이 새 대화로 떨어뜨린다.
     */
    public function openConversation(string $id): void
    {
        $conversation = ConciergeConversation::query()
            ->where('user_id', (int) auth()->id())
            ->find($id);

        if ($conversation === null) {
            $this->startConversation();

            return;
        }

        $this->conversationId = $conversation->id;
        $this->messages = $this->restoreMessages($conversation);
        $this->draft = '';

        // 알림을 확인했다 — 목록의 점을 끈다(#29).
        if ($conversation->notice_unread_at !== null) {
            $conversation->forceFill(['notice_unread_at' => null])->save();
        }

        $this->restorePendingCard($conversation);
        $this->refreshConversations();
    }

    /**
     * 저장된 턴을 말풍선으로 되돌린다.
     *
     * ⚠ 한 행이 **한 턴**(사용자 발화 + 최종 응답)이다. 도구 이력은 `concierge_tool_calls`
     *   에 따로 있고 여기 섞지 않는다 — 이 배열은 그대로 모델에게 다시 보내는 대화 맥락이라
     *   화면 장식이 들어가면 맥락이 오염된다.
     *
     * @return array<int, array{role: string, text: string}>
     */
    private function restoreMessages(ConciergeConversation $conversation): array
    {
        $turns = $conversation->messages;
        $dropped = $turns->count() - self::RESTORED_TURN_LIMIT;

        $messages = $dropped > 0
            ? [['role' => 'event', 'text' => trans('concierge::strings.older_turns_hidden', ['count' => $dropped])]]
            : [];

        foreach ($turns->slice(max(0, $dropped)) as $usage) {
            if (filled($usage->user_message)) {
                $messages[] = ['role' => 'user', 'text' => $usage->user_message];
            }

            if (filled($usage->assistant_message)) {
                $messages[] = [
                    'role' => 'assistant',
                    'text' => $usage->assistant_message,
                    // 버튼은 저장하지 않고 **그 턴의 도구 이력에서 다시 만든다.** 저장해 두면
                    // 서버가 지워지거나 권한이 빠진 뒤에도 죽은 링크가 남는다.
                    'links' => ServerLinks::forToolCalls($usage->toolCalls),
                ];
            }
        }

        return $this->mergeConsecutive($messages);
    }

    /**
     * 같은 역할이 연달아 오면 하나로 합친다.
     *
     * ⚠ **확인 카드를 방치한 턴은 응답이 비어 있다.** 그 턴을 복원하면 사용자 발화만 남고,
     *   이어서 새 발화를 하면 `user` 가 연속으로 놓인다 — 대화 API 는 역할이 번갈아 오기를
     *   기대하므로 그대로 보내면 거절당한다. 화면에서도 말풍선 두 개보다 하나가 자연스럽다.
     *
     * @param  array<int, array{role: string, text: string}>  $messages
     * @return array<int, array{role: string, text: string}>
     */
    private function mergeConsecutive(array $messages): array
    {
        $merged = [];

        foreach ($messages as $message) {
            $previous = array_key_last($merged);

            // 'event' 는 API 로 나가지 않는 화면 표시라 합치기 대상이 아니다.
            if ($previous !== null
                && $message['role'] !== 'event'
                && $merged[$previous]['role'] === $message['role']
            ) {
                $merged[$previous]['text'] .= "\n\n" . $message['text'];
                $merged[$previous]['links'] = array_merge(
                    $merged[$previous]['links'] ?? [],
                    $message['links'] ?? [],
                );

                continue;
            }

            $merged[] = $message;
        }

        return $merged;
    }

    /**
     * 카드를 띄운 채 새로고침한 경우 카드를 되살린다.
     *
     * 재개 상태는 캐시에만 있고 수명이 짧다. **만료됐으면 되살리지 않는 것이 맞다** — 그 상태
     * 안의 도구 결과는 읽은 시점의 스냅샷이라, 한참 지난 뒤 승인하면 그 사이의 변경을 덮어쓴다.
     */
    private function restorePendingCard(ConciergeConversation $conversation): void
    {
        $this->pendingCard = [];
        $this->pendingToken = '';

        // 다른 대화에 배달됐던 standalone 카드(#29) — 재개 상태(캐시)가 없으므로 그대로 복원.
        if (isset($conversation->pending_card['standalone'])) {
            $this->pendingCard = $conversation->pending_card;

            return;
        }

        $this->cardName = (string) ($conversation->pending_card['name_input']['value'] ?? '');

        if ($conversation->pending_token === null) {
            return;
        }

        if (!Cache::store(self::PENDING_STORE)->has('concierge:pending:' . $conversation->pending_token)) {
            $conversation->clearPending();
            $this->messages[] = ['role' => 'event', 'text' => trans('concierge::strings.card_expired')];

            return;
        }

        $this->pendingToken = $conversation->pending_token;
        $this->pendingCard = $conversation->pending_card ?? [];
    }

    /** 헤더에 띄울 현재 대화 이름. 첫 발화 전이면 아직 목록에 없다. */
    public function currentTitle(): string
    {
        foreach ($this->conversations as $conversation) {
            if ($conversation['id'] === $this->conversationId) {
                return $conversation['title'];
            }
        }

        return trans('concierge::strings.new_conversation');
    }

    private function refreshConversations(): void
    {
        $this->conversations = ConciergeConversation::listFor((int) auth()->id())
            ->limit(self::CONVERSATION_LIST_LIMIT)
            ->get()
            ->map(fn (ConciergeConversation $c) => [
                'id' => $c->id,
                'title' => $c->displayTitle(),
                'when' => $c->lastMessageLabel(),
                'unread' => $c->notice_unread_at !== null,
            ])
            ->all();
    }

    public function render(): View
    {
        return view('concierge::livewire.agent-sidebar');
    }

    /**
     * 렌더 훅이 이걸 보고 사이드바를 붙일지 정한다.
     *
     * ⚠ **모든 페이지에서 불린다.** 여기서 예외가 새면 패널 전체가 500 이 된다.
     */
    public static function canAccess(): bool
    {
        if (!auth()->check()) {
            return false;
        }

        // 켜기/끄기 설정은 없다(#2) — 끄고 싶으면 플러그인을 비활성화한다. 그러면
        // 프로바이더가 부팅되지 않아 이 코드가 아예 실행되지 않는다.
        return true;
    }

    public function send(): void
    {
        $text = trim($this->draft);

        if ($text === '' || $this->pendingCard !== []) {
            // 카드가 떠 있는 동안에는 새 발화를 받지 않는다 — 결정이 먼저다.
            return;
        }

        $this->draft = '';
        $this->messages[] = ['role' => 'user', 'text' => $text];

        // 사용자 말풍선을 즉시 띄운다. 이걸 안 하면 응답이 끝날 때까지(수십 초) 자기가 무엇을
        // 보냈는지조차 화면에 안 나온다 — Livewire 는 요청이 끝나야 다시 렌더하기 때문이다.
        $this->streamTo('live-user', $text);

        $settings = ConciergeSettings::current();
        $userId = (int) auth()->id();

        if (!$settings->isConfigured()) {
            $this->reply($settings, $userId, $text, ConciergeUsage::STATUS_NOT_CONFIGURED, trans('concierge::strings.not_configured'));

            return;
        }

        $limit = $settings->daily_message_limit;

        if ($limit > 0 && ConciergeUsage::todayCountFor($userId) >= $limit) {
            $this->reply($settings, $userId, $text, ConciergeUsage::STATUS_RATE_LIMITED, trans('concierge::strings.rate_limited', ['limit' => $limit]));

            return;
        }

        $this->streamTo('live-assistant', trans('concierge::strings.thinking'));

        $this->run(
            fn (AnthropicChatService $service) => $service->start($this->messages, ...$this->callbacks()),
            $settings,
            $userId,
            $text,
        );
    }

    /** 확인 카드의 "실행". */
    public function confirmTool(): void
    {
        $this->resolveCard(approved: true);
    }

    /** 확인 카드의 "취소". */
    public function cancelTool(): void
    {
        $this->resolveCard(approved: false);
    }

    private function resolveCard(bool $approved): void
    {
        if ($this->pendingCard === []) {
            return;
        }

        $card = $this->pendingCard;

        // 🔴 **재개 상태를 먼저 꺼낸다.** `cacheKey()` 는 `pendingToken` 으로 만들어지므로
        //    토큰을 비운 뒤에 부르면 키가 `concierge:pending:` 이 되어 **항상 null** 이고,
        //    모든 카드가 "확인이 만료되었습니다"로 끝난다. 실제로 그렇게 깨뜨린 적이 있다.
        //    (에이전트가 먼저 띄운 카드는 모델 루프 밖이라 꺼낼 상태 자체가 없다)
        $state = isset($card['standalone'])
            ? null
            : Cache::store(self::PENDING_STORE)->pull($this->cacheKey());

        // 카드는 어느 쪽이든 즉시 걷는다. 두 번 눌리면 같은 명령이 두 번 나간다.
        $this->pendingCard = [];
        $this->pendingToken = '';

        // 새로고침용 표시도 같이 걷는다. 안 걷으면 다음에 열 때 이미 처리한 카드가 되살아난다.
        ConciergeConversation::find($this->conversationId)?->clearPending();

        if (isset($card['standalone'])) {
            $this->resolveStandalone((string) $card['standalone'], $approved, (string) ($card['title'] ?? ''));

            return;
        }

        // 카드의 편집 필드(#59): 사용자가 고친 이름을 **실행될 입력에 주입**한다.
        //  ⚠ 카드가 보여준 값과 실행되는 값이 같아야 한다는 원칙의 연장 — 편집 필드는
        //    "보여준 값"이 곧 입력창의 현재 값이다. 비우면 기본 이름으로 되돌아간다(plan 이 생성).
        if ($approved && is_array($state) && isset($card['name_input']) && isset($state['pending']['input'])) {
            $state['pending']['input']['name'] = mb_substr(trim($this->cardName), 0, 40);
        }

        $this->cardName = '';

        if (!is_array($state)) {
            // 캐시가 만료됐거나 서버가 재시작됐다. 명령을 보내지 않은 것이 확실하므로 그렇게 알린다.
            $this->messages[] = ['role' => 'event', 'text' => trans('concierge::strings.card_expired')];

            return;
        }

        $this->messages[] = [
            'role' => 'event',
            'text' => trans($approved ? 'concierge::strings.card_approved' : 'concierge::strings.card_cancelled', [
                'action' => $card['title'] ?? '',
            ]),
        ];

        $settings = ConciergeSettings::current();

        $this->run(
            fn (AnthropicChatService $service) => $service->resume($state, $approved, ...$this->callbacks()),
            $settings,
            (int) auth()->id(),
            (string) ($state['user_message'] ?? ''),
            $state,
        );
    }

    /**
     * 모델 루프 밖에서 띄운 카드의 처리. 지금은 재설치 하나뿐이다.
     *
     * ⚠ **소유자를 여기서 다시 확인한다.** 카드 내용은 브라우저를 거쳐 돌아온다.
     */
    private function resolveStandalone(string $action, bool $approved, string $title): void
    {
        $this->messages[] = [
            'role' => 'event',
            'text' => trans($approved ? 'concierge::strings.card_approved' : 'concierge::strings.card_cancelled', [
                'action' => $title,
            ]),
        ];

        if (str_starts_with($action, 'idle:')) {
            $this->resolveIdle((int) substr($action, strlen('idle:')), $approved);

            return;
        }

        if (!$approved || !str_starts_with($action, 'reinstall:')) {
            return;
        }

        // 재설치는 서버를 통째로 다시 까는 일이라 **주인만** 할 수 있다(#48).
        $server = Server::query()
            ->where('owner_id', (int) auth()->id())
            ->find((int) substr($action, strlen('reinstall:')));

        if ($server === null) {
            $this->messages[] = ['role' => 'assistant', 'text' => trans('concierge::strings.reinstall_gone'), 'links' => []];

            return;
        }

        try {
            app(ReinstallServerService::class)->handle($server);
        } catch (Throwable $exception) {
            report($exception);
            $this->messages[] = ['role' => 'assistant', 'text' => trans('concierge::strings.reinstall_failed'), 'links' => []];

            return;
        }

        // 재설치가 끝나면 Installed 가 다시 뜨고 VerifyInstall 이 또 판정한다 —
        // 이번에도 실패하면 같은 흐름으로 다시 알린다.
        $this->watch($server);

        // ⚠ 화면에 직접 넣지 않는다. deliverNotice 가 **그 서버를 다루던 대화**로 보내고,
        //   그게 지금 열린 대화면 화면에도 넣는다(#29).
        $this->deliverNotice($server, trans('concierge::strings.reinstall_started', ['server' => $server->name]),
            ServerLinks::forToolCalls([
                (object) ['name' => 'restart_server', 'serverId' => $server->id, 'isError' => false],
            ]));
    }

    /**
     * 유휴 카드의 결정 (#18).
     *
     * "더 켜두기"는 감시를 끄는 게 아니라 **타이머를 처음으로 되돌린다** — 계속 안 쓰면
     * 다시 물어본다. 아예 끄면 안 쓰는 서버가 영원히 켜져 있다.
     */
    private function resolveIdle(int $serverId, bool $approved): void
    {
        // ⚠ 카드는 친구(서브유저)에게도 간다(#48). 소유자로 찾으면 친구가 눌러도 **아무 일도
        //   일어나지 않는다** — 실제로 그랬다. 접근 가능한 서버에서 찾는다.
        $server = auth()->user()?->accessibleServers()->find($serverId);

        if ($server === null) {
            return;
        }

        // ⚠ 알림을 받은 뒤 권한이 회수됐을 수 있다. **누를 때 다시 확인한다.**
        if ($approved && !auth()->user()->can(SubuserPermission::ControlStop, $server)) {
            $this->messages[] = ['role' => 'assistant', 'text' => trans('concierge::strings.idle_no_permission'), 'links' => []];

            return;
        }

        if (!$approved) {
            ConciergeIdleWatch::where('server_id', $server->id)
                ->update(['snoozed_at' => now(), 'notified_at' => null]);

            $this->deliverNotice($server, trans('concierge::strings.idle_snoozed', ['server' => $server->name]));

            return;
        }

        try {
            app(DaemonServerRepository::class)->setServer($server)->power('stop');
        } catch (Throwable $exception) {
            report($exception);

            // 조용히 돌아가면 사용자는 눌렀는데 아무 일도 없는 것으로 본다.
            $this->deliverNotice($server, trans('concierge::strings.idle_stop_failed', ['server' => $server->name]));

            return;
        }

        ConciergeIdleWatch::where('server_id', $server->id)->delete();

        $this->deliverNotice($server, trans('concierge::strings.idle_stopped', ['server' => $server->name]),
            ServerLinks::forToolCalls([
                (object) ['name' => 'start_server', 'serverId' => $server->id, 'isError' => false],
            ]));
    }

    /**
     * 모델 호출을 감싸고, 결과가 "카드 대기"인지 "완료"인지에 따라 뒤처리한다.
     *
     * @param  Closure(AnthropicChatService): ChatResult  $call
     * @param  array<string, mixed>  $previousState  재개일 때만 채워진다
     */
    private function run(callable $call, ConciergeSettings $settings, int $userId, string $userMessage, array $previousState = []): void
    {
        /** @var User $user */
        $user = auth()->user();

        try {
            $result = $call(new AnthropicChatService($settings, $user));
        } catch (Throwable $exception) {
            // 원문은 로그(관리자만 봄)에만 남긴다. 친구들에게 API 오류 문자열을 보여줄 이유가 없다.
            report($exception);

            $this->finish(
                $settings, $userId, $userMessage, $previousState,
                ConciergeUsage::STATUS_ERROR,
                trans('concierge::strings.error'),
                0, 0, [],
                $exception->getMessage(),
            );

            return;
        }

        if ($result->needsConfirmation()) {
            $this->pauseForCard($result, $settings, $userId, $userMessage, $previousState);

            return;
        }

        // 안전 분류기가 거절하면 HTTP 는 200 이고 본문이 비어 있다.
        // 텍스트 유무가 아니라 stop_reason 을 봐야 한다.
        if ($result->isRefusal()) {
            $this->finish(
                $settings, $userId, $userMessage, $previousState,
                ConciergeUsage::STATUS_ERROR,
                trans('concierge::strings.refused'),
                $result->inputTokens, $result->outputTokens, $result->toolCalls,
                'stop_reason=refusal', $result->searchCount,
            );

            return;
        }

        $this->finish(
            $settings, $userId, $userMessage, $previousState,
            ConciergeUsage::STATUS_OK,
            trim($result->text) !== '' ? $result->text : trans('concierge::strings.empty_reply'),
            $result->inputTokens, $result->outputTokens, $result->toolCalls,
            null, $result->searchCount,
        );
    }

    /**
     * 카드를 띄우고 멈춘다. **여기서 이미 사용량 행을 만든다** — 사용자가 카드를 방치해도
     * 그때까지 쓴 토큰은 기록에 남아야 한다. 결정이 오면 같은 행을 갱신한다.
     *
     * @param array<string, mixed> $previousState
     */
    private function pauseForCard(ChatResult $result, ConciergeSettings $settings, int $userId, string $userMessage, array $previousState): void
    {
        $shownText = (string) ($previousState['persisted_text'] ?? '');
        $newText = mb_substr($result->text, mb_strlen($shownText));

        if (trim($newText) !== '') {
            $this->messages[] = ['role' => 'assistant', 'text' => $newText];
        }

        $usage = $this->persist(
            $settings, $userId, $userMessage, $previousState,
            ConciergeUsage::STATUS_AWAITING,
            $result->text,
            $result->inputTokens, $result->outputTokens, $result->toolCalls,
            null, $result->searchCount,
        );

        $state = $result->state;
        $state['usage_id'] = $usage->id;
        $state['user_message'] = $userMessage;
        $state['persisted_tools'] = count($result->toolCalls);
        $state['persisted_text'] = $result->text;

        $this->pendingToken = (string) Str::ulid();
        $this->pendingCard = $result->card;
        $this->cardName = (string) ($result->card['name_input']['value'] ?? '');

        Cache::store(self::PENDING_STORE)->put($this->cacheKey(), $state, now()->addMinutes(self::PENDING_TTL_MINUTES));

        // 카드가 떠 있는 채로 새로고침해도 다시 그릴 수 있게 대화에 표시를 남긴다.
        // (persist() 가 방금 ensure() 했으므로 행은 반드시 있다)
        ConciergeConversation::find($this->conversationId)?->markPending($this->pendingToken, $result->card);
    }

    /**
     * @param array<string, mixed> $previousState
     * @param array<int, \WisdomIT\Concierge\Tools\ToolCallResult> $toolCalls
     */
    private function finish(
        ConciergeSettings $settings,
        int $userId,
        string $userMessage,
        array $previousState,
        string $status,
        string $assistantMessage,
        int $inputTokens,
        int $outputTokens,
        array $toolCalls,
        ?string $error = null,
        int $searchCount = 0,
    ): void {
        $shownText = (string) ($previousState['persisted_text'] ?? '');
        $newText = mb_substr($assistantMessage, mb_strlen($shownText));

        $this->messages[] = [
            'role' => 'assistant',
            'text' => trim($newText) !== '' ? $newText : $assistantMessage,
            'links' => ServerLinks::forToolCalls($toolCalls),
        ];

        $this->persist(
            $settings, $userId, $userMessage, $previousState,
            $status, $assistantMessage, $inputTokens, $outputTokens, $toolCalls, $error, $searchCount,
        );
    }

    /**
     * 사용량 행을 만들거나(첫 호출) 갱신한다(카드 이후). 한 번의 사용자 발화 = 한 행이다.
     *
     * @param array<string, mixed> $previousState
     * @param array<int, \WisdomIT\Concierge\Tools\ToolCallResult> $toolCalls
     */
    private function persist(
        ConciergeSettings $settings,
        int $userId,
        string $userMessage,
        array $previousState,
        string $status,
        string $assistantMessage,
        int $inputTokens,
        int $outputTokens,
        array $toolCalls,
        ?string $error = null,
        int $searchCount = 0,
    ): ConciergeUsage {
        $attributes = [
            'conversation_id' => $this->conversationId,
            'status' => $status,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            // 검색은 토큰과 별도로 과금된다 — 따로 센다(#43).
            'search_count' => $searchCount,
            'error' => $error,
            'user_message' => $userMessage,
            'assistant_message' => $assistantMessage,
        ];

        $usageId = $previousState['usage_id'] ?? null;

        if ($usageId && $usage = ConciergeUsage::find($usageId)) {
            $usage->update($attributes);
        } else {
            $usage = ConciergeUsage::record($userId, $settings, $attributes);
        }

        // 시간이 걸리는 동작은 감시 목록에 넣는다 → 하단 카드로 진행이 보이고, 끝나면 알린다.
        foreach ($toolCalls as $call) {
            if (!$call->isError && $call->serverId && in_array($call->name, self::WATCHED_TOOLS, true)) {
                if ($server = Server::find($call->serverId)) {
                    $this->watch($server);
                }
            }
        }

        // 대화 행은 **첫 발화 때** 만든다. 화면을 열기만 해도 만들면 사이드바가 빈 항목으로 찬다.
        ConciergeConversation::ensure($this->conversationId, $userId, $userMessage);

        // 첫 발화면 이때 제목이 정해진다 → 사이드바에 바로 뜨게 목록을 다시 읽는다.
        $this->refreshConversations();

        // "무엇을 답했는가"보다 **"무엇을 보고 답했는가"** 가 진단에 중요하다.
        // 카드 이전에 이미 저장한 것은 건너뛴다(한 번의 발화가 두 번 저장되면 안 된다).
        foreach (array_slice($toolCalls, (int) ($previousState['persisted_tools'] ?? 0)) as $call) {
            ConciergeToolCall::create([
                'usage_id' => $usage->id,
                'conversation_id' => $this->conversationId,
                'tool_name' => $call->name,
                'server_id' => $call->serverId,
                'input' => json_encode($call->input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                // 콘솔 로그·파일 내용이 통째로 들어올 수 있어 자른다. 진단에는 앞부분이면 충분하다.
                'result' => Str::limit($call->output, 4000),
                'is_error' => $call->isError,
            ]);
        }

        return $usage;
    }

    /** 도구 없이 즉시 끝나는 경로(설정 안 됨·한도 초과 등). */
    private function reply(ConciergeSettings $settings, int $userId, string $userMessage, string $status, string $assistantMessage): void
    {
        $this->finish($settings, $userId, $userMessage, [], $status, $assistantMessage, 0, 0, []);
    }

    /** @return array{0: \Closure, 1: \Closure, 2: \Closure} */
    private function callbacks(): array
    {
        return [
            fn (string $accumulated) => $this->streamTo('live-assistant', $accumulated, asMarkdown: true),
            fn () => $this->streamTo('live-assistant', trans('concierge::strings.thinking')),
            // 도구가 도는 동안 화면이 멈춘 것처럼 보이면 안 된다 — 무엇을 보고 있는지 알린다.
            fn (string $tool) => $this->streamTo('live-assistant', trans('concierge::strings.using_tool', [
                'tool' => trans('concierge::strings.tool_' . $tool),
            ])),
        ];
    }

    private function cacheKey(): string
    {
        return 'concierge:pending:' . $this->pendingToken;
    }

    /**
     * 저장된 말풍선과 스트리밍 중인 말풍선이 **같은 함수**를 써야
     * 스트리밍이 끝나는 순간 모양이 튀지 않는다. 관리자의 대화 보기도 같은 것을 쓴다.
     */
    public function markdown(string $text): string
    {
        return Markdown::render($text);
    }

    /**
     * Livewire 의 스트림 대상은 innerHTML 로 들어간다 → 그대로 넣으면 안 된다.
     * 모델 응답은 마크다운 렌더(안에서 이스케이프됨), 그 외에는 평문 이스케이프.
     */
    private function streamTo(string $target, string $content, bool $asMarkdown = false): void
    {
        $this->stream(
            name: $target,
            content: $asMarkdown ? $this->markdown($content) : e($content),
            replace: true,
        );
    }
}
