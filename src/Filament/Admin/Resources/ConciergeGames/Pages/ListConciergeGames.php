<?php

namespace WisdomIT\Concierge\Filament\Admin\Resources\ConciergeGames\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use WisdomIT\Concierge\Filament\Admin\Resources\ConciergeGames\ConciergeGameResource;

class ListConciergeGames extends ListRecords
{
    protected static string $resource = ConciergeGameResource::class;

    /** @return array<\Filament\Actions\Action> */
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
