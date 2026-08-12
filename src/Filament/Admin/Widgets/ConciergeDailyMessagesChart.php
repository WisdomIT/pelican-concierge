<?php

namespace WisdomIT\Concierge\Filament\Admin\Widgets;

/** 일별 메시지 수 (#32) — 막대. 색은 패널의 노드 도표와 같은 파랑. */
class ConciergeDailyMessagesChart extends ConciergeDailyChart
{
    public function getHeading(): string
    {
        return trans('concierge::strings.chart_daily_messages');
    }

    /** @return array<string, mixed> */
    protected function getData(): array
    {
        $daily = $this->daily();

        return [
            'datasets' => [[
                'data' => $daily['messages'],
                'backgroundColor' => 'rgba(96, 165, 250, 0.6)',
                'borderRadius' => 4,
            ]],
            'labels' => $daily['labels'],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
