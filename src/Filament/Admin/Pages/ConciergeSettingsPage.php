<?php

namespace WisdomIT\Concierge\Filament\Admin\Pages;

use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use WisdomIT\Concierge\Models\ConciergeSettings;

/**
 * @property Schema $form
 */
class ConciergeSettingsPage extends Page implements HasSchemas
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'tabler-message-chatbot';

    protected static ?string $slug = 'concierge';

    protected string $view = 'concierge::filament.admin.pages.settings';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public bool $hasApiKey = false;

    public static function canAccess(): bool
    {
        return (bool) user()?->can('update wisdomAgent');
    }

    public static function getNavigationGroup(): ?string
    {
        return trans('admin/dashboard.advanced');
    }

    public static function getNavigationLabel(): string
    {
        return trans('concierge::strings.settings_title');
    }

    public function getTitle(): string
    {
        return trans('concierge::strings.settings_title');
    }

    public function mount(): void
    {
        $settings = ConciergeSettings::current();
        $this->hasApiKey = $settings->isConfigured();

        // api_key 는 절대 되돌려 채우지 않는다 — 폼 상태는 브라우저로 나가는 값이다.
        // 비워두고, 저장할 때 값이 들어온 경우에만 교체한다.
        $this->form->fill([
            'enabled' => $settings->enabled,
            'api_key' => '',
            'model' => $settings->model,
            'effort' => $settings->effort,
            'max_tokens' => $settings->max_tokens,
            'daily_message_limit' => $settings->daily_message_limit,
            'search_enabled' => $settings->search_enabled,
            'search_max_uses' => $settings->search_max_uses,
            'idle_enabled' => $settings->idle_enabled,
            'idle_minutes' => $settings->idle_minutes,
            'idle_stop_enabled' => $settings->idle_stop_enabled,
            'idle_grace_minutes' => $settings->idle_grace_minutes,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(trans('concierge::strings.section_general'))
                    ->schema([
                        Toggle::make('enabled')
                            ->label(trans('concierge::strings.field_enabled'))
                            ->helperText(trans('concierge::strings.help_enabled'))
                            ->inline(false),
                    ]),

                Section::make(trans('concierge::strings.section_connection'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('api_key')
                            ->label(trans('concierge::strings.field_api_key'))
                            ->password()
                            ->revealable()
                            ->autocomplete(false)
                            ->placeholder(fn () => $this->hasApiKey
                                ? trans('concierge::strings.api_key_set')
                                : trans('concierge::strings.api_key_unset'))
                            ->helperText(trans('concierge::strings.help_api_key'))
                            ->columnSpanFull(),

                        Select::make('model')
                            ->label(trans('concierge::strings.field_model'))
                            ->options(config('concierge.available_models'))
                            ->helperText(trans('concierge::strings.help_model'))
                            ->native(false)
                            ->required(),

                        Select::make('effort')
                            ->label(trans('concierge::strings.field_effort'))
                            ->options(config('concierge.available_efforts'))
                            ->helperText(trans('concierge::strings.help_effort'))
                            ->native(false)
                            ->required(),

                        TextInput::make('max_tokens')
                            ->label(trans('concierge::strings.field_max_tokens'))
                            ->helperText(trans('concierge::strings.help_max_tokens'))
                            ->numeric()
                            ->minValue(256)
                            ->maxValue(64000)
                            ->required(),
                    ]),

                Section::make(trans('concierge::strings.section_limits'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('daily_message_limit')
                            ->label(trans('concierge::strings.field_daily_limit'))
                            ->helperText(trans('concierge::strings.help_daily_limit'))
                            ->numeric()
                            ->minValue(0)
                            ->required(),
                    ]),

                Section::make(trans('concierge::strings.section_idle'))
                    ->description(trans('concierge::strings.section_idle_help'))
                    ->schema([
                        Toggle::make('search_enabled')
                            ->label(trans('concierge::strings.field_search_enabled'))
                            ->helperText(trans('concierge::strings.help_search_enabled'))
                            ->live()
                            ->inline(false),

                        TextInput::make('search_max_uses')
                            ->label(trans('concierge::strings.field_search_max_uses'))
                            ->helperText(trans('concierge::strings.help_search_max_uses'))
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(10)
                            ->required()
                            ->visible(fn ($get) => (bool) $get('search_enabled')),

                        Toggle::make('idle_enabled')
                            ->label(trans('concierge::strings.field_idle_enabled'))
                            ->helperText(trans('concierge::strings.help_idle_enabled'))
                            ->live()
                            ->inline(false),

                        TextInput::make('idle_minutes')
                            ->label(trans('concierge::strings.field_idle_minutes'))
                            ->helperText(trans('concierge::strings.help_idle_minutes'))
                            ->numeric()
                            ->minValue(5)
                            ->required()
                            ->visible(fn ($get) => (bool) $get('idle_enabled')),

                        Toggle::make('idle_stop_enabled')
                            ->label(trans('concierge::strings.field_idle_stop'))
                            ->helperText(trans('concierge::strings.help_idle_stop'))
                            ->live()
                            ->inline(false)
                            ->visible(fn ($get) => (bool) $get('idle_enabled')),

                        TextInput::make('idle_grace_minutes')
                            ->label(trans('concierge::strings.field_idle_grace'))
                            ->helperText(trans('concierge::strings.help_idle_grace'))
                            ->numeric()
                            ->minValue(5)
                            ->required()
                            ->visible(fn ($get) => (bool) $get('idle_enabled') && (bool) $get('idle_stop_enabled')),
                    ]),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $apiKey = trim((string) ($data['api_key'] ?? ''));
        unset($data['api_key']);

        $settings = ConciergeSettings::current();
        $settings->fill($data);

        // 빈 입력은 "그대로 두기"를 뜻한다. 그래야 다른 설정만 고칠 때 키를 다시 칠 필요가 없다.
        if ($apiKey !== '') {
            $settings->api_key = $apiKey;
        }

        $settings->save();
        ConciergeSettings::forgetCached();

        $this->hasApiKey = $settings->isConfigured();
        $this->data['api_key'] = '';

        Notification::make()
            ->title(trans('concierge::strings.saved'))
            ->success()
            ->send();
    }

    public function clearApiKey(): void
    {
        $settings = ConciergeSettings::current();
        $settings->api_key = null;
        $settings->save();
        ConciergeSettings::forgetCached();

        $this->hasApiKey = false;
        $this->data['api_key'] = '';

        Notification::make()
            ->title(trans('concierge::strings.api_key_cleared'))
            ->warning()
            ->send();
    }
}
