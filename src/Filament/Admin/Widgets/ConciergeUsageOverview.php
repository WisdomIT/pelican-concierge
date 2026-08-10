<?php

namespace WisdomIT\Concierge\Filament\Admin\Widgets;

use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use WisdomIT\Concierge\Models\ConciergeSettings;
use WisdomIT\Concierge\Models\ConciergeUsage;

class ConciergeUsageOverview extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $settings = ConciergeSettings::current();

        $today = ConciergeUsage::query()->where('created_at', '>=', Carbon::today());
        $month = ConciergeUsage::query()->where('created_at', '>=', Carbon::now()->startOfMonth());

        $limit = $settings->daily_message_limit;

        return [
            Stat::make(
                trans('concierge::strings.stat_today_messages'),
                (string) (clone $today)->count(),
            )->description($limit > 0
                ? trans('concierge::strings.stat_limit_per_user', ['limit' => $limit])
                : trans('concierge::strings.stat_no_limit'))
                ->icon('tabler-message-chatbot'),

            Stat::make(
                trans('concierge::strings.stat_today_users'),
                (string) (clone $today)->distinct('user_id')->count('user_id'),
            )->icon('tabler-users'),

            Stat::make(
                trans('concierge::strings.stat_month_tokens'),
                number_format((int) (clone $month)->sum('input_tokens') + (int) (clone $month)->sum('output_tokens')),
            )->description(trans('concierge::strings.stat_month_tokens_hint'))
                ->icon('tabler-coin'),
        ];
    }
}
