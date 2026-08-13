<?php

namespace WisdomIT\Concierge\Filament\Admin\Resources\ConciergeGames\Pages;

use Filament\Resources\Pages\CreateRecord;
use WisdomIT\Concierge\Catalog\GameCatalog;
use WisdomIT\Concierge\Filament\Admin\Resources\ConciergeGames\ConciergeGameResource;
use WisdomIT\Concierge\Filament\Admin\Resources\ConciergeGames\HandlesAdvancedYaml;

class CreateConciergeGame extends CreateRecord
{
    use HandlesAdvancedYaml;

    protected static string $resource = ConciergeGameResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->foldAdvancedYaml($data);
    }

    protected function afterCreate(): void
    {
        GameCatalog::forget();
    }
}
