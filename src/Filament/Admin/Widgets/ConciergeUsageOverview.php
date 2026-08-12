<?php

namespace WisdomIT\Concierge\Filament\Admin\Widgets;

use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use WisdomIT\Concierge\Models\ConciergeSettings;
use WisdomIT\Concierge\Models\ConciergeUsage;
use WisdomIT\Concierge\Services\UsageLimiter;

class ConciergeUsageOverview extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $settings = ConciergeSettings::current();

        // 집계 경계는 서버 기준시(TZ) — 한도 판정과 같은 자정을 본다. 저장은 UTC 라 되돌린다.
        $tz = UsageLimiter::timezone();
        $today = ConciergeUsage::query()->where('created_at', '>=', Carbon::today($tz)->utc());
        $month = ConciergeUsage::query()->where('created_at', '>=', Carbon::now($tz)->startOfMonth()->utc());

        $rules = UsageLimiter::rules($settings);

        $stats = [
            Stat::make(
                trans('concierge::strings.stat_today_messages'),
                (string) (clone $today)->count(),
            )->description($rules === []
                ? trans('concierge::strings.stat_no_limit')
                : trans('concierge::strings.stat_limit_summary', [
                    'scope' => trans('concierge::strings.limit_scope_' . $rules[0]['scope']),
                    'period' => trans('concierge::strings.limit_period_' . $rules[0]['period']),
                    'metric' => trans('concierge::strings.limit_metric_' . $rules[0]['metric']),
                    'amount' => number_format($rules[0]['amount']),
                ])),

            Stat::make(
                trans('concierge::strings.stat_today_users'),
                (string) (clone $today)->distinct('user_id')->count('user_id'),
            ),

            Stat::make(
                trans('concierge::strings.stat_month_tokens'),
                number_format((int) (clone $month)->sum('input_tokens') + (int) (clone $month)->sum('output_tokens')),
            )->description(trans('concierge::strings.stat_month_tokens_hint')),
        ];

        // 패널 전체 한도(#4)는 한 사용자가 다 써버릴 수 있다 — 의도된 동작이지만,
        // 관리자는 여기서 그 사실을 봐야 한다. 규칙별 소비량과 리셋 시각을 붙인다.
        foreach ($rules as $rule) {
            if ($rule['scope'] !== 'panel') {
                continue;
            }

            $used = UsageLimiter::usedIn($rule, 0);

            $stats[] = Stat::make(
                trans('concierge::strings.stat_panel_limit', [
                    'period' => trans('concierge::strings.limit_period_' . $rule['period']),
                    'metric' => trans('concierge::strings.limit_metric_' . $rule['metric']),
                ]),
                number_format($used) . ' / ' . number_format($rule['amount']),
            )->description(trans('concierge::strings.stat_limit_resets', [
                'reset' => UsageLimiter::resetsAt($rule['period'])->format('Y-m-d H:i'),
            ]))->color($used >= $rule['amount'] ? 'danger' : ($used >= $rule['amount'] * 0.8 ? 'warning' : 'gray'));
        }

        return $stats;
    }
}
