<?php

namespace WisdomIT\Concierge\Tools;

use Illuminate\Support\Carbon;
use WisdomIT\Concierge\Models\ConciergeSettings;
use WisdomIT\Concierge\Models\ConciergeUsage;
use WisdomIT\Concierge\Services\UsageLimiter;

/**
 * 사용량 통계 (#92) — 관리자가 "얼마나 썼나"를 대화에서 묻기 위한 것.
 *
 * 🔴 **숫자만 돌려준다. 대화 내용은 절대 나가지 않는다.**
 *    concierge_usages 행에는 user_message·assistant_message 가 그대로 들어 있다. 여기서
 *    그걸 내보내면 관리자가 **에이전트를 통해 남의 대화를 읽게** 된다 — 관리 화면에서
 *    의도적으로 여는 것과는 다른 일이다. 그래서 이 클래스는 집계 함수만 쓰고, 메시지
 *    컬럼은 select 조차 하지 않는다. 대화 id 도 내보내지 않는다(그걸로 화면을 찾아갈 수
 *    있어서가 아니라, 통계에 필요 없기 때문이다).
 *
 * ⚠ 집계 경계는 **서버 기준시**다(UsageLimiter::timezone). 한도가 그 자정으로 초기화되고
 *   관리자는 그 숫자를 한도·로그와 맞춰 봐야 한다 — 예약·백업 시각이 요청자의 프로필
 *   시간대를 따르는 것과 일부러 다르다(#79 에서 그은 경계).
 *
 * ⚠ **돈은 계산하지 않는다.** 공급자별 단가를 우리가 들고 있으면 낡는다 — 값이 바뀌어도
 *   조용히 옛 단가로 답하게 되고, 그건 없느니만 못하다. 토큰을 정확히 주고 환산은 단가를
 *   아는 사람에게 맡긴다.
 */
final class UsageStats
{
    /** 사용자별 목록의 상한 — 모델 컨텍스트로 들어가므로 잡아 둔다. */
    private const TOP_USERS = 15;

    /**
     * @return array<string, mixed>
     */
    public static function summary(string $window): array
    {
        $tz = UsageLimiter::timezone();
        $since = self::windowStart($window, $tz);

        $rows = ConciergeUsage::query()
            // 메시지 컬럼은 **고르지도 않는다** — 실수로 새 나갈 자리를 만들지 않는다.
            ->selectRaw('user_id, model, status, input_tokens, output_tokens')
            ->when($since !== null, fn ($q) => $q->where('created_at', '>=', $since->utc()))
            ->get();

        return [
            'window' => [
                'name' => $window,
                'from' => $since?->format('Y-m-d H:i') ?? 'all time',
                'timezone' => $tz,
                'note' => 'Counted on server time, the same boundary quota resets use.',
            ],
            'totals' => self::totals($rows),
            'by_user' => self::byUser($rows, $tz),
            'by_model' => self::byModel($rows),
            'limits' => self::limits(),
            'cost' => 'Not calculated — token counts only. Apply your provider\'s rates.',
        ];
    }

    /** @param \Illuminate\Support\Collection<int, ConciergeUsage> $rows */
    private static function totals($rows): array
    {
        return [
            'messages' => $rows->count(),
            'users' => $rows->pluck('user_id')->unique()->count(),
            'input_tokens' => (int) $rows->sum('input_tokens'),
            'output_tokens' => (int) $rows->sum('output_tokens'),
            // 실패한 턴도 토큰을 쓴다 — 합계에 **포함하되** 따로 보여준다. 빼면 지출을
            // 실제보다 적게 말하게 되고, 안 보여주면 왜 많은지 알 수 없다.
            'failed_messages' => $rows->where('status', 'error')->count(),
        ];
    }

    /** @param \Illuminate\Support\Collection<int, ConciergeUsage> $rows */
    private static function byUser($rows, string $tz): array
    {
        $settings = ConciergeSettings::current();
        $userRules = array_values(array_filter(
            UsageLimiter::rules($settings),
            fn (array $rule) => $rule['scope'] === 'user',
        ));

        return $rows->groupBy('user_id')
            ->map(fn ($group, $userId) => [
                'user' => optional($group->first()->user)->username ?? "#{$userId}",
                'messages' => $group->count(),
                'input_tokens' => (int) $group->sum('input_tokens'),
                'output_tokens' => (int) $group->sum('output_tokens'),
                // "한도에 얼마나 가까운가" — 관리자가 가장 자주 묻는 것이고, 판정과 같은
                // 계산(UsageLimiter)을 써야 답이 어긋나지 않는다.
                'limits' => array_map(fn (array $rule) => [
                    'rule' => self::describeRule($rule),
                    'used' => UsageLimiter::usedIn($rule, (int) $userId),
                    'of' => $rule['amount'],
                ], $userRules),
            ])
            ->sortByDesc(fn (array $row) => $row['input_tokens'] + $row['output_tokens'])
            ->take(self::TOP_USERS)
            ->values()
            ->all();
    }

    /** @param \Illuminate\Support\Collection<int, ConciergeUsage> $rows */
    private static function byModel($rows): array
    {
        return $rows->groupBy('model')
            ->map(fn ($group, $model) => [
                'model' => $model ?: 'unknown',
                'messages' => $group->count(),
                'input_tokens' => (int) $group->sum('input_tokens'),
                'output_tokens' => (int) $group->sum('output_tokens'),
            ])
            ->sortByDesc('messages')
            ->values()
            ->all();
    }

    /** 패널 전체 한도의 소진 상황. 사용자별 한도는 by_user 쪽에 붙는다. */
    private static function limits(): array
    {
        $settings = ConciergeSettings::current();
        $rules = UsageLimiter::rules($settings);

        if ($rules === []) {
            return ['configured' => false, 'note' => 'No usage limit is configured.'];
        }

        return [
            'configured' => true,
            'rules' => array_map(fn (array $rule) => array_filter([
                'rule' => self::describeRule($rule),
                'amount' => $rule['amount'],
                'used' => $rule['scope'] === 'panel' ? UsageLimiter::usedIn($rule, 0) : null,
                'resets_at' => UsageLimiter::resetsAt($rule['period'])->format('Y-m-d H:i'),
            ], fn ($v) => $v !== null), $rules),
        ];
    }

    /** @param array<string, mixed> $rule */
    private static function describeRule(array $rule): string
    {
        return sprintf(
            '%d %s per %s per %s',
            $rule['amount'],
            $rule['metric'],
            $rule['period'],
            $rule['scope'],
        );
    }

    /** 창의 시작. 한도와 같은 경계를 쓴다 — 두 숫자가 어긋나면 어느 쪽도 못 믿는다. */
    private static function windowStart(string $window, string $tz): ?Carbon
    {
        return match ($window) {
            'today' => Carbon::today($tz),
            'week' => Carbon::today($tz)->subDays(6),
            'month' => Carbon::today($tz)->subDays(29),
            default => null,
        };
    }
}
