<?php

namespace WisdomIT\Concierge\Filament\Admin\Resources\ConciergeGames\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use WisdomIT\Concierge\Filament\Admin\Resources\ConciergeGames\ConciergeGameResource;

class ListConciergeGames extends ListRecords
{
    protected static string $resource = ConciergeGameResource::class;

    /** @return array<\Filament\Actions\Action> */
    protected function getHeaderActions(): array
    {
        return [
            // 대화로 항목을 만든다 (#93 의 첫 사용처 · 도구는 #91).
            //
            // egg 변수를 읽고, 무엇을 물을지 고르고, 자원을 잡는 일은 폼보다 대화가 낫다 —
            // 이 버튼은 그 대화를 **이미 그 일을 향해** 열어 준다. 프리셋 키만 넘기고
            // 문장은 서버가 만든다(ChatPresets) — 화면이 에이전트에게 아무 말이나 시킬 수
            // 있으면 그 문장이 사용자 발화로 기록된다.
            Action::make('ask_agent')
                ->label(trans('concierge::strings.catalog_ask_agent'))
                ->icon('tabler-message-chatbot')
                ->color('gray')
                ->button()
                ->visible(fn () => ConciergeGameResource::canCreate())
                // ⚠ extraAttributes 의 x-on:click 은 이 액션 버튼에 붙지 않았다(실측: 렌더된
                //   마크업에 없고 wire:click 만 남았다). Livewire dispatch 는 브라우저
                //   이벤트로도 나가므로 사이드바의 x-on:cg-start.window 가 그대로 받는다.
                ->action(fn (ListConciergeGames $livewire) => $livewire->dispatch('cg-start', preset: 'catalog_new')),

            CreateAction::make(),
        ];
    }
}
