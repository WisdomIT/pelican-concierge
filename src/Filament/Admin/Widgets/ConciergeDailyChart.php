<?php

namespace WisdomIT\Concierge\Filament\Admin\Widgets;

use Carbon\Carbon;
use Filament\Widgets\ChartWidget;
use WisdomIT\Concierge\Models\ConciergeUsage;
use WisdomIT\Concierge\Services\UsageLimiter;

/**
 * 일별 사용량 도표의 공통 재료 (#32) — 최근 30일, 서버 기준시(TZ) 자정 버킷.
 *
 * 버킷팅은 PHP 에서 한다: SQL 의 날짜 함수는 드라이버마다 달라서(SQLite 의
 * date(…, '+N seconds') 는 MySQL 에 없다) 30일치 행을 3개 컬럼만 가져와 접는
 * 쪽이 이식성 있다. 두 도표가 같은 재료를 쓰므로 요청당 한 번만 접는다.
 */
abstract class ConciergeDailyChart extends ChartWidget
{
    protected ?string $maxHeight = '300px';

    /** @var ?array{labels: array<int, string>, messages: array<int, int>, tokens: array<int, int>} */
    private static ?array $daily = null;

    /** @return array{labels: array<int, string>, messages: array<int, int>, tokens: array<int, int>} */
    protected function daily(): array
    {
        if (self::$daily !== null) {
            return self::$daily;
        }

        $tz = UsageLimiter::timezone();
        $start = Carbon::today($tz)->subDays(29);

        $messages = [];
        $tokens = [];

        ConciergeUsage::query()
            ->where('created_at', '>=', $start->copy()->utc())
            ->get(['created_at', 'input_tokens', 'output_tokens'])
            ->each(function (ConciergeUsage $row) use ($tz, &$messages, &$tokens) {
                $day = $row->created_at->copy()->timezone($tz)->format('Y-m-d');
                $messages[$day] = ($messages[$day] ?? 0) + 1;
                $tokens[$day] = ($tokens[$day] ?? 0) + $row->input_tokens + $row->output_tokens;
            });

        $daily = ['labels' => [], 'messages' => [], 'tokens' => []];

        for ($i = 0; $i < 30; $i++) {
            $day = $start->copy()->addDays($i);
            $key = $day->format('Y-m-d');
            $daily['labels'][] = $day->format('m-d');
            $daily['messages'][] = $messages[$key] ?? 0;
            $daily['tokens'][] = $tokens[$key] ?? 0;
        }

        return self::$daily = $daily;
    }

    /** @return array<string, mixed> */
    protected function getOptions(): array
    {
        return [
            // 단일 시리즈 — 제목이 곧 범례다.
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['y' => ['beginAtZero' => true]],
        ];
    }
}
