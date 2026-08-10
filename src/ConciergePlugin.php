<?php

namespace WisdomIT\Concierge;

use App\Contracts\Plugins\HasPluginSettings;
use Filament\Contracts\Plugin;
use App\Enums\PluginStatus;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Throwable;
use WisdomIT\Concierge\Models\ConciergeSettings;
use WisdomIT\Concierge\Support\OptionalPlugins;

/**
 * 설정은 자체 관리자 페이지가 아니라 **플러그인 목록의 Settings 버튼**에 붙는다(#1).
 * 사용자가 플러그인 설정을 찾는 자리가 거기고, HasPluginSettings 가 그 용도다.
 *
 * ⚠ 접근 제어가 바뀐다: 예전 페이지는 `update wisdomAgent` 권한으로 가렸지만, 플러그인
 *   목록의 Settings 는 패널의 플러그인 관리 권한을 따른다 — UCS·Player Counter 와 같다.
 */
class ConciergePlugin implements Plugin, HasPluginSettings
{
    public function getId(): string
    {
        return 'concierge';
    }

    public function register(Panel $panel): void
    {
        // plugin.json 의 panels 에 따라 admin·app·server 세 패널에서 호출된다.
        //  admin  → 사용량 리소스
        //  app    → (Filament 화면 없음. 사이드바는 렌더 훅으로 붙는다)
        //  server → (같음. 서버 콘솔에서 물어볼 수 있어야 해서 등록만 해 둔다)
        $id = str($panel->getId())->title();

        // ⚠ Widgets 는 일부러 discover 하지 않는다. discoverWidgets 로 등록하면 관리자
        //   대시보드에도 붙어서 권한과 무관하게 노출된다. 사용량 위젯은 리소스의
        //   getHeaderWidgets() 에서 클래스로 직접 참조하므로 등록이 필요 없다.
        foreach (['Pages' => 'discoverPages', 'Resources' => 'discoverResources'] as $dir => $method) {
            $path = plugin_path($this->getId(), "src/Filament/$id/$dir");

            if (is_dir($path)) {
                $panel->{$method}($path, "WisdomIT\\Concierge\\Filament\\$id\\$dir");
            }
        }
    }

    public function boot(Panel $panel): void {}

    /**
     * ⚠ 세 메서드를 모두 구현하고 **필드마다 default() 도 함께 건다.** beta36 은
     *   getSettingsFormData() 로 폼을 채우지만(panel#2453) 그 아래 버전에는 그 호출이
     *   없다 — default 가 없으면 폼이 비어 뜨고, 저장하면 빈 값이 실제 설정을 덮는다.
     *   secret-variables v0.1.1 에서 실제로 겪었다. beta36 이 하한이 되면 지울 것.
     *
     * @return array<string, mixed>
     */
    public function getSettingsFormData(): array
    {
        try {
            $settings = ConciergeSettings::current();
        } catch (Throwable) {
            // 마이그레이션 전(설치 직후)의 짧은 구간 — 폼이 빈 채로라도 뜨는 편이 낫다.
            return [];
        }

        return [
            // api_key 는 절대 되돌려 채우지 않는다 — 폼 상태는 브라우저로 나가는 값이다.
            'api_key' => '',
            'clear_api_key' => false,
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
        ];
    }

    /**
     * 섹션은 넷 — 연결 / 한도 / 웹 검색 / 유휴 서버(#5).
     * 웹 검색은 토큰과 별도로 과금되는 유일한 항목이라 제 섹션을 갖고, 설명에 비용을 적는다.
     * (켜기/끄기 토글은 없다(#2) — 플러그인 비활성화가 그 역할이다.)
     *
     * @return Component[]
     */
    public function getSettingsForm(): array
    {
        $hasApiKey = false;

        try {
            $hasApiKey = ConciergeSettings::current()->isConfigured();
        } catch (Throwable) {
            // 위와 같은 구간. "미설정"으로 보이면 충분하다.
        }

        return [
            Section::make(trans('concierge::strings.section_connection'))
                ->columns(2)
                ->schema([
                    TextInput::make('api_key')
                        ->label(trans('concierge::strings.field_api_key'))
                        ->password()
                        ->revealable()
                        ->autocomplete(false)
                        ->default('')
                        ->placeholder($hasApiKey
                            ? trans('concierge::strings.api_key_set')
                            : trans('concierge::strings.api_key_unset'))
                        ->helperText(trans('concierge::strings.help_api_key'))
                        ->columnSpanFull(),

                    // 전용 페이지 시절의 "키 삭제" 버튼을 대신한다 — 플러그인 설정 모달에는
                    // 임의 액션 버튼을 놓을 자리가 없어 체크박스로 받는다.
                    Checkbox::make('clear_api_key')
                        ->label(trans('concierge::strings.field_clear_api_key'))
                        ->default(false)
                        ->visible($hasApiKey)
                        ->columnSpanFull(),

                    Select::make('model')
                        ->label(trans('concierge::strings.field_model'))
                        ->options(config('concierge.available_models'))
                        ->helperText(trans('concierge::strings.help_model'))
                        ->native(false)
                        ->default(fn () => ConciergeSettings::current()->model)
                        ->required(),

                    Select::make('effort')
                        ->label(trans('concierge::strings.field_effort'))
                        ->options(config('concierge.available_efforts'))
                        ->helperText(trans('concierge::strings.help_effort'))
                        ->native(false)
                        ->default(fn () => ConciergeSettings::current()->effort)
                        ->required(),

                    TextInput::make('max_tokens')
                        ->label(trans('concierge::strings.field_max_tokens'))
                        ->helperText(trans('concierge::strings.help_max_tokens'))
                        ->numeric()
                        ->minValue(256)
                        ->maxValue(64000)
                        ->default(fn () => ConciergeSettings::current()->max_tokens)
                        ->required(),

                    TextInput::make('daily_message_limit')
                        ->label(trans('concierge::strings.field_daily_limit'))
                        ->helperText(trans('concierge::strings.help_daily_limit'))
                        ->numeric()
                        ->minValue(0)
                        ->default(fn () => ConciergeSettings::current()->daily_message_limit)
                        ->required(),
                ]),

            Section::make(trans('concierge::strings.section_search'))
                ->description(trans('concierge::strings.section_search_help'))
                ->schema([
                    Toggle::make('search_enabled')
                        ->label(trans('concierge::strings.field_search_enabled'))
                        ->helperText(trans('concierge::strings.help_search_enabled'))
                        ->live()
                        ->inline(false)
                        ->default(fn () => ConciergeSettings::current()->search_enabled),

                    TextInput::make('search_max_uses')
                        ->label(trans('concierge::strings.field_search_max_uses'))
                        ->helperText(trans('concierge::strings.help_search_max_uses'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(10)
                        ->required()
                        ->default(fn () => ConciergeSettings::current()->search_max_uses)
                        ->visible(fn (Get $get) => (bool) $get('search_enabled')),
                ]),

            Section::make(trans('concierge::strings.section_idle'))
                ->description(trans('concierge::strings.section_idle_help'))
                ->schema([
                    Toggle::make('idle_enabled')
                        ->label(trans('concierge::strings.field_idle_enabled'))
                        ->helperText(trans('concierge::strings.help_idle_enabled'))
                        ->live()
                        ->inline(false)
                        ->default(fn () => ConciergeSettings::current()->idle_enabled),

                    TextInput::make('idle_minutes')
                        ->label(trans('concierge::strings.field_idle_minutes'))
                        ->helperText(trans('concierge::strings.help_idle_minutes'))
                        ->numeric()
                        ->minValue(5)
                        ->required()
                        ->default(fn () => ConciergeSettings::current()->idle_minutes)
                        ->visible(fn (Get $get) => (bool) $get('idle_enabled')),

                    Toggle::make('idle_stop_enabled')
                        ->label(trans('concierge::strings.field_idle_stop'))
                        ->helperText(trans('concierge::strings.help_idle_stop'))
                        ->live()
                        ->inline(false)
                        ->default(fn () => ConciergeSettings::current()->idle_stop_enabled)
                        ->visible(fn (Get $get) => (bool) $get('idle_enabled')),

                    TextInput::make('idle_grace_minutes')
                        ->label(trans('concierge::strings.field_idle_grace'))
                        ->helperText(trans('concierge::strings.help_idle_grace'))
                        ->numeric()
                        ->minValue(5)
                        ->required()
                        ->default(fn () => ConciergeSettings::current()->idle_grace_minutes)
                        ->visible(fn (Get $get) => (bool) $get('idle_enabled') && (bool) $get('idle_stop_enabled')),
                ]),

            $this->integrationsSection(),
        ];
    }

    /**
     * 선택 연동 여섯의 상태 (#13). 없으면 해당 기능만 조용히 빠지는데, 그 사실이
     * 어디에도 안 보이면 관리자는 설치 하나로 해결될 일을 영영 모른다.
     *
     * 다섯 상태는 서로 다른 안내가 필요하다 — "설치하세요"는 설치돼 있는데 꺼둔
     * 경우엔 틀린 말이다. 버전은 경고만 하고 막지 않는다(OptionalPlugins 참고).
     */
    private function integrationsSection(): Section
    {
        $names = [
            'player-counter' => 'Player Counter',
            'minecraft-modrinth' => 'Minecraft Modrinth',
            'rust-umod' => 'Rust uMod',
            'user-creatable-servers' => 'User Creatable Servers',
            'factorio-mod-installer' => 'Factorio Mod Installer',
            'secret-variables' => 'Secret Variables',
        ];

        $fields = [];

        foreach ($names as $id => $name) {
            $fields[] = Placeholder::make('integration_' . str_replace('-', '_', $id))
                ->label($name)
                ->content(fn () => $this->integrationState($id))
                ->helperText(trans('concierge::strings.integration_adds_' . str_replace('-', '_', $id)));
        }

        return Section::make(trans('concierge::strings.section_integrations'))
            ->description(trans('concierge::strings.section_integrations_help'))
            ->columns(2)
            ->schema($fields);
    }

    private function integrationState(string $id): string
    {
        $status = OptionalPlugins::status($id);

        if ($status === PluginStatus::Enabled) {
            return OptionalPlugins::belowKnownVersion($id)
                ? trans('concierge::strings.integration_below_known', [
                    'version' => OptionalPlugins::version($id),
                    'known' => OptionalPlugins::KNOWN[$id],
                ])
                : trans('concierge::strings.integration_ok', ['version' => OptionalPlugins::version($id)]);
        }

        return trans(match ($status) {
            PluginStatus::Disabled => 'concierge::strings.integration_disabled',
            PluginStatus::Errored => 'concierge::strings.integration_errored',
            PluginStatus::Incompatible => 'concierge::strings.integration_incompatible',
            default => 'concierge::strings.integration_not_installed',
        });
    }

    /** @param array<mixed, mixed> $data */
    public function saveSettings(array $data): void
    {
        $apiKey = trim((string) ($data['api_key'] ?? ''));
        $clearApiKey = (bool) ($data['clear_api_key'] ?? false);
        unset($data['api_key'], $data['clear_api_key']);

        $settings = ConciergeSettings::current();
        $settings->fill($data);

        // 빈 입력은 "그대로 두기"다 — 다른 설정만 고칠 때 키를 다시 칠 필요가 없어야 한다.
        // 새 키와 삭제가 동시에 오면 새 키가 이긴다(치고 나서 체크박스를 되돌리지 않은 경우).
        if ($apiKey !== '') {
            $settings->api_key = $apiKey;
        } elseif ($clearApiKey) {
            $settings->api_key = null;
        }

        $settings->save();
        ConciergeSettings::forgetCached();

        Notification::make()
            ->title(trans('concierge::strings.saved'))
            ->success()
            ->send();
    }
}
