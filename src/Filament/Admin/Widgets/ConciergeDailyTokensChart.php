<?php

namespace WisdomIT\Concierge\Filament\Admin\Widgets;

/** 일별 토큰 (#32) — 선. 패널의 노드 도표와 같은 채움 스타일. */
class ConciergeDailyTokensChart extends ConciergeDailyChart
{
    public function getHeading(): string
    {
        return trans('concierge::strings.chart_daily_tokens');
    }

    /** @return array<string, mixed> */
    protected function getData(): array
    {
        $daily = $this->daily();

        return [
            'datasets' => [[
                'data' => $daily['tokens'],
                'borderColor' => 'rgb(96, 165, 250)',
                'backgroundColor' => 'rgba(96, 165, 250, 0.3)',
                'borderWidth' => 2,
                'pointRadius' => 0,
                'tension' => 0.3,
                'fill' => true,
            ]],
            'labels' => $daily['labels'],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
