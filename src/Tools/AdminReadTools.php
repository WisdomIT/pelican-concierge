<?php

namespace WisdomIT\Concierge\Tools;

use App\Models\ActivityLog;
use App\Models\ApiKey;
use App\Models\BackupHost;
use App\Models\DatabaseHost;
use App\Models\Egg;
use App\Models\Mount;
use App\Models\Server;
use App\Models\User;
use App\Models\WebhookConfiguration;
use Spatie\Health\ResultStores\ResultStore;

/**
 * 관리 화면 읽기 2차 (#61) — egg·mount·호스트·웹훅·API 키·헬스·활동 로그.
 *
 * 1차(AdminTools)와 같은 원칙이다: 요청자가 화면에서 볼 수 있는 것만, 리소스별
 * 권한으로 걸러서.
 *
 * 🔴 **자격증명은 어떤 출력에도 넣지 않는다.** API 키 값·토큰, 백업/DB 호스트의
 *    접속 비밀번호는 화면에서만 본다 — 도구 결과는 대화 기록(관리자 화면·사용량
 *    로그)에 그대로 저장되기 때문이다. 여기서는 "무엇이 있는지"만 말한다.
 */
final class AdminReadTools
{
    /** 활동 로그 한 번에 보여줄 최대 줄 수 — 길면 토큰만 태우고 읽히지 않는다. */
    private const ACTIVITY_LIMIT = 25;

    public function __construct(private readonly User $user) {}

    /** 이 패널이 만들 수 있는 게임들 — "그 게임은 관리자가 추가해야 한다"의 근거. */
    public function listEggs(): string
    {
        $eggs = Egg::query()->withCount(['servers', 'variables'])->orderBy('name')->get();

        if ($eggs->isEmpty()) {
            return 'No eggs are imported on this panel — nothing can be created until an egg is added.';
        }

        $lines = $eggs->map(fn (Egg $egg) => sprintf(
            '- %s (id %d) — %d servers, %d variables%s',
            $egg->name,
            $egg->id,
            $egg->servers_count,
            $egg->variables_count,
            filled($egg->description) ? "\n  " . str($egg->description)->limit(120) : '',
        ));

        return sprintf("Eggs imported on this panel (%d):\n%s", $eggs->count(), $lines->implode("\n"));
    }

    /** egg 하나의 변수들 — "이 설정이 왜 있나", "무엇을 채워야 하나". */
    public function getEggDetails(array $input): string
    {
        $reference = trim((string) ($input['egg'] ?? ''));

        if ($reference === '') {
            throw new ToolInputException('egg is required. Check list_eggs first.');
        }

        $egg = Egg::query()
            ->where(fn ($q) => $q->where('id', is_numeric($reference) ? (int) $reference : 0)
                ->orWhereRaw('lower(name) = ?', [mb_strtolower($reference)]))
            ->with('variables')
            ->withCount('servers')
            ->first();

        if ($egg === null) {
            throw new ToolInputException("No egg matches \"{$reference}\". Get the exact name from list_eggs.");
        }

        $variables = $egg->variables->map(fn ($v) => sprintf(
            '  - %s (%s)%s%s — default: %s',
            $v->name,
            $v->env_variable,
            $v->user_viewable ? '' : ' [hidden from users]',
            $v->user_editable ? '' : ' [read-only]',
            filled($v->default_value) ? $v->default_value : '(empty)',
        ))->implode("\n");

        return sprintf(
            "Egg %s (id %d) — %d servers use it\n%s\nvariables (%d):\n%s",
            $egg->name,
            $egg->id,
            $egg->servers_count,
            filled($egg->description) ? str($egg->description)->limit(300) : '(no description)',
            $egg->variables->count(),
            $variables === '' ? '  (none)' : $variables,
        );
    }

    /** 마운트 — 노드의 호스트 디렉터리를 서버 안에 붙이는 설정. */
    public function listMounts(): string
    {
        $mounts = Mount::query()->withCount(['nodes', 'eggs'])->orderBy('name')->get();

        if ($mounts->isEmpty()) {
            return 'No mounts are configured on this panel.';
        }

        $lines = $mounts->map(fn (Mount $m) => sprintf(
            '- %s (id %d) — %s → %s%s, %d nodes, %d eggs',
            $m->name,
            $m->id,
            $m->source,
            $m->target,
            $m->read_only ? ' [read-only]' : '',
            $m->nodes_count,
            $m->eggs_count,
        ));

        return "Mounts:\n" . $lines->implode("\n");
    }

    /** DB 호스트 — 서버가 데이터베이스를 만들 수 있는 곳. 비밀번호는 말하지 않는다. */
    public function listDatabaseHosts(): string
    {
        $hosts = DatabaseHost::query()->withCount(['databases', 'nodes'])->orderBy('name')->get();

        if ($hosts->isEmpty()) {
            return 'No database hosts are configured — servers cannot be given databases until one is added.';
        }

        $lines = $hosts->map(fn (DatabaseHost $h) => sprintf(
            '- %s (id %d) — %s:%d, %d databases, %d nodes',
            $h->name,
            $h->id,
            $h->host,
            (int) $h->port,
            $h->databases_count,
            $h->nodes_count,
        ));

        return "Database hosts:\n" . $lines->implode("\n");
    }

    /** 백업 호스트 — 백업이 어디로 가는가. 접속 설정(키·비밀번호)은 말하지 않는다. */
    public function listBackupHosts(): string
    {
        $hosts = BackupHost::query()->withCount('nodes')->orderBy('name')->get();

        if ($hosts->isEmpty()) {
            return 'No backup hosts are configured — backups fall back to whatever the panel default is.';
        }

        $lines = $hosts->map(fn (BackupHost $h) => sprintf(
            '- %s (id %d) — type %s, %d nodes',
            $h->name,
            $h->id,
            $h->schema ?? '?',
            $h->nodes_count,
        ));

        return "Backup hosts:\n" . $lines->implode("\n");
    }

    /** 웹훅 — 패널 이벤트가 어디로 나가는가. */
    public function listWebhooks(): string
    {
        $hooks = WebhookConfiguration::query()->orderBy('id')->get();

        if ($hooks->isEmpty()) {
            return 'No webhooks are configured.';
        }

        $lines = $hooks->map(fn (WebhookConfiguration $w) => sprintf(
            '- %s (id %d)%s — events: %s',
            $w->description ?: $w->endpoint,
            $w->id,
            $w->description ? " → {$w->endpoint}" : '',
            implode(', ', (array) $w->events) ?: '(none)',
        ));

        return "Webhooks:\n" . $lines->implode("\n");
    }

    /**
     * API 키 — 누가 언제 쓰는지만. **키 값·식별자는 절대 내보내지 않는다.**
     */
    public function listApiKeys(): string
    {
        $keys = ApiKey::query()->with('user:id,username')->orderByDesc('last_used_at')->limit(50)->get();

        if ($keys->isEmpty()) {
            return 'No API keys exist on this panel.';
        }

        $lines = $keys->map(fn (ApiKey $k) => sprintf(
            '- %s — owner %s, %s%s%s',
            $k->memo ?: '(no memo)',
            $k->user?->username ?? '?',
            $k->last_used_at ? "last used {$k->last_used_at}" : 'never used',
            $k->expires_at ? ", expires {$k->expires_at}" : '',
            // key_type 은 int 상수다 — 숫자로 두면 "type 2" 가 되어 아무 뜻도 못 준다.
            ', ' . match ((int) $k->key_type) {
                ApiKey::TYPE_ACCOUNT => 'account key (acts as that user)',
                ApiKey::TYPE_APPLICATION => 'application key (admin API)',
                default => 'unscoped key',
            },
        ));

        return "API keys — key values are never shown here, read them on the panel screen:\n"
            . $lines->implode("\n");
    }

    /** 패널 헬스 체크 — "패널이 이상한데요"의 첫 조회. */
    public function getPanelHealth(): string
    {
        $results = app(ResultStore::class)->latestResults();

        if ($results === null) {
            return 'Health checks have not run yet on this panel.';
        }

        $lines = collect($results->storedCheckResults)->map(fn ($c) => sprintf(
            '- %s: %s%s',
            $c->label ?: $c->name,
            $c->status,
            filled($c->notificationMessage) ? ' — ' . $c->notificationMessage : '',
        ));

        // finishedAt 은 DateTime 이다 — 문자열로 이어 붙이면 예외가 난다(실측).
        $finished = $results->finishedAt instanceof \DateTimeInterface
            ? $results->finishedAt->format('Y-m-d H:i')
            : (string) ($results->finishedAt ?? '?');

        return sprintf("Panel health (checked %s):\n%s", $finished, $lines->implode("\n"));
    }

    /** 활동 로그 — "이거 누가 했어요?"의 답. 사용자·서버로 좁힐 수 있다. */
    public function getActivityLog(array $input): string
    {
        $query = ActivityLog::query()->with('actor')->latest('timestamp');

        $who = trim((string) ($input['user'] ?? ''));
        $server = trim((string) ($input['server'] ?? ''));

        if ($who !== '') {
            $actor = User::query()
                ->where(fn ($q) => $q->where('id', is_numeric($who) ? (int) $who : 0)
                    ->orWhereRaw('lower(username) = ?', [mb_strtolower($who)]))
                ->first();

            if ($actor === null) {
                throw new ToolInputException("No user matches \"{$who}\".");
            }

            $query->where('actor_type', $actor->getMorphClass())->where('actor_id', $actor->id);
        }

        if ($server !== '') {
            $target = Server::query()
                ->where(fn ($q) => $q->where('uuid_short', $server)
                    ->orWhere('id', is_numeric($server) ? (int) $server : 0)
                    ->orWhereRaw('lower(name) = ?', [mb_strtolower($server)]))
                ->first();

            if ($target === null) {
                throw new ToolInputException("No server matches \"{$server}\".");
            }

            $query->whereHas('subjects', fn ($q) => $q
                ->where('subject_type', $target->getMorphClass())
                ->where('subject_id', $target->id));
        }

        $entries = $query->limit(self::ACTIVITY_LIMIT)->get();

        if ($entries->isEmpty()) {
            return 'No activity matches that.';
        }

        $lines = $entries->map(fn (ActivityLog $log) => sprintf(
            '- %s  %s  by %s%s',
            $log->timestamp,
            $log->event,
            $log->actor?->username ?? 'system',
            $log->ip ? " from {$log->ip}" : '',
        ));

        return sprintf(
            "Most recent activity (%d of the newest%s):\n%s",
            $entries->count(),
            $entries->count() === self::ACTIVITY_LIMIT ? ', older entries not shown' : '',
            $lines->implode("\n"),
        );
    }
}
