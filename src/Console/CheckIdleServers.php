<?php

namespace WisdomIT\WisdomAiAssistant\Console;

use App\Models\Server;
use App\Repositories\Daemon\DaemonServerRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;
use WisdomIT\WisdomAiAssistant\Catalog\GameCatalog;
use WisdomIT\WisdomAiAssistant\Models\WisdomAiAssistantIdleWatch;
use WisdomIT\WisdomAiAssistant\Services\PlayerCount;
use WisdomIT\WisdomAiAssistant\Models\WisdomAiAssistantSettings;

/**
 * 켜져 있는 서버가 실제로 쓰이고 있는지 주기적으로 본다 (#18).
 *
 * **신호는 `rx_bytes` 증가분이다.** 158표본 실측 결과:
 *
 *   | 게임 | 유휴 rx | 접속 rx | 유휴 CPU | 접속 CPU |
 *   | 테라리아 | 0 | 7,300~34,217 | 6.6% | 32% |
 *   | 새티스팩토리 | 0 | 1,692~94,555 | 1.4% | **1.9%** |
 *
 * 🔴 **CPU 를 쓰면 접속 중인 사람을 쫓아낸다.** 새티스팩토리는 사람이 붙어 있는데도 CPU 가
 *   유휴와 같았다(서버 관리자에서 월드를 고르는 동안). rx 는 같은 표본에서 명확히 갈렸다.
 *   유휴 CPU 기준선도 게임마다 4.7배 차이라 공통 임계값이 성립하지 않는다.
 *
 * ⚠ **쿼리 가능한 게임은 접속자 수를 쓴다.** 마인크래프트는 런처가 서버 목록을 열어두기만 해도
 *   핑을 보내 rx 가 0 이 아니게 된다 — 아무도 안 들어왔는데 "쓰는 중"이 된다.
 */
class CheckIdleServers extends Command
{
    protected $signature = 'wisdom-ai-assistant:check-idle';

    protected $description = '유휴 서버를 찾아 알리고, 설정에 따라 정지한다 (#18)';

    public function handle(): int
    {
        $settings = WisdomAiAssistantSettings::current();

        if (!$settings->idle_enabled) {
            return self::SUCCESS;
        }

        $catalog = new GameCatalog();

        foreach (Server::query()->whereNull('status')->get() as $server) {
            try {
                $this->inspect($server, $settings, $catalog);
            } catch (Throwable $exception) {
                // 서버 하나가 응답하지 않는다고 나머지를 건너뛰면 안 된다.
                report($exception);
            }
        }

        return self::SUCCESS;
    }

    private function inspect(Server $server, WisdomAiAssistantSettings $settings, GameCatalog $catalog): void
    {
        $details = app(DaemonServerRepository::class)->setServer($server)->getDetails();

        // 꺼져 있으면 추적할 것이 없다. 다음에 켜졌을 때 처음부터 센다.
        if (($details['state'] ?? '') !== 'running') {
            WisdomAiAssistantIdleWatch::where('server_id', $server->id)->delete();

            return;
        }

        $watch = WisdomAiAssistantIdleWatch::firstOrCreate(['server_id' => $server->id]);
        $rx = (int) ($details['utilization']['network']['rx_bytes'] ?? 0);
        $players = $this->playerCount($server, $catalog);

        // 🔴 쿼리를 선언해 놓고 실패한 경우 **rx 로 내려가면 안 된다.** 실패한 쿼리도 패킷이라
        //    그 서버의 rx 를 올린다 — 우리가 만든 트래픽 때문에 영영 유휴가 되지 않는다.
        //    판정을 건너뛰고 다음 주기를 기다린다(상태는 그대로 둔다).
        if ($players === false) {
            Log::warning('wisdom-ai-assistant: 접속자 쿼리 실패 — 이번 주기는 판정하지 않는다', [
                'server' => $server->uuid_short,
                'egg' => $server->egg?->name,
            ]);

            return;
        }

        if ($this->isActive($watch, $rx, $players)) {
            $watch->markActive($rx);

            return;
        }

        $watch->markIdle($rx);

        $idle = $watch->idleMinutes();

        if ($watch->notified_at === null) {
            // 알림은 사이드바가 집어간다(undeliveredFor). 여기서는 시각만 찍지 않는다 —
            // 전하기 전에 찍으면 사용자가 못 본 채 유예가 흘러간다.
            return;
        }

        if (!$settings->idle_stop_enabled) {
            return;
        }

        if ((int) $watch->notified_at->diffInMinutes(now()) < $settings->idle_grace_minutes) {
            return;
        }

        Log::info('wisdom-ai-assistant: 유휴 서버 자동 정지', [
            'server' => $server->uuid_short,
            'idle_minutes' => $idle,
        ]);

        app(DaemonServerRepository::class)->setServer($server)->power('stop');
        $watch->delete();
    }

    /**
     * 쓰이고 있는가.
     *
     * ⚠ rx 가 **줄었으면** 활동이 아니라 재시작이다(카운터가 0 부터 다시 센다).
     *   활동으로 처리하면 재시작할 때마다 타이머가 리셋된다.
     */
    private function isActive(WisdomAiAssistantIdleWatch $watch, int $rx, int|false|null $players): bool
    {
        if ($players !== null) {
            return $players > 0;
        }

        if ($watch->last_rx === null || $rx < $watch->last_rx) {
            return false;
        }

        return $rx > $watch->last_rx;
    }

    /**
     * 접속자 수 (#18). 조회 자체는 공용 서비스(#53)에 있다 — 위젯·도구와 같은 코드다.
     *
     * @return int|false|null  접속자 수 / 쿼리 실패 / 쿼리를 선언하지 않은 게임(rx 로 판정)
     *
     * ⚠ 이 쿼리도 그 서버의 rx 를 올린다. 그래서 **접속자 수를 쓰는 게임은 rx 를 보지 않고**,
     *   실패했을 때 rx 로 내려가지도 않는다(호출부 참고).
     */
    private function playerCount(Server $server, GameCatalog $catalog): int|false|null
    {
        return (new PlayerCount($catalog))->for($server);
    }
}
