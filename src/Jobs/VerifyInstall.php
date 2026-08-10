<?php

namespace WisdomIT\Concierge\Jobs;

use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use WisdomIT\Concierge\Models\ConciergeInstallCheck;
use WisdomIT\Concierge\Models\ConciergeSettings;
use WisdomIT\Concierge\Services\InstallLogAuditor;

/**
 * 설치가 끝나면 **에이전트가 알아서** 로그를 읽고 판정한다 (#7).
 *
 * ⚠ **사용자가 물어볼 때까지 기다리면 안 된다.** 설치는 몇 분 걸리고 그동안 사용자는 다른
 *   화면을 보고 있다. 실패를 알아채는 시점이 "다음에 말을 걸었을 때"면 며칠 뒤일 수도 있다.
 *   그래서 이 작업이 먼저 판정하고, 사이드바가 그 결과를 집어 사용자에게 말을 건다.
 *
 * ⚠ **판정만 한다. 재설치는 하지 않는다.** 자동 재설치를 만들었다가 정상 서버(276MB)를
 *   미달로 보고 다시 깐 적이 있다 — 기계가 틀렸을 때 비용이 크다. 근거를 보여주고
 *   사용자가 카드에서 누른다.
 */
class VerifyInstall implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * 설치 완료 직후 로그가 아직 다 안 실렸을 수 있어 조금 기다린다.
     * 용량 때와 달리 긴 대기가 필요하지는 않다 — 로그는 즉시 남는다.
     */
    public const DELAY_SECONDS = 20;

    public function __construct(public readonly int $serverId) {}

    public function handle(): void
    {
        $server = Server::find($this->serverId);

        // 판정을 기다리는 동안 지워졌을 수 있다.
        if ($server === null) {
            return;
        }

        $settings = ConciergeSettings::current();

        // 에이전트가 꺼져 있거나 키가 없으면 판정할 수단이 없다. 조용히 넘어간다.
        if (!$settings->isUsable()) {
            return;
        }

        $verdict = (new InstallLogAuditor($settings))->audit($server);
        $record = ConciergeInstallCheck::firstOrNew(['server_id' => $server->id]);

        // 재설치 뒤 다시 판정하는 경우다 — 이전 알림은 이미 지나갔으므로 초기화한다.
        $record->notified_at = null;

        if ($verdict === null) {
            // 로그를 못 읽었거나 모델이 답을 못 줬다. **실패로 단정하지 않는다.**
            $record->status = ConciergeInstallCheck::STATUS_UNKNOWN;
            $record->reason = null;
            $record->save();

            return;
        }

        $record->status = $verdict['ok']
            ? ConciergeInstallCheck::STATUS_OK
            : ConciergeInstallCheck::STATUS_FAILED;
        $record->reason = $verdict['reason'];
        $record->save();

        if (!$verdict['ok']) {
            Log::warning('concierge: 설치가 실패한 것으로 판정', [
                'server' => $server->uuid_short,
                'reason' => $verdict['reason'],
            ]);
        }
    }
}
