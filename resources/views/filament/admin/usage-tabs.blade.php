@php
    use WisdomIT\Concierge\Filament\Admin\Resources\ConciergeUsages\ConciergeUsageResource;

    $tabs = [
        ['url' => ConciergeUsageResource::getUrl('index'), 'label' => trans('concierge::strings.usage_tab_log')],
        ['url' => ConciergeUsageResource::getUrl('stats'), 'label' => trans('concierge::strings.usage_tab_stats')],
        ['url' => ConciergeUsageResource::getUrl('charts'), 'label' => trans('concierge::strings.usage_tab_charts')],
    ];
@endphp

<x-filament-widgets::widget>
    <x-filament::tabs>
        @foreach ($tabs as $tab)
            <x-filament::tabs.item tag="a" :href="$tab['url']" :active="url()->current() === $tab['url']">
                {{ $tab['label'] }}
            </x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>
</x-filament-widgets::widget>
