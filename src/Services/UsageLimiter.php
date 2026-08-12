<?php

namespace WisdomIT\Concierge\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use WisdomIT\Concierge\Models\ConciergeSettings;
use WisdomIT\Concierge\Models\ConciergeUsage;

/**
 * 사용 한도 판정 (#4) — 규칙 목록을 순서대로 보고 **처음 걸린 것**이 막는다.
 *
 * 규칙 = {metric: messages|tokens, scope: user|panel, period: hour|day|week|month,
 *         amount: int>0}. 빈 목록이면 무제한.
 *
 * 패널 전체 집계는 60초 캐시를 쓴다 — 메시지마다 테이블 전체를 합산하지 않기
 * 위해서다(이슈의 주의사항). 그만큼 한도 언저리에서 최대 1분 늦게 막힐 수 있는데,
 * 운영자 지갑을 지키는 용도로는 충분하다.
 */
final class UsageLimiter
{
    public const METRICS = ['messages', 'tokens'];

    public const SCOPES = ['user', 'panel'];

    public const PERIODS = ['hour', 'day', 'week', 'month'];

    /**
     * 걸린 규칙 + 리셋 시각. null = 통과.
     *
     * @return ?array{metric: string, scope: string, period: string, amount: int, resets_at: CarbonInterface}
     */
    public static function firstHit(ConciergeSettings $settings, int $userId): ?array
    {
        foreach (self::rules($settings) as $rule) {
            if (self::usedIn($rule, $userId) >= $rule['amount']) {
                return $rule + ['resets_at' => self::resetsAt($rule['period'])];
            }
        }

        return null;
    }

    /**
     * 저장된 목록에서 형태가 온전한 규칙만 — 폼과 판정이 같은 것을 본다.
     *
     * @return array<int, array{metric: string, scope: string, period: string, amount: int}>
     */
    public static function rules(ConciergeSettings $settings): array
    {
        return self::sanitize((array) ($settings->usage_limits ?? []));
    }

    /**
     * @param  array<int, mixed>  $rules
     * @return array<int, array{metric: string, scope: string, period: string, amount: int}>
     */
    public static function sanitize(array $rules): array
    {
        return collect($rules)
            ->filter(fn ($rule) => is_array($rule)
                && in_array($rule['metric'] ?? null, self::METRICS, true)
                && in_array($rule['scope'] ?? null, self::SCOPES, true)
                && in_array($rule['period'] ?? null, self::PERIODS, true)
                && (int) ($rule['amount'] ?? 0) > 0)
            ->map(fn (array $rule) => [
                'metric' => (string) $rule['metric'],
                'scope' => (string) $rule['scope'],
                'period' => (string) $rule['period'],
                'amount' => (int) $rule['amount'],
            ])
            ->values()
            ->all();
    }

    /**
     * 사용자에게 보여줄 차단 문구 — 어느 한도인지, 언제 풀리는지 (이슈의 done-when).
     *
     * @param  array{metric: string, scope: string, period: string, amount: int, resets_at: CarbonInterface}  $hit
     */
    public static function message(array $hit): string
    {
        return trans('concierge::strings.limit_hit', [
            'scope' => trans('concierge::strings.limit_hit_scope_' . $hit['scope']),
            'period' => trans('concierge::strings.limit_hit_period_' . $hit['period']),
            'metric' => trans('concierge::strings.limit_hit_metric_' . $hit['metric']),
            'amount' => number_format($hit['amount']),
            'reset' => $hit['resets_at']->format('Y-m-d H:i'),
        ]);
    }

    /**
     * 관리자 화면용 — 이 규칙의 현재 소비량 (패널 규칙이면 캐시를 그대로 탄다).
     *
     * @param  array{metric: string, scope: string, period: string, amount: int}  $rule
     */
    public static function usedIn(array $rule, int $userId): int
    {
        $since = self::windowStart($rule['period']);

        if ($rule['scope'] === 'panel') {
            $key = sprintf('concierge:limit:%s:%s:%d', $rule['metric'], $rule['period'], $since->getTimestamp());

            return (int) Cache::remember($key, 60, fn () => self::aggregate($rule['metric'], $since, null));
        }

        return self::aggregate($rule['metric'], $since, $userId);
    }

    /**
     * 기간 경계의 시간대 — **컨테이너의 TZ**(운영자가 compose 에 정한 서버 기준시).
     *
     * Pelican 은 앱 시간대를 UTC 로 고정하므로(저장은 UTC, 표시는 프로필 시간대)
     * config('app.timezone')는 못 쓴다. TZ 를 기준으로 하면 "오늘"의 초기화가
     * **모든 사용자에게 같은 자정**(예: Asia/Seoul 이면 KST 00:00)이 된다.
     */
    public static function timezone(): string
    {
        return getenv('TZ') ?: config('app.timezone', 'UTC');
    }

    /** 서버 기준시(TZ)의 시각 — 차단 문구·화면의 리셋 표시용. */
    public static function resetsAt(string $period): CarbonInterface
    {
        $tz = self::timezone();

        return match ($period) {
            'hour' => Carbon::now($tz)->addHour()->startOfHour(),
            'week' => Carbon::now($tz)->addWeek()->startOfWeek(),
            'month' => Carbon::now($tz)->addMonthNoOverflow()->startOfMonth(),
            default => Carbon::tomorrow($tz),
        };
    }

    private static function windowStart(string $period): CarbonInterface
    {
        $tz = self::timezone();

        $start = match ($period) {
            'hour' => Carbon::now($tz)->startOfHour(),
            'week' => Carbon::now($tz)->startOfWeek(),
            'month' => Carbon::now($tz)->startOfMonth(),
            default => Carbon::today($tz),
        };

        // ⚠ created_at 은 UTC 로 저장돼 있고, 쿼리 빌더는 Carbon 의 시간대를 변환하지
        //   않고 그대로 포맷한다 — UTC 로 바꿔서 넘겨야 경계가 어긋나지 않는다.
        return $start->utc();
    }

    private static function aggregate(string $metric, CarbonInterface $since, ?int $userId): int
    {
        $query = ConciergeUsage::query()
            ->where('created_at', '>=', $since)
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId));

        if ($metric === 'messages') {
            // 카드 결정(card_resolved)·차단된 시도는 세지 않는다 — 종전 일일 한도와 같은 눈.
            return $query
                ->whereIn('status', [ConciergeUsage::STATUS_OK, ConciergeUsage::STATUS_ERROR, ConciergeUsage::STATUS_AWAITING])
                ->count();
        }

        // 토큰은 상태를 가리지 않는다 — 기록된 토큰은 전부 실제로 지불한 것이다.
        return (int) $query->sum(ConciergeUsage::query()->getModel()->getConnection()->raw('input_tokens + output_tokens'));
    }
}
