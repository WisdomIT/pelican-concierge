<?php

namespace WisdomIT\Concierge\Filament\Server\Widgets;

use App\Filament\Server\Components\SmallStatBlock;
use App\Models\Server;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use WisdomIT\Concierge\Services\PlayerCount;

/**
 * 콘솔 위 접속자 수 위젯 (#53).
 *
 * Player Counter 의 위젯은 이 배포에서 전 게임 비활성이다 — canRunQuery 가 할당 IP(0.0.0.0)
 * 를 거부한다. 이 위젯은 같은 자리에 같은 방식(공식 registerCustomWidgets API)으로 붙되,
 * 쿼리는 우리 공용 서비스(도커 게이트웨이 경유, 유휴 판정과 동일 코드)를 쓴다.
 * egg↔쿼리 매핑을 관리자가 화면에서 설정할 필요도 없다 — 카탈로그 선언을 재사용한다.
 */
class PlayerCountWidget extends StatsOverviewWidget
{
    /**
     * ⚠ 30초로 뒀더니 "동적으로 안 바뀐다"는 피드백을 받았다 — 옆의 CPU 위젯이 1초라
     *   상대적으로 멈춘 것처럼 보인다. 쿼리 비용(패킷 1개)은 미미하고, 이 쿼리의 rx 는
     *   유휴 판정이 보지 않으므로(접속자 수 우선 게임) 짧게 잡아도 안전하다.
     */
    /** 상태 표·메인 상단 카드와 같은 인상이 되게 한 줄을 통째로 쓴다(#61 요청). */
    protected int | string | array $columnSpan = 'full';

    protected ?string $pollingInterval = '5s';

    public static function canView(): bool
    {
        /** @var ?Server $server */
        $server = Filament::getTenant();

        if (!$server instanceof Server || $server->isInConflictState()) {
            return false;
        }

        // 쿼리를 선언한 게임 + 켜져 있을 때만. 꺼진 서버에 "0명"을 띄우면 오해를 부른다.
        return app(PlayerCount::class)->supports($server)
            && !$server->retrieveStatus()->isOffline();
    }

    protected function getStats(): array
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        $details = app(PlayerCount::class)->details($server);

        if (!is_array($details)) {
            // 부팅 직후 등 일시 실패 — 다음 폴링(30초)에 다시 온다.
            return [
                SmallStatBlock::make(trans('concierge::strings.widget_players'), trans('concierge::strings.widget_waiting')),
            ];
        }

        $count = sprintf('%s / %s', $details['current_players'] ?? '?', $details['max_players'] ?? '?');

        $stats = [
            SmallStatBlock::make(trans('concierge::strings.widget_players'), $count),
        ];

        // 닉네임이 오는 게임(마인크래프트 등)만 한 줄 덧붙인다.
        $names = array_column($details['players'] ?? [], 'name');

        if ($names !== []) {
            $stats[] = SmallStatBlock::make(
                trans('concierge::strings.widget_who'),
                implode(', ', array_slice($names, 0, 8)) . (count($names) > 8 ? ' …' : ''),
            );
        }

        return $stats;
    }
}
