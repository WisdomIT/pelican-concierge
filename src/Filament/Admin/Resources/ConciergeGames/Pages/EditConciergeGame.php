<?php

namespace WisdomIT\Concierge\Filament\Admin\Resources\ConciergeGames\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use WisdomIT\Concierge\Catalog\GameCatalog;
use WisdomIT\Concierge\Filament\Admin\Resources\ConciergeGames\ConciergeGameResource;
use WisdomIT\Concierge\Filament\Admin\Resources\ConciergeGames\HandlesAdvancedYaml;

class EditConciergeGame extends EditRecord
{
    use HandlesAdvancedYaml;

    protected static string $resource = ConciergeGameResource::class;

    /** @return array<\Filament\Actions\Action> */
    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->foldAdvancedYaml($data);
    }

    protected function afterSave(): void
    {
        GameCatalog::forget();
    }
}
