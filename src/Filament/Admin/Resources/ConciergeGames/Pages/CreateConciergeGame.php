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
     * 편집 화면과 같은 규칙 — 라벨 있는 일반 버튼. 이 패널의 기본 폼 액션은 아이콘
     * 버튼이라 아이콘이 비면 투명한 자리만 남는다(실측).
     *
     * @return array<\Filament\Actions\Action>
     */
    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction()
                ->button()
                ->label(trans('filament-panels::resources/pages/create-record.form.actions.create.label')),

            $this->getCancelFormAction()
                ->button()
                ->color('gray')
                ->label(trans('filament-panels::resources/pages/edit-record.form.actions.cancel.label')),
        ];
    }

    /** 폼 버튼은 오른쪽 — 편집 화면과 같은 규칙. */
    public function getFormActionsAlignment(): string | \Filament\Support\Enums\Alignment
    {
        return \Filament\Support\Enums\Alignment::End;
    }

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
