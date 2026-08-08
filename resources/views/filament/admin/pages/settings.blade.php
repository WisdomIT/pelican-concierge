<x-filament-panels::page>
    <form wire:submit="save" class="flex flex-col gap-6">
        {{ $this->form }}

        <div class="flex items-center gap-3">
            <x-filament::button type="submit" icon="tabler-device-floppy">
                {{ trans('wisdom-ai-assistant::strings.save') }}
            </x-filament::button>

            @if ($this->hasApiKey)
                <x-filament::button
                    type="button"
                    color="danger"
                    icon="tabler-key-off"
                    wire:click="clearApiKey"
                    wire:confirm="{{ trans('wisdom-ai-assistant::strings.confirm_clear_key') }}"
                >
                    {{ trans('wisdom-ai-assistant::strings.clear_api_key') }}
                </x-filament::button>
            @endif
        </div>
    </form>
</x-filament-panels::page>
