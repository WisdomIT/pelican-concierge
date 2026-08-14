<?php

namespace WisdomIT\Concierge;

use App\Contracts\Plugins\HasPluginSettings;
use Closure;
use Filament\Contracts\Plugin;
use App\Enums\PluginStatus;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Schemas\Components\Actions as FormActions;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\VerticalAlignment;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Throwable;
use WisdomIT\Concierge\Llm\ProviderFactory;
use WisdomIT\Concierge\Llm\ProviderProbe;
use WisdomIT\Concierge\Models\ConciergePreset;
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

    /** 배포 지식 작성 가이드 — 화면에서 읽는 사람과 저장소에서 읽는 사람이 같은 글을 본다. */
    private static function knowledgeGuideUrl(): string
    {
        $locale = app()->getLocale() === 'ko' ? 'ko' : 'en';

        return "https://github.com/WisdomIT/pelican-concierge/blob/main/docs/deployment-knowledge.{$locale}.md";
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
            // 🔴 키는 절대 되돌려 채우지 않는다 — 폼 상태는 브라우저로 나가는 값이다.
            //    항목마다 빈 칸으로 두고, 저장 때 비어 있으면 저장된 키를 그대로 쓴다(#89).
            'provider_entries' => $this->entryRows(),
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
            'deployment_knowledge' => $settings->deployment_knowledge,
            'presets' => $this->presetRows(),
        ];
    }

    /**
     * 항목 목록을 폼 배열로 (#89).
     *
     * 🔴 **키는 싣지 않는다.** 폼 상태는 브라우저로 나가고 Livewire 왕복마다 오간다 —
     *    저장된 키는 빈 칸으로 두고, 비어 있으면 그대로 둔다는 뜻으로 읽는다.
     *
     * @return array<int, array<string, mixed>>
     */
    private function entryRows(): array
    {
        try {
            return array_map(fn (array $entry) => [
                'id' => (string) ($entry['id'] ?? ''),
                'label' => (string) ($entry['label'] ?? ''),
                'provider' => (string) ($entry['provider'] ?? 'anthropic'),
                'api_key' => '',
                'verified' => '',
                'base_url' => $entry['base_url'] ?? null,
                'model' => (string) ($entry['model'] ?? ''),
                'effort' => (string) ($entry['effort'] ?? ''),
                'max_tokens' => (int) ($entry['max_tokens'] ?? config('concierge.max_tokens')),
            ], ConciergeSettings::current()->entries());
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * 시작점 목록을 폼 배열로 (#103). 실패해도 폼 전체를 죽이지 않는다 —
     * 마이그레이션 전(설치 직후)에는 테이블이 없다.
     *
     * @return array<int, array<string, mixed>>
     */
    private function presetRows(): array
    {
        try {
            return ConciergePreset::query()
                ->orderBy('sort')
                ->orderBy('id')
                ->get()
                ->map(fn (ConciergePreset $preset) => [
                    'preset_key' => $preset->preset_key,
                    'enabled' => $preset->enabled,
                    'label' => $preset->label,
                    'label_translations' => $preset->label_translations ?? [],
                    'prompt' => $preset->prompt,
                    'prompt_translations' => $preset->prompt_translations ?? [],
                    'visibility' => $preset->visibility,
                    'permission' => $preset->permission,
                    'path_pattern' => $preset->path_pattern,
                ])
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * 섹션은 넷 — 연결 / 한도 / 웹 검색 / 유휴 서버(#5).
     * 웹 검색은 토큰과 별도로 과금되는 유일한 항목이라 제 섹션을 갖고, 설명에 비용을 적는다.
     * (켜기/끄기 토글은 없다(#2) — 플러그인 비활성화가 그 역할이다.)
     *
     * @return Component[]
     */
    /**
     * "연결 확인" 통과의 지문 (#3 후속). 무엇을 확인했는지(공급자·폼에 친 키·주소)를
     * 담는다 — 확인만 눌러 놓고 값을 바꿔 저장하는 우회를 막는 근거다.
     */
    private static function verifyFingerprint(string $provider, string $typedKey, string $baseUrl): string
    {
        return sha1($provider . '|' . $typedKey . '|' . $baseUrl);
    }

    /**
     * 설정은 **탭 여섯**이다 (#103).
     *
     * 예전에는 한 장의 긴 스크롤이었다 — 키와 모델로 시작해 기능이 붙을 때마다 섹션이
     * 아래에 쌓였고, 한 번만 정할 것(어느 에이전트에 연결하는가)을 지나야 자주 고칠
     * 것(한도)에 닿았다. 순서는 **결정의 성격**을 따른다:
     *
     *  1. 연결   — 이게 되기 전에는 나머지가 의미 없다. 새 설치가 반드시 손대는 하나.
     *  2. 한도   — 연결이 되고 나면 운영자가 가장 자주 돌아오는 곳.
     *  3. 기능   — 어시스턴트가 무엇을 할 수 있는지 정하는 스위치들.
     *  4. 환경   — 스위치가 아니라 운영자가 쓰는 글. 위가 정해진 뒤에야 쓸 만하다.
     *  5. 시작점 — 같은 성격(운영자가 쓰는 글)이고, 무엇을 권할지는 3·4 가 정해져야 정해진다.
     *  6. 모양   — 동작을 바꾸지 않으므로 마지막.
     *
     * ⚠ **저장은 하나다.** 탭은 화면을 나눌 뿐 제출을 나누지 않는다 — 호스트(PluginResource)가
     *   슬라이드오버 하나에 렌더하고 제출 한 번에 saveSettings() 를 부른다. 탭마다 저장이
     *   갈리면 "어느 탭은 저장됐고 어느 탭은 아닌" 반쯤 설정된 플러그인이 생기는데,
     *   그건 설정하지 않은 것보다 나쁘다.
     *
     * @return Component[]
     */
    public function getSettingsForm(): array
    {
        return [
            Tabs::make('concierge_settings')
                ->columnSpanFull()
                ->tabs([
                    Tab::make(trans('concierge::strings.tab_connection'))
                        ->columns(2)
                        ->schema($this->connectionFields()),

                    Tab::make(trans('concierge::strings.tab_limits'))
                        ->columns(4)
                        ->schema($this->limitFields()),

                    Tab::make(trans('concierge::strings.tab_features'))
                        ->schema($this->featureSections()),

                    Tab::make(trans('concierge::strings.tab_environment'))
                        ->schema($this->environmentFields()),

                    Tab::make(trans('concierge::strings.tab_presets'))
                        ->schema($this->presetFields()),

                    Tab::make(trans('concierge::strings.tab_appearance'))
                        ->columns(2)
                        ->schema($this->appearanceFields()),
                ]),
        ];
    }

    /** @return Component[] */
    private function connectionFields(): array
    {
        return [
            Text::make(trans('concierge::strings.entries_help'))
                ->columnSpanFull(),

            // 확인 버튼의 배치 보정. 항목마다 넣으면 목록 길이만큼 복제되므로 탭에 한 번만 둔다.
            //  · 버튼 쪽 padding 으로 키 칸과 높이를 맞춘다(왼쪽은 0 — 키 칸에 붙어야 한다)
            //  · 로딩 아이콘이 글자보다 커서 버튼 세로가 부푸는 것을 눌러 둔다
            // (둘 사이 간격은 Repeater 의 gap(false) 가 이미 없앤다 — 여기서 또 지우지 않는다.)
            Text::make(new HtmlString(
                '<style>'
                . '.cg-verify{padding:.75rem .75rem .75rem 0}'
                . '.cg-verify .fi-loading-indicator{width:1em;height:1em}'
                . '</style>'
            )),

            Repeater::make('provider_entries')
                ->hiddenLabel()
                ->addActionLabel(trans('concierge::strings.entries_add'))
                ->reorderable()
                ->collapsible()
                ->collapsed()
                ->minItems(1)
                ->itemLabel(fn (array $state): string => self::entryItemLabel($state))
                ->schema($this->entryFields())
                ->columns(2)
                // 칸마다 이미 제 여백이 있다 — 격자 간격까지 더하면 한 항목이 필요 이상으로
                // 길어지고, 접었다 펴는 목록에서 그 길이가 곧 읽기 비용이다.
                ->gap(false)
                ->columnSpanFull(),
        ];
    }

    /**
     * 목록에서 접힌 항목의 한 줄. **첫 항목이 주 공급자**라는 사실이 여기서 보여야 한다 —
     * 순서가 곧 우선순위인데 그 말이 어디에도 없으면 끌어 옮길 이유를 알 수 없다.
     *
     * @param  array<string, mixed>  $state
     */
    private static function entryItemLabel(array $state): string
    {
        $label = trim((string) ($state['label'] ?? ''));

        if ($label === '') {
            $label = ProviderFactory::label((string) ($state['provider'] ?? ''));
        }

        return $label . (filled($state['model'] ?? null) ? ' · ' . $state['model'] : '');
    }

    /**
     * 항목 하나의 칸들 (#89).
     *
     * 🔴 **모든 값이 항목에 속한다** — 공급자·키·주소·모델·effort·응답 상한. 예전에는
     *    활성 칸 한 벌을 공유하고 공급자별 스냅샷으로 오갔는데, 그 모양으로는 "같은 공급자
     *    두 개"(키 둘, 혹은 호스팅 하나와 로컬 하나)를 표현할 수 없다.
     *
     * @return Component[]
     */
    private function entryFields(): array
    {
        return [
            // 항목의 신원. 이름이 바뀌어도 쉬는 상태가 따라가도록 id 를 들고 다닌다.
            Hidden::make('id')->default(fn () => (string) Str::ulid()),
            // 이 항목에 대해 "연결 확인"을 통과한 지문. 새 키를 친 항목은 이게 있어야 저장된다.
            Hidden::make('verified')->default(''),

            TextInput::make('label')
                ->label(trans('concierge::strings.entry_field_label'))
                ->helperText(trans('concierge::strings.entry_help_label'))
                ->maxLength(60)
                ->placeholder(fn (Get $get) => ProviderFactory::label((string) $get('provider')))
                ->columnSpanFull(),

            Select::make('provider')
                ->label(trans('concierge::strings.field_provider'))
                ->options(ProviderFactory::options())
                ->native(false)
                ->default('anthropic')
                ->live()
                // 공급자를 바꾸면 모델·effort 도 그 공급자의 권장값으로 함께 바뀐다 —
                // 이전 공급자의 모델이 남아 있으면 저장 때까지 잘못된 조합으로 보인다.
                ->afterStateUpdated(function (Set $set, ?string $state): void {
                    $set('model', (string) config("concierge.providers.{$state}.default_model", ''));
                    $set('effort', (string) (config("concierge.providers.{$state}.default_effort") ?? ''));
                    $set('verified', ''); // 다른 공급자에 대한 확인은 무효다
                })
                ->required()
                ->columnSpanFull(),

            // 로컬 OpenAI 호환 엔드포인트만 주소가 필요하다(capabilities 기준).
            TextInput::make('base_url')
                ->label(trans('concierge::strings.field_base_url'))
                ->helperText(trans('concierge::strings.help_base_url'))
                ->placeholder('http://localhost:11434/v1')
                ->url()
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Set $set) => $set('verified', ''))
                ->visible(fn (Get $get) => ProviderFactory::capabilitiesOf((string) $get('provider'))->needsBaseUrl)
                ->columnSpanFull(),

            // 키 입력과 "연결 확인"은 **한 줄에** 둔다 — 버튼이 아래로 내려가면 무엇을
            // 확인하는 버튼인지 눈으로 이어지지 않는다.
            Flex::make([
                TextInput::make('api_key')
                    ->label(fn (Get $get) => trans('concierge::strings.field_api_key_for', [
                        'provider' => (string) config('concierge.providers.' . $get('provider') . '.short', ''),
                    ]))
                    ->password()
                    ->revealable()
                    ->autocomplete(false)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set) => $set('verified', ''))
                    // 🔴 저장된 키는 폼으로 되돌리지 않는다 — 폼 상태는 브라우저로 나가는 값이다.
                    //    비워 두면 그대로 둔다는 뜻이고, 그 사실을 자리 표시로 말한다.
                    ->placeholder(fn (Get $get) => $this->entryHasStoredKey((string) $get('id'))
                        ? trans('concierge::strings.api_key_set')
                        : trans('concierge::strings.api_key_unset'))
                    ->helperText(trans('concierge::strings.help_api_key'))
                    // 🔴 **연결 확인 게이트는 폼 검증으로 건다** (#89).
                    //
                    //    저장 쪽에서 막을 수는 없다: 호스트의 Plugin::saveSettings() 가
                    //    `catch (Exception) {}` 로 **모든 예외를 삼킨다**. Filament 가 모달을
                    //    열어 두는 근거인 Halt 도 Exception 이라 거기서 사라지고, 화면은
                    //    성공한 것처럼 닫힌다 — 오류를 띄워 놓고 고칠 화면을 치우는 꼴이었다.
                    //
                    //    검증은 액션이 불리기 **전에** 돌고, 실패하면 모달이 그대로 남으면서
                    //    문제가 난 칸에 빨간 글씨가 붙는다. 어느 항목인지 찾을 필요도 없다.
                    ->rule(static fn (Get $get) => static function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                        $typed = trim((string) $value);

                        // 빈 칸은 "그대로 두기" — 저장된 키를 다시 확인시킬 이유가 없다.
                        if ($typed === '') {
                            return;
                        }

                        $expected = self::verifyFingerprint(
                            (string) $get('provider'),
                            $typed,
                            (string) $get('base_url'),
                        );

                        if ((string) $get('verified') !== $expected) {
                            $fail(trans('concierge::strings.verify_required_inline'));
                        }
                    }),

                Actions::make([
                    Action::make('verify_entry')
                        ->label(trans('concierge::strings.verify_key'))
                        // 명시하지 않으면 아이콘 버튼으로 렌더된다 — 아이콘이 없어
                        // 투명한 클릭 영역만 남으므로 라벨 버튼을 강제한다.
                        ->button()
                        ->color('gray')
                        ->action(function (Get $get, Set $set): void {
                            $provider = (string) $get('provider');
                            $typed = trim((string) $get('api_key'));
                            // 폼에 새 키가 없으면 저장된 키로 확인한다 — 다른 값을 고칠 때마다
                            // 키를 다시 치게 하지 않는다.
                            $key = $typed !== '' ? $typed : $this->entryStoredKey((string) $get('id'));
                            $baseUrl = (string) $get('base_url');

                            $error = ProviderProbe::verify($provider, $key, $baseUrl);
                            ProviderFactory::forgetModels($provider, $key, $baseUrl);

                            if ($error === null) {
                                $set('verified', self::verifyFingerprint($provider, $typed, $baseUrl));
                                Notification::make()->success()->title(trans('concierge::strings.verify_ok'))->send();
                            } else {
                                $set('verified', '');
                                Notification::make()->danger()->title(trans('concierge::strings.verify_failed'))->body($error)->send();
                            }
                        }),
                ])
                    ->grow(false)
                    // ⚠ 빈 라벨을 두지 않는다. 라벨 자리로 높이를 맞추는 수법은 키 칸이
                    //   **위에** 있을 때 이야기고, 지금은 옆에 있어 죽은 여백만 남는다.
                    //   높이는 cg-verify 의 padding 이 맞춘다.
                    ->extraAttributes(['class' => 'cg-verify']),
            ])
                ->verticalAlignment(VerticalAlignment::Start)
                // 이름·공급자·주소·키는 한 행을 통째로 쓴다 — 반씩 나누면 값이 길어
                // 잘려 보이고, 좌우로 읽을 이유도 없다(모델·effort·상한만 짝을 이룬다).
                ->columnSpanFull(),

            // 선택지가 정의된 공급자는 드롭다운으로, 로컬은 조회 결과 또는 자유 입력으로.
            Select::make('model')
                ->label(trans('concierge::strings.field_model'))
                ->options(fn (Get $get) => ProviderFactory::modelOptions(
                    (string) $get('provider'),
                    trim((string) $get('api_key')) ?: $this->entryStoredKey((string) $get('id')),
                    (string) $get('base_url') ?: null,
                ))
                ->searchable()
                ->helperText(trans('concierge::strings.help_model'))
                ->native(false)
                ->required()
                // 로컬 엔드포인트는 목록이 안 잡힐 수 있다 — 그때는 자유 입력으로 물러난다.
                ->visible(fn (Get $get) => ProviderFactory::modelOptions(
                    (string) $get('provider'),
                    trim((string) $get('api_key')) ?: $this->entryStoredKey((string) $get('id')),
                    (string) $get('base_url') ?: null,
                ) !== []),

            TextInput::make('model')
                ->label(trans('concierge::strings.field_model'))
                ->helperText(trans('concierge::strings.help_model_free'))
                ->placeholder('llama3.3:70b')
                ->required()
                ->visible(fn (Get $get) => ProviderFactory::modelOptions(
                    (string) $get('provider'),
                    trim((string) $get('api_key')) ?: $this->entryStoredKey((string) $get('id')),
                    (string) $get('base_url') ?: null,
                ) === []),

            Select::make('effort')
                ->label(trans('concierge::strings.field_effort'))
                ->options(fn (Get $get) => ProviderFactory::effortOptions((string) $get('provider')))
                ->helperText(trans('concierge::strings.help_effort'))
                ->native(false)
                ->visible(fn (Get $get) => ProviderFactory::capabilitiesOf((string) $get('provider'))->supportsEffort),

            TextInput::make('max_tokens')
                ->label(trans('concierge::strings.field_max_tokens'))
                ->helperText(trans('concierge::strings.help_max_tokens'))
                ->numeric()
                ->minValue(256)
                ->maxValue(64000)
                ->default(fn () => (int) config('concierge.max_tokens'))
                ->required(),
        ];
    }

    /** 이 항목에 저장된 키가 있는가 — 폼에는 키를 되돌리지 않으므로 id 로 확인한다. */
    private function entryHasStoredKey(string $id): bool
    {
        return filled($this->entryStoredKey($id));
    }

    /** 저장된 키 값. 확인 버튼과 모델 조회만 쓴다 — 서버 밖으로 내보내지 말 것. */
    private function entryStoredKey(string $id): ?string
    {
        try {
            foreach (ConciergeSettings::current()->entries() as $entry) {
                if ((string) ($entry['id'] ?? '') === $id) {
                    return $entry['api_key'] ?? null;
                }
            }
        } catch (Throwable) {
            // 마이그레이션 전이거나 APP_KEY 가 바뀐 경우 — "없음"으로 보이면 충분하다.
        }

        return null;
    }

    /**
     * 사용 한도(#4) — 규칙 하나: 기준(메시지·토큰) × 범위 × 주기 × 한도량.
     * 저장 형식(usage_limits 목록)과 판정(UsageLimiter)은 목록 그대로 두고,
     * 화면은 0~1개만 쓴다. 한도량 0 = 무제한(빈 목록).
     *
     * @return Component[]
     */
    private function limitFields(): array
    {
        return [
                    Text::make(trans('concierge::strings.section_limits_help'))
                        ->columnSpanFull(),

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
        ];
    }

    /**
     * 배포 지식(#59) — 도구로 알 수 없는 사실. 매 요청에 실려 가므로 길이가 곧 비용이다.
     *
     * @return Component[]
     */
    private function environmentFields(): array
    {
        return [
                    Text::make(trans('concierge::strings.section_knowledge_help'))
                        ->columnSpanFull(),

                    Textarea::make('deployment_knowledge')
                        ->hiddenLabel()
                        ->rows(10)
                        ->placeholder(trans('concierge::strings.placeholder_knowledge'))
                        ->helperText(trans('concierge::strings.help_knowledge'))
                        ->default(fn () => ConciergeSettings::current()->deployment_knowledge),

                    // 무엇을 어떻게 쓰는지는 이 칸 하나로 설명되지 않는다 — 예시와 요령이
                    // 필요하다. 문서는 저장소에도 그대로 있다(#87).
                    Actions::make([
                        Action::make('knowledge_guide')
                            ->label(trans('concierge::strings.knowledge_guide'))
                            ->button()
                            ->color('gray')
                            ->url(fn () => self::knowledgeGuideUrl())
                            ->openUrlInNewTab(),
                    ]),
        ];
    }

    /**
     * 모양(#10) — 기본은 패널의 primary 를 그대로 따른다(오버라이드 없음).
     * 에이전트를 패널과 구분되는 물건으로 표시하고 싶은 운영자만 색을 고른다.
     *
     * @return Component[]
     */
    private function appearanceFields(): array
    {
        return [
                    Text::make(trans('concierge::strings.section_appearance_help'))
                        ->columnSpanFull(),

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
        ];
    }

    /**
     * 기능 — 어시스턴트가 무엇을 할 수 있는지 정하는 스위치들 (#103).
     *
     * 여기만 섹션을 유지한다. 나머지 탭은 한 가지를 정하지만 이 탭은 성격이 다른 넷
     * (검색·유휴 정리·대화 삭제·연동 상태)을 담고, 각각 자기 설명이 필요하다.
     *
     * @return Component[]
     */
    private function featureSections(): array
    {
        return [
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

            $this->integrationsSection(),
        ];
    }

    /**
     * 대화의 시작점 (#93 · #103). 채팅을 열었을 때 입력창 위에 놓이는 버튼들이다.
     *
     * ⚠ **누르면 그 사람이 친 것이 된다.** 프롬프트는 사용자의 말로 기록되고 그 사람의
     *   한도에서 깎인다 — 운영자가 대신 말하게 하는 장치가 아니라 첫 문장을 거드는 것이다.
     *
     * 🔴 **답을 미리 넣지 않는다.** "만들 수 있는 게임은 A·B·C 야" 같은 문장을 프롬프트에
     *    박으면 에이전트가 자기가 확인하지도 않은 사실을 되읽는다. 사용자는 묻고,
     *    에이전트가 도구로 확인해 답한다.
     *
     * 🔴 **경로는 보안이 아니다.** path 는 "지금 화면에서 할 만한 일인가"(적절함)를 정하고,
     *    막는 일은 노출 범위와 권한이 한다. 경로만 걸어 두고 감췄다고 여기면 안 된다 —
     *    사이드바는 어느 화면에서나 열리고, 화면 바깥에서 시작점을 여는 경로도 있다.
     *
     * @return Component[]
     */
    private function presetFields(): array
    {
        return [
            Text::make(trans('concierge::strings.presets_help'))
                ->columnSpanFull(),

            Repeater::make('presets')
                ->hiddenLabel()
                ->addActionLabel(trans('concierge::strings.presets_add'))
                ->reorderable()
                ->collapsible()
                ->collapsed()
                ->itemLabel(fn (array $state): ?string => filled($state['label'] ?? null)
                    ? (string) $state['label']
                    : trans('concierge::strings.presets_new_item'))
                ->defaultItems(0)
                ->schema([
                    // 화면 바깥(카탈로그 목록의 버튼 등)에서 이 시작점을 여는 이름이다.
                    // 바꾸면 그 버튼이 아무것도 열지 않게 되므로 설명에 적어 둔다.
                    TextInput::make('preset_key')
                        ->label(trans('concierge::strings.presets_field_key'))
                        ->helperText(trans('concierge::strings.presets_help_key'))
                        ->required()
                        ->maxLength(64)
                        ->regex('/^[a-z0-9_]+$/')
                        // 같은 키가 둘이면 저장할 때 유니크 제약에 걸린다 — 폼에서 막는다.
                        ->distinct(),

                    Toggle::make('enabled')
                        ->label(trans('concierge::strings.presets_field_enabled'))
                        ->default(true),

                    TextInput::make('label')
                        ->label(trans('concierge::strings.presets_field_label'))
                        ->helperText(trans('concierge::strings.presets_help_label'))
                        ->required()
                        ->maxLength(80)
                        ->columnSpanFull(),

                    KeyValue::make('label_translations')
                        ->label(trans('concierge::strings.presets_field_label_translations'))
                        ->helperText(trans('concierge::strings.catalog_help_translations'))
                        ->keyLabel(trans('concierge::strings.catalog_locale'))
                        ->valueLabel(trans('concierge::strings.presets_field_label'))
                        ->addActionLabel(trans('concierge::strings.catalog_add_translation'))
                        ->columnSpanFull(),

                    Textarea::make('prompt')
                        ->label(trans('concierge::strings.presets_field_prompt'))
                        ->helperText(trans('concierge::strings.presets_help_prompt'))
                        ->rows(3)
                        ->required()
                        ->columnSpanFull(),

                    KeyValue::make('prompt_translations')
                        ->label(trans('concierge::strings.presets_field_prompt_translations'))
                        ->keyLabel(trans('concierge::strings.catalog_locale'))
                        ->valueLabel(trans('concierge::strings.presets_field_prompt'))
                        ->addActionLabel(trans('concierge::strings.catalog_add_translation'))
                        ->columnSpanFull(),

                    Select::make('visibility')
                        ->label(trans('concierge::strings.presets_field_visibility'))
                        ->helperText(trans('concierge::strings.presets_help_visibility'))
                        ->options([
                            'all' => trans('concierge::strings.presets_visibility_all'),
                            'create' => trans('concierge::strings.presets_visibility_create'),
                            'admin' => trans('concierge::strings.presets_visibility_admin'),
                        ])
                        ->default('all')
                        ->native(false)
                        ->required(),

                    TextInput::make('permission')
                        ->label(trans('concierge::strings.presets_field_permission'))
                        ->helperText(trans('concierge::strings.presets_help_permission'))
                        ->placeholder('update egg')
                        ->maxLength(120),

                    TextInput::make('path_pattern')
                        ->label(trans('concierge::strings.presets_field_path'))
                        ->helperText(trans('concierge::strings.presets_help_path'))
                        ->placeholder('*concierge-games*')
                        ->maxLength(160)
                        ->columnSpanFull(),
                ])
                ->columns(2),
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
        // 시작점은 제 테이블에 있다 — settings 행에 fill 되지 않게 먼저 뺀다(#103).
        $presets = $data['presets'] ?? null;
        unset($data['presets']);

        $settings = ConciergeSettings::current();

        // ── 공급자 목록 (#89) ─────────────────────────────────────
        // 통과하지 못하면 Halt 가 올라온다 — 창이 열린 채로 남아 고칠 수 있다.
        $entries = $this->normalizeEntries((array) ($data['provider_entries'] ?? []), $settings);
        unset($data['provider_entries']);

        $data['provider_entries'] = $entries;

        // 🔴 첫 항목을 활성 칸에도 그대로 둔다. isConfigured()·모델 조회·사이드바 배지처럼
        //    "지금 설정" 하나를 보는 곳이 여럿이고, 그 값이 목록의 주 공급자와 갈리면
        //    화면이 거짓말한다. 활성 칸은 이제 **주 공급자의 사본**이다.
        $primary = $entries[0];
        $data['provider'] = $primary['provider'];
        $data['base_url'] = $primary['base_url'];
        $data['model'] = $primary['model'];
        $data['effort'] = $primary['effort'];
        $data['max_tokens'] = $primary['max_tokens'];

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
        $settings->api_key = $primary['api_key'];

        $settings->save();
        ConciergeSettings::forgetCached();

        if (is_array($presets)) {
            $this->savePresets($presets);
        }

        Notification::make()
            ->title(trans('concierge::strings.saved'))
            ->success()
            ->send();
    }

    /**
     * 폼의 항목 목록을 저장할 모양으로 (#89).
     *
     * @param  array<mixed, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     *
     * @throws Halt 연결 확인을 통과하지 못했을 때 — 창을 닫지 않고 멈춘다
     */
    private function normalizeEntries(array $rows, ConciergeSettings $settings): array
    {
        $stored = [];

        foreach ($settings->entries() as $entry) {
            $stored[(string) ($entry['id'] ?? '')] = $entry;
        }

        $entries = [];

        foreach ($rows as $row) {
            $provider = (string) ($row['provider'] ?? '');

            if ($provider === '') {
                continue;
            }

            $id = (string) ($row['id'] ?? '') ?: (string) Str::ulid();
            $typed = trim((string) ($row['api_key'] ?? ''));
            $baseUrl = trim((string) ($row['base_url'] ?? '')) ?: null;

            // 빈 입력은 "그대로 두기"다 — 다른 값만 고칠 때 키를 다시 칠 필요가 없어야 한다.
            $key = $typed !== '' ? $typed : ($stored[$id]['api_key'] ?? null);

            // ── 연결 확인 게이트 (#3 후속, 항목별) ──
            // 🔴 새 키를 친 항목은 확인을 통과해야 저장된다. 틀린 키가 조용히 들어가면
            //    그 항목은 매번 실패하고, 장애 조치가 그것을 가려 아무도 모르게 된다 —
            //    대비책이 늘어난 만큼 잘못된 설정도 더 오래 숨는다.
            if ($typed !== '' && (string) ($row['verified'] ?? '') !== self::verifyFingerprint($provider, $typed, (string) $baseUrl)) {
                Notification::make()
                    ->danger()
                    ->title(trans('concierge::strings.verify_required'))
                    ->body(trans('concierge::strings.verify_required_body', ['entry' => trim((string) ($row['label'] ?? '')) ?: ProviderFactory::label($provider)]))
                    ->send();

                // 🔴 **Halt 를 던진다. 그냥 return 하면 창이 닫힌다.** 호스트의 액션은
                //    이 메서드가 정상으로 끝나면 성공으로 보고 모달을 내린다 — 오류를
                //    띄워 놓고 고칠 화면을 치워 버리는 꼴이었다(실측). Filament 는 Halt 를
                //    잡으면 unmountAction() 을 건너뛰므로 창이 그대로 남는다.
                throw new Halt();
            }

            $efforts = array_values((array) config("concierge.providers.{$provider}.efforts", []));
            $effort = (string) ($row['effort'] ?? '');

            if ($efforts !== [] && !in_array($effort, $efforts, true)) {
                $effort = (string) (config("concierge.providers.{$provider}.default_effort") ?? $efforts[0]);
            }

            $entries[] = [
                'id' => $id,
                'label' => trim((string) ($row['label'] ?? '')) ?: ProviderFactory::label($provider),
                'provider' => $provider,
                'api_key' => $key,
                'base_url' => $baseUrl,
                'model' => trim((string) ($row['model'] ?? '')),
                'effort' => $effort,
                'max_tokens' => (int) ($row['max_tokens'] ?? config('concierge.max_tokens')),
            ];
        }

        // 하나도 남지 않으면 지금 것을 지킨다 — 목록이 비면 어시스턴트가 말을 못 한다.
        return $entries !== [] ? $entries : $settings->entries();
    }

    /**
     * 폼의 시작점 목록을 테이블에 반영한다 (#103).
     *
     * ⚠ **화면에 없는 것은 지운다.** 반복 필드에서 지운 항목은 배열에서 사라질 뿐 삭제
     *   신호가 따로 오지 않는다 — 목록에 남은 키만 남기고 나머지를 지워야 화면과 DB 가
     *   같아진다. 이 폼이 시작점 전체를 보여주므로 안전하다(부분 목록이 아니다).
     *
     * ⚠ 순서는 배열의 순서다. 운영자가 끌어 옮긴 결과가 곧 sort 이고, 노출은 앞에서부터
     *   차오른다(ChatPresets::LIMIT) — 그래서 순서가 곧 "무엇을 보일지"다.
     *
     * @param  array<mixed, array<string, mixed>>  $rows
     */
    private function savePresets(array $rows): void
    {
        $seen = [];
        $sort = 0;

        foreach ($rows as $row) {
            $key = trim((string) ($row['preset_key'] ?? ''));
            $label = trim((string) ($row['label'] ?? ''));
            $prompt = trim((string) ($row['prompt'] ?? ''));

            // 셋 중 하나라도 비면 시작점이 되지 못한다 — 폼 검증이 이미 막지만,
            // 반쯤 만들다 만 행이 DB 에 남는 것보다 조용히 건너뛰는 편이 낫다.
            if ($key === '' || $label === '' || $prompt === '') {
                continue;
            }

            ConciergePreset::query()->updateOrCreate(
                ['preset_key' => $key],
                [
                    'sort' => $sort++,
                    'enabled' => (bool) ($row['enabled'] ?? true),
                    'label' => $label,
                    'label_translations' => self::cleanTranslations($row['label_translations'] ?? null),
                    'prompt' => $prompt,
                    'prompt_translations' => self::cleanTranslations($row['prompt_translations'] ?? null),
                    'visibility' => in_array($row['visibility'] ?? '', ['all', 'create', 'admin'], true)
                        ? (string) $row['visibility']
                        : 'all',
                    // 빈 문자열과 null 은 뜻이 다르다 — 모델은 filled() 로 "검사하지 않음"을
                    // 가리므로 빈 값은 null 로 눕힌다.
                    'permission' => trim((string) ($row['permission'] ?? '')) ?: null,
                    'path_pattern' => trim((string) ($row['path_pattern'] ?? '')) ?: null,
                ],
            );

            $seen[] = $key;
        }

        ConciergePreset::query()->whereNotIn('preset_key', $seen)->delete();
    }

    /**
     * 빈 번역은 저장하지 않는다 — 로케일 칸만 만들어 두고 값을 비워 두면 그 언어에서
     * 빈 라벨이 뜬다. 값이 없으면 기본값으로 물러나는 것이 규칙이다(#99).
     *
     * @param  mixed  $translations
     * @return ?array<string, string>
     */
    private static function cleanTranslations(mixed $translations): ?array
    {
        if (!is_array($translations)) {
            return null;
        }

        $clean = [];

        foreach ($translations as $locale => $value) {
            $locale = trim((string) $locale);
            $value = trim((string) $value);

            if ($locale !== '' && $value !== '') {
                $clean[$locale] = $value;
            }
        }

        return $clean ?: null;
    }
}
