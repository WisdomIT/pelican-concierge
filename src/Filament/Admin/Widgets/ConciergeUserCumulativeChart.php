<?php

namespace WisdomIT\Concierge\Filament\Admin\Widgets;

use App\Models\User;
use Carbon\Carbon;
use Filament\Widgets\ChartWidget;
use WisdomIT\Concierge\Models\ConciergeUsage;
use WisdomIT\Concierge\Services\UsageLimiter;

/**
 * 사용자별 누적 토큰 (#32) — 최근 30일, 사용자마다 선 하나, 일일 누적(러닝 합).
 *
 * 사용량이 없는 사용자는 선이 없다. 시리즈 색은 **고정 순서**의 검증된
 * 카테고리 팔레트를 쓴다(누적 상위순으로 슬롯 배정) — 8명을 넘으면 나머지를
 * "기타" 한 줄로 접는다: 9번째 색을 지어내는 것보다 읽힌다.
 */
class ConciergeUserCumulativeChart extends ChartWidget
{
    /** 고정 순서 카테고리 팔레트 — CVD 검증 통과 순서 그대로, 자르지도 섞지도 않는다. */
    private const PALETTE = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#4a3aa7', '#e34948'];

    private const OTHERS = '#9ca3af';

    protected ?string $maxHeight = '300px';

    protected int|string|array $columnSpan = 'full';

    public function getHeading(): string
    {
        return trans('concierge::strings.chart_user_cumulative');
    }

    /** @return array<string, mixed> */
    protected function getData(): array
    {
        $tz = UsageLimiter::timezone();
        $start = Carbon::today($tz)->subDays(29);

        /** @var array<int, array<string, int>> $perUser user_id → [일 → 토큰] */
        $perUser = [];

        ConciergeUsage::query()
            ->where('created_at', '>=', $start->copy()->utc())
            ->get(['user_id', 'created_at', 'input_tokens', 'output_tokens'])
            ->each(function (ConciergeUsage $row) use ($tz, &$perUser) {
                $day = $row->created_at->copy()->timezone($tz)->format('Y-m-d');
                $perUser[$row->user_id][$day] = ($perUser[$row->user_id][$day] ?? 0)
                    + $row->input_tokens + $row->output_tokens;
            });

        // 누적 상위순 — 팔레트 슬롯도 이 순서로 배정된다.
        uasort($perUser, fn (array $a, array $b) => array_sum($b) <=> array_sum($a));

        $top = array_slice($perUser, 0, count(self::PALETTE), preserve_keys: true);
        $rest = array_slice($perUser, count(self::PALETTE), preserve_keys: true);

        $names = User::query()->whereIn('id', array_keys($top))->pluck('username', 'id');

        $days = [];
        $labels = [];

        for ($i = 0; $i < 30; $i++) {
            $day = $start->copy()->addDays($i);
            $days[] = $day->format('Y-m-d');
            $labels[] = $day->format('m-d');
        }

        $cumulative = function (array $daily) use ($days): array {
            $sum = 0;

            return array_map(function (string $day) use ($daily, &$sum) {
                return $sum += $daily[$day] ?? 0;
            }, $days);
        };

        $datasets = [];
        $slot = 0;

        foreach ($top as $userId => $daily) {
            $datasets[] = [
                'label' => (string) ($names[$userId] ?? "#{$userId}"),
                'data' => $cumulative($daily),
                'borderColor' => self::PALETTE[$slot++],
                'borderWidth' => 2,
                'pointRadius' => 0,
                'tension' => 0.3,
                'fill' => false,
            ];
        }

        if ($rest !== []) {
            $merged = [];

            foreach ($rest as $daily) {
                foreach ($daily as $day => $tokens) {
                    $merged[$day] = ($merged[$day] ?? 0) + $tokens;
                }
            }

            $datasets[] = [
                'label' => trans('concierge::strings.chart_others'),
                'data' => $cumulative($merged),
                'borderColor' => self::OTHERS,
                'borderWidth' => 2,
                'pointRadius' => 0,
                'tension' => 0.3,
                'fill' => false,
            ];
        }

        return ['datasets' => $datasets, 'labels' => $labels];
    }

    protected function getType(): string
    {
        return 'line';
    }

    /** @return array<string, mixed> */
    protected function getOptions(): array
    {
        return [
            // 여러 시리즈 — 범례가 곧 신원이다(단일 시리즈 도표와 달리 끄지 않는다).
            'plugins' => ['legend' => ['display' => true, 'position' => 'bottom']],
            'scales' => ['y' => ['beginAtZero' => true]],
        ];
    }
}
