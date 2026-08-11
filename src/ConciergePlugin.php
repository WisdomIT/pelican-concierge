<?php

namespace WisdomIT\Concierge;

use App\Contracts\Plugins\HasPluginSettings;
use Filament\Contracts\Plugin;
use App\Enums\PluginStatus;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Schemas\Components\Actions as FormActions;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\VerticalAlignment;
use Illuminate\Support\HtmlString;
use Throwable;
use WisdomIT\Concierge\Llm\ProviderFactory;
use WisdomIT\Concierge\Llm\ProviderProbe;
use WisdomIT\Concierge\Models\ConciergeSettings;
use WisdomIT\Concierge\Services\UsageLimiter;
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
            'provider' => $settings->provider ?? 'anthropic',
            'key_verified' => '',
            'base_url' => $settings->base_url,
            'model' => $settings->model,
            'model_free' => $settings->model,
            'effort' => $settings->effort,
            'max_tokens' => $settings->max_tokens,
            'limit_metric' => UsageLimiter::rules($settings)[0]['metric'] ?? 'messages',
            'limit_scope' => UsageLimiter::rules($settings)[0]['scope'] ?? 'user',
            'limit_period' => UsageLimiter::rules($settings)[0]['period'] ?? 'day',
            'limit_amount' => UsageLimiter::rules($settings)[0]['amount'] ?? 0,
            'search_enabled' => $settings->search_enabled,
            'search_max_uses' => $settings->search_max_uses,
            'idle_enabled' => $settings->idle_enabled,
            'idle_minutes' => $settings->idle_minutes,
            'idle_stop_enabled' => $settings->idle_stop_enabled,
            'idle_grace_minutes' => $settings->idle_grace_minutes,
            'allow_conversation_delete' => $settings->allow_conversation_delete,
            'sidebar_color_custom' => filled($settings->sidebar_color),
            'sidebar_color' => $settings->sidebar_color,
        ];
    }

    /**
     * 섹션은 넷 — 연결 / 한도 / 웹 검색 / 유휴 서버(#5).
     * 웹 검색은 토큰과 별도로 과금되는 유일한 항목이라 제 섹션을 갖고, 설명에 비용을 적는다.
     * (켜기/끄기 토글은 없다(#2) — 플러그인 비활성화가 그 역할이다.)
     *
     * @return Component[]
     */
    /** @var array<string, array<string, string>> 폼 렌더 한 번에 엔드포인트를 여러 번 찌르지 않게 */
    private static array $localModels = [];

    /**
     * 로컬 엔드포인트의 모델 목록 (#3 후속). 폼 렌더 경로라 요청당 한 번만 조회하고,
     * 실패는 [] — 그러면 자유 입력 필드가 대신 보인다.
     *
     * @return array<string, string>
     */
    private function localModelOptions(Get $get): array
    {
        if ((string) $get('provider') !== 'openai-compatible') {
            return [];
        }

        $baseUrl = (string) ($get('base_url') ?: (ConciergeSettings::current()->base_url ?? ''));

        if ($baseUrl === '') {
            return [];
        }

        return self::$localModels[$baseUrl] ??= ProviderProbe::localModels(
            $baseUrl,
            ConciergeSettings::current()->apiKeyValueFor('openai-compatible'),
        );
    }

    /**
     * "연결 확인" 통과의 지문 (#3 후속). 무엇을 확인했는지(공급자·폼에 친 키·주소)를
     * 담는다 — 확인만 눌러 놓고 값을 바꿔 저장하는 우회를 막는 근거다.
     */
    private static function verifyFingerprint(string $provider, string $typedKey, string $baseUrl): string
    {
        return sha1($provider . '|' . $typedKey . '|' . $baseUrl);
    }

    /** 폼 표시용 — 마이그레이션 전(설치 직후) 구간에도 죽지 않는다. */
    private function hasApiKeyFor(string $provider): bool
    {
        try {
            return ConciergeSettings::current()->hasApiKeyFor($provider);
        } catch (Throwable) {
            return false; // "미설정"으로 보이면 충분하다.
        }
    }

    public function getSettingsForm(): array
    {
        return [
            Section::make(trans('concierge::strings.section_connection'))
                ->columns(2)
                ->schema([
                    // LLM 공급자(#3). 바꿔도 다른 공급자의 키·모델 선택은 스냅샷에 남는다.
                    Select::make('provider')
                        ->label(trans('concierge::strings.field_provider'))
                        ->options(ProviderFactory::options())
                        ->helperText(trans('concierge::strings.help_provider'))
                        ->native(false)
                        ->default(fn () => ConciergeSettings::current()->provider ?? 'anthropic')
                        ->live()
                        // 공급자를 바꾸면 모델·effort 도 그 공급자의 권장값으로 함께 바뀐다 —
                        // 이전 공급자의 모델이 남아 있으면 저장 때까지 잘못된 조합으로 보인다.
                        ->afterStateUpdated(function (Set $set, ?string $state): void {
                            $set('model', (string) config("concierge.providers.{$state}.default_model", ''));
                            $set('model_free', '');
                            $set('effort', (string) (config("concierge.providers.{$state}.default_effort") ?? ''));
                            // 다른 공급자에 대한 확인은 무효다.
                            $set('key_verified', '');
                        })
                        ->required()
                        ->columnSpanFull(),

                    // 로컬 OpenAI 호환 엔드포인트만 주소가 필요하다(capabilities 기준).
                    // 키보다 먼저 — 연결 대상(주소)을 정한 뒤 자격(키)을 묻는 순서가 자연스럽다.
                    TextInput::make('base_url')
                        ->label(trans('concierge::strings.field_base_url'))
                        ->helperText(trans('concierge::strings.help_base_url'))
                        ->placeholder('http://localhost:11434/v1')
                        ->url()
                        // 주소를 치고 벗어나면 아래 모델 드롭다운이 그 엔드포인트의 목록으로 채워진다.
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Set $set) => $set('key_verified', '')) // 다른 주소에 대한 확인은 무효
                        ->default(fn () => ConciergeSettings::current()->base_url)
                        ->visible(fn (Get $get) => ProviderFactory::capabilitiesOf((string) $get('provider'))->needsBaseUrl)
                        ->columnSpanFull(),

                    // 키 입력 + 우측 "연결 확인" 버튼. 아이콘 suffix 는 아무도 용도를 모른다 —
                    // 라벨 있는 버튼으로 뺀다. 확인이 통과하면 지문(key_verified)이 찍히고,
                    // 새 키·키 없는 공급자 전환은 그 지문 없이는 저장되지 않는다(아래 saveSettings).
                    Flex::make([
                        TextInput::make('api_key')
                            // 키 라벨은 선택된 공급자를 따른다 — 전부 "Anthropic API 키"면 오해를 부른다.
                            ->label(function (Get $get) {
                                $short = (string) config('concierge.providers.' . $get('provider') . '.short', '');

                                return $short !== ''
                                    ? trans('concierge::strings.field_api_key_for', ['provider' => $short])
                                    : trans('concierge::strings.field_api_key_generic');
                            })
                            ->password()
                            ->revealable()
                            ->autocomplete(false)
                            ->default('')
                            // 키를 새로 치면 이전 확인은 무효다 — 지문을 지워 재확인을 강제한다.
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set) => $set('key_verified', ''))
                            // "저장돼 있음" 표시는 **선택된 공급자**의 키를 본다 — 활성 키 하나만
                            // 보면 Claude 키가 있을 때 다른 공급자에도 저장됨이 떠서 오해를 부른다.
                            ->placeholder(fn (Get $get) => $this->hasApiKeyFor((string) $get('provider'))
                                ? trans('concierge::strings.api_key_set')
                                : trans('concierge::strings.api_key_unset'))
                            ->helperText(trans('concierge::strings.help_api_key')),

                        FormActions::make([
                            // GET /models — 토큰을 안 쓰는 가장 싼 인증 검사. 폼에 친 키가
                            // 있으면 그걸, 없으면 그 공급자의 저장된 키로 확인한다.
                            Action::make('verify_key')
                                ->label(trans('concierge::strings.verify_key'))
                                // 명시하지 않으면 아이콘 버튼으로 렌더된다 — 아이콘이 없어
                                // 투명한 클릭 영역만 남으므로 라벨 버튼을 강제한다.
                                ->button()
                                ->color('gray')
                                ->action(function (Get $get, Set $set): void {
                                    $provider = (string) $get('provider');
                                    $typed = trim((string) $get('api_key'));
                                    $key = $typed !== ''
                                        ? $typed
                                        : ConciergeSettings::current()->apiKeyValueFor($provider);
                                    $baseUrl = (string) $get('base_url');

                                    $error = ProviderProbe::verify($provider, $key, $baseUrl);

                                    if ($error === null) {
                                        $set('key_verified', self::verifyFingerprint($provider, $typed, $baseUrl));
                                        Notification::make()->success()->title(trans('concierge::strings.verify_ok'))->send();
                                    } else {
                                        $set('key_verified', '');
                                        Notification::make()->danger()->title(trans('concierge::strings.verify_failed'))->body($error)->send();
                                    }
                                }),
                        ])
                            ->grow(false)
                            ->extraAttributes(['class' => 'cg-verify'])
                            // 하단 정렬은 input 아래 helperText 높이까지 끌려 내려간다 —
                            // 상단 정렬 + label 높이만큼의 빈 라벨로 input 본체와 나란히 맞춘다.
                            // 라벨에 스타일을 함께 싣는다 — 별도 컴포넌트로 넣으면 그리드
                            // 칸이 하나 생겨 간격이 틀어진다. 보정 내용:
                            //  · 빈 라벨과 실제 field 라벨의 4px 높이 차이
                            //  · 로딩 아이콘이 글자보다 커서 버튼 세로가 부푸는 것
                            ->label(new HtmlString(
                                '<style>'
                                . '.cg-verify{margin-top:-4px}'
                                . '.cg-verify .fi-loading-indicator{width:1em;height:1em}'
                                . '</style>&nbsp;'
                            )),
                    ])->verticalAlignment(VerticalAlignment::Start)->columnSpanFull(),

                    // 확인 통과의 지문 — 무엇을(공급자·키·주소) 확인했는지까지 담아,
                    // 확인 후 값을 바꾸는 우회를 막는다.
                    Hidden::make('key_verified')->default(''),

                    // 전용 페이지 시절의 "키 삭제" 버튼을 대신한다 — 플러그인 설정 모달에는
                    // 임의 액션 버튼을 놓을 자리가 없어 체크박스로 받는다.
                    Checkbox::make('clear_api_key')
                        ->label(trans('concierge::strings.field_clear_api_key'))
                        ->default(false)
                        ->visible(fn (Get $get) => $this->hasApiKeyFor((string) $get('provider')))
                        ->columnSpanFull(),

                    // 선택지가 정의된 공급자는 드롭다운으로 —
                    Select::make('model')
                        ->label(trans('concierge::strings.field_model'))
                        ->options(fn (Get $get) => (array) config('concierge.providers.' . $get('provider') . '.models', []))
                        ->helperText(trans('concierge::strings.help_model'))
                        ->native(false)
                        ->default(fn () => ConciergeSettings::current()->model)
                        ->visible(fn (Get $get) => (array) config('concierge.providers.' . $get('provider') . '.models', []) !== [])
                        ->required(fn (Get $get) => (array) config('concierge.providers.' . $get('provider') . '.models', []) !== []),

                    // — 로컬 엔드포인트는 `GET /models` 로 목록을 받아 고른다. 엔드포인트가
                    //   안 닿으면(주소 미입력·서버 꺼짐) 자유 입력으로 물러난다.
                    Select::make('model_free')
                        ->label(trans('concierge::strings.field_model'))
                        ->options(fn (Get $get) => $this->localModelOptions($get))
                        ->helperText(trans('concierge::strings.help_model_local'))
                        ->native(false)
                        ->searchable()
                        ->default(fn () => ConciergeSettings::current()->model)
                        ->visible(fn (Get $get) => (array) config('concierge.providers.' . $get('provider') . '.models', []) === []
                            && $this->localModelOptions($get) !== []),

                    TextInput::make('model_free')
                        ->label(trans('concierge::strings.field_model'))
                        ->helperText(trans('concierge::strings.help_model_free'))
                        ->placeholder('llama3.3:70b')
                        ->default(fn () => ConciergeSettings::current()->model)
                        ->visible(fn (Get $get) => (array) config('concierge.providers.' . $get('provider') . '.models', []) === []
                            && $this->localModelOptions($get) === []),

                    Select::make('effort')
                        ->label(trans('concierge::strings.field_effort'))
                        ->options(fn (Get $get) => (array) config('concierge.providers.' . $get('provider') . '.efforts', []))
                        ->helperText(trans('concierge::strings.help_effort'))
                        ->native(false)
                        ->default(fn () => ConciergeSettings::current()->effort)
                        ->visible(fn (Get $get) => ProviderFactory::capabilitiesOf((string) $get('provider'))->supportsEffort),

                    TextInput::make('max_tokens')
                        ->label(trans('concierge::strings.field_max_tokens'))
                        ->helperText(trans('concierge::strings.help_max_tokens'))
                        ->numeric()
                        ->minValue(256)
                        ->maxValue(64000)
                        ->default(fn () => ConciergeSettings::current()->max_tokens)
                        ->required(),

                ]),

            // 사용 한도(#4) — 규칙 하나: 기준(메시지·토큰) × 범위 × 주기 × 한도량.
            // 저장 형식(usage_limits 목록)과 판정(UsageLimiter)은 목록 그대로 두고,
            // 화면은 0~1개만 쓴다. 한도량 0 = 무제한(빈 목록).
            Section::make(trans('concierge::strings.section_limits'))
                ->description(trans('concierge::strings.section_limits_help'))
                ->columns(4)
                ->schema([
                    Select::make('limit_metric')
                        ->label(trans('concierge::strings.limit_metric'))
                        ->options([
                            'messages' => trans('concierge::strings.limit_metric_messages'),
                            'tokens' => trans('concierge::strings.limit_metric_tokens'),
                        ])
                        ->default(fn () => UsageLimiter::rules(ConciergeSettings::current())[0]['metric'] ?? 'messages')
                        ->native(false)
                        ->required(),

                    Select::make('limit_scope')
                        ->label(trans('concierge::strings.limit_scope'))
                        ->options([
                            'user' => trans('concierge::strings.limit_scope_user'),
                            'panel' => trans('concierge::strings.limit_scope_panel'),
                        ])
                        ->default(fn () => UsageLimiter::rules(ConciergeSettings::current())[0]['scope'] ?? 'user')
                        ->native(false)
                        ->required(),

                    Select::make('limit_period')
                        ->label(trans('concierge::strings.limit_period'))
                        ->options([
                            'hour' => trans('concierge::strings.limit_period_hour'),
                            'day' => trans('concierge::strings.limit_period_day'),
                            'week' => trans('concierge::strings.limit_period_week'),
                            'month' => trans('concierge::strings.limit_period_month'),
                        ])
                        ->default(fn () => UsageLimiter::rules(ConciergeSettings::current())[0]['period'] ?? 'day')
                        ->native(false)
                        ->required(),

                    TextInput::make('limit_amount')
                        ->label(trans('concierge::strings.limit_amount'))
                        ->helperText(trans('concierge::strings.help_limit_amount'))
                        ->numeric()
                        ->minValue(0)
                        ->default(fn () => UsageLimiter::rules(ConciergeSettings::current())[0]['amount'] ?? 0)
                        ->required(),
                ]),

            // 대화 정책(#8) — 연결 설정과 성격이 달라 제 그룹을 갖는다.
            Section::make(trans('concierge::strings.section_conversations'))
                ->description(trans('concierge::strings.section_conversations_help'))
                ->schema([
                    // 기본 꺼짐(#8) — 삭제는 soft 라 관리자 기록은 남지만,
                    // 사용자에게 지우기를 줄지 자체가 운영자의 결정이다.
                    Toggle::make('allow_conversation_delete')
                        ->label(trans('concierge::strings.field_allow_conversation_delete'))
                        ->helperText(trans('concierge::strings.help_allow_conversation_delete'))
                        ->default(fn () => ConciergeSettings::current()->allow_conversation_delete),
                ]),

            // 모양(#10) — 기본은 패널의 primary 를 그대로 따른다(오버라이드 없음).
            // 에이전트를 패널과 구분되는 물건으로 표시하고 싶은 운영자만 색을 고른다.
            Section::make(trans('concierge::strings.section_appearance'))
                ->description(trans('concierge::strings.section_appearance_help'))
                ->columns(2)
                ->schema([
                    Toggle::make('sidebar_color_custom')
                        ->label(trans('concierge::strings.field_sidebar_color_custom'))
                        ->default(fn () => filled(ConciergeSettings::current()->sidebar_color))
                        ->live(),

                    ColorPicker::make('sidebar_color')
                        ->label(trans('concierge::strings.field_sidebar_color'))
                        ->helperText(trans('concierge::strings.help_sidebar_color'))
                        ->default(fn () => ConciergeSettings::current()->sidebar_color)
                        ->visible(fn (Get $get) => (bool) $get('sidebar_color_custom'))
                        ->requiredIf('sidebar_color_custom', true),
                ]),

            Section::make(trans('concierge::strings.section_search'))
                ->description(trans('concierge::strings.section_search_help'))
                ->schema([
                    Toggle::make('search_enabled')
                        ->label(trans('concierge::strings.field_search_enabled'))
                        // 공급자가 검색을 지원하지 않으면(로컬 등) 켤 수 없고, 이유가 보인다(#3).
                        ->helperText(fn (Get $get) => ProviderFactory::capabilitiesOf((string) $get('provider'))->supportsWebSearch
                            ? trans('concierge::strings.help_search_enabled')
                            : trans('concierge::strings.search_unsupported'))
                        ->disabled(fn (Get $get) => !ProviderFactory::capabilitiesOf((string) $get('provider'))->supportsWebSearch)
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

        // ── 공급자 전환 (#3) ──────────────────────────────────────
        $provider = (string) ($data['provider'] ?? $settings->provider ?? 'anthropic');
        unset($data['provider']);

        // ── 연결 확인 게이트 (#3 후속) ────────────────────────────
        // 새 키를 쳤거나, 키가 등록되지 않은 공급자로 바꾸는 저장은 "연결 확인"을
        // 통과한 지문이 있어야 한다 — 틀린 키로 조용히 저장돼 채팅이 죽는 것을 막는다.
        $fingerprint = (string) ($data['key_verified'] ?? '');
        unset($data['key_verified']);

        $providerChanged = $provider !== ($settings->provider ?? 'anthropic');
        $needsVerify = $apiKey !== '' || ($providerChanged && !$settings->hasApiKeyFor($provider));

        if ($needsVerify && $fingerprint !== self::verifyFingerprint($provider, $apiKey, (string) ($data['base_url'] ?? ''))) {
            Notification::make()
                ->danger()
                ->title(trans('concierge::strings.verify_required'))
                ->body(trans('concierge::strings.verify_required_body'))
                ->send();

            return;
        }

        if ($provider !== ($settings->provider ?? 'anthropic')) {
            // 이전 공급자의 키·모델을 스냅샷에 넣고, 새 공급자의 스냅샷(또는 기본값)을
            // 활성 값으로 적재한다 — 아래 fill 이 폼에서 온 값으로 덮는다(폼이 이긴다).
            $settings->switchProvider($provider);
        }

        // 자유 입력 모델(로컬 엔드포인트)은 별도 필드로 받는다 — 선택지형과 하나로 합친다.
        $models = array_keys((array) config("concierge.providers.{$provider}.models", []));

        if ($models === []) {
            $data['model'] = trim((string) ($data['model_free'] ?? ''));
        }

        unset($data['model_free']);

        // 공급자를 바꾼 직후 폼의 모델·effort 가 이전 공급자의 값일 수 있다 —
        // 그 공급자의 선택지에 없는 값은 기본값으로 되돌린다(404 를 설정 화면에서 막는다).
        if ($models !== [] && !in_array($data['model'] ?? '', $models, true)) {
            $data['model'] = (string) config("concierge.providers.{$provider}.default_model", $settings->model);
        }

        $efforts = array_keys((array) config("concierge.providers.{$provider}.efforts", []));

        if ($efforts !== [] && !in_array($data['effort'] ?? '', $efforts, true)) {
            $data['effort'] = (string) (config("concierge.providers.{$provider}.default_effort") ?? $efforts[0]);
        }

        // 커스텀 색(#10): 토글이 꺼져 있으면 "패널을 따른다" = null. 색 값이 남아 있으면
        // 토글을 다시 켰을 때 이전 색이 돌아오는 게 아니라, 꺼짐 = 값 없음으로 둔다.
        if (!($data['sidebar_color_custom'] ?? false)) {
            $data['sidebar_color'] = null;
        }

        unset($data['sidebar_color_custom']);

        // 한도 규칙(#4) — 폼의 4개 필드를 규칙 하나로 접는다. 한도량 0 = 무제한(빈 목록).
        $data['usage_limits'] = UsageLimiter::sanitize([[
            'metric' => $data['limit_metric'] ?? '',
            'scope' => $data['limit_scope'] ?? '',
            'period' => $data['limit_period'] ?? '',
            'amount' => (int) ($data['limit_amount'] ?? 0),
        ]]);
        unset($data['limit_metric'], $data['limit_scope'], $data['limit_period'], $data['limit_amount']);

        $settings->fill($data);

        // 빈 입력은 "그대로 두기"다 — 다른 설정만 고칠 때 키를 다시 칠 필요가 없어야 한다.
        // 새 키와 삭제가 동시에 오면 새 키가 이긴다(치고 나서 체크박스를 되돌리지 않은 경우).
        // 공급자를 바꾼 경우 "그대로"의 기준은 switchProvider 가 적재한 그 공급자의 스냅샷 키다.
        if ($apiKey !== '') {
            $settings->api_key = $apiKey;
        } elseif ($clearApiKey) {
            $settings->api_key = null;
        }

        // 활성 값이 확정됐다 — 현재 공급자의 스냅샷도 같은 값으로 맞춰 둔다.
        $settings->stashProviderSnapshot();

        $settings->save();
        ConciergeSettings::forgetCached();

        Notification::make()
            ->title(trans('concierge::strings.saved'))
            ->success()
            ->send();
    }
}
