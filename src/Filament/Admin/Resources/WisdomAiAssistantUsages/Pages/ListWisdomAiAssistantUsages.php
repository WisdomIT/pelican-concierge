<?php

namespace WisdomIT\WisdomAiAssistant\Filament\Admin\Resources\WisdomAiAssistantUsages\Pages;

use Filament\Resources\Pages\ListRecords;
use WisdomIT\WisdomAiAssistant\Filament\Admin\Resources\WisdomAiAssistantUsages\WisdomAiAssistantUsageResource;
use WisdomIT\WisdomAiAssistant\Filament\Admin\Widgets\WisdomAiAssistantUsageOverview;

class ListWisdomAiAssistantUsages extends ListRecords
{
    protected static string $resource = WisdomAiAssistantUsageResource::class;

    /** @return array<class-string> */
    protected function getHeaderWidgets(): array
    {
        return [
            WisdomAiAssistantUsageOverview::class,
        ];
    }
}
