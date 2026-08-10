<?php

namespace WisdomIT\Concierge\Filament\Admin\Resources\ConciergeUsages\Pages;

use Filament\Resources\Pages\ListRecords;
use WisdomIT\Concierge\Filament\Admin\Resources\ConciergeUsages\ConciergeUsageResource;
use WisdomIT\Concierge\Filament\Admin\Widgets\ConciergeUsageOverview;

class ListConciergeUsages extends ListRecords
{
    protected static string $resource = ConciergeUsageResource::class;

    /** @return array<class-string> */
    protected function getHeaderWidgets(): array
    {
        return [
            ConciergeUsageOverview::class,
        ];
    }
}
