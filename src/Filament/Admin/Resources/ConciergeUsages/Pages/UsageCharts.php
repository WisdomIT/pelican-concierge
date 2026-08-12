<?php

namespace WisdomIT\Concierge\Filament\Admin\Resources\ConciergeUsages\Pages;

use Filament\Resources\Pages\Page;
use WisdomIT\Concierge\Filament\Admin\Resources\ConciergeUsages\ConciergeUsageResource;
use WisdomIT\Concierge\Filament\Admin\Widgets\ConciergeDailyMessagesChart;
use WisdomIT\Concierge\Filament\Admin\Widgets\ConciergeDailyTokensChart;
use WisdomIT\Concierge\Filament\Admin\Widgets\ConciergeUserCumulativeChart;
use WisdomIT\Concierge\Filament\Admin\Widgets\UsageTabs;

/**
 * 사용량 도표 (#32) — 내용이 전부 헤더 위젯(탭 + 도표 2장)이다.
 *
 * 도표는 Filament ChartWidget(패널에 내장된 Chart.js)로 그린다 — 플러그인은
 * 패널의 빌드 대상이 아니라 외부 에셋을 못 쓰는데, 이건 패널 자신의 에셋이다.
 */
class UsageCharts extends Page
{
    protected static string $resource = ConciergeUsageResource::class;

    protected string $view = 'concierge::filament.admin.usage-charts';

    public function getTitle(): string
    {
        return trans('concierge::strings.usage_title');
    }

    /** @return array<class-string> */
    protected function getHeaderWidgets(): array
    {
        return [
            UsageTabs::class,
            ConciergeDailyMessagesChart::class,
            ConciergeDailyTokensChart::class,
            ConciergeUserCumulativeChart::class,
        ];
    }
}
