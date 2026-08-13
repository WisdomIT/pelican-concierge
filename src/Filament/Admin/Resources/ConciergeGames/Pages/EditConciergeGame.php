<?php

namespace WisdomIT\Concierge\Filament\Admin\Resources\ConciergeGames\Pages;

use Filament\Actions\Action;
use Filament\Support\Enums\Alignment;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use WisdomIT\Concierge\Catalog\GameCatalog;
use WisdomIT\Concierge\Filament\Admin\Resources\ConciergeGames\ConciergeGameResource;
use WisdomIT\Concierge\Filament\Admin\Resources\ConciergeGames\HandlesAdvancedYaml;

class EditConciergeGame extends EditRecord
{
    use HandlesAdvancedYaml;

    protected static string $resource = ConciergeGameResource::class;

    /**
     * ⚠ 삭제는 위, 저장·취소는 아래로 갈라져 있으면 "이 화면의 동작"이 두 군데가 된다.
     *   셋을 한 줄에 모은다.
     *
     * 저장은 **폼 안에** 있어야 한다(type=submit) — 그래서 헤더로 올리지 않고
     * 삭제를 아래로 내린다.
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }

    /** @return array<Action> */
    protected function getFormActions(): array
    {
        return [
            // 이 패널의 기본 폼 액션은 아이콘 버튼이라 라벨이 없고, 아이콘까지 비면
            // 투명한 자리만 남는다(실측). 라벨을 붙인 일반 버튼으로 그린다.
            $this->getSaveFormAction()
                ->button()
                ->label(trans('filament-panels::resources/pages/edit-record.form.actions.save.label')),

            $this->getCancelFormAction()
                ->button()
                ->color('gray')
                ->label(trans('filament-panels::resources/pages/edit-record.form.actions.cancel.label')),

            // 이 패널은 삭제 액션에 hiddenLabel 을 기본으로 건다 — 저장·취소는 글자인데
            // 삭제만 아이콘이면 같은 줄에서 종류가 달라 보인다. 라벨을 되살린다.
            DeleteAction::make()->button()->hiddenLabel(false),
        ];
    }

    /** 폼 버튼은 오른쪽 — 페이지 흐름의 끝에 둔다. */
    public function getFormActionsAlignment(): string | Alignment
    {
        return Alignment::End;
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
