<?php

namespace WisdomIT\Concierge\Filament\Admin\Resources\ConciergeGames;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\ViewField;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Symfony\Component\Yaml\Yaml;
use WisdomIT\Concierge\Catalog\AdvancedYaml;
use WisdomIT\Concierge\Support\Markdown;
use WisdomIT\Concierge\Models\ConciergeGame;

/**
 * 게임 카탈로그 관리 화면 (#81).
 *
 * 카탈로그는 **운영자 데이터**다 — 패널마다 임포트한 egg 가 다르니 목록도 달라야 한다.
 * 종전에는 플러그인 안의 YAML 을 셸로 고쳐야 했고 업데이트가 그걸 지웠다.
 *
 * ⚠ 폼은 **사람이 정하는 것**만 칸으로 편다: 이름·설명·egg·크기·물어볼 것.
 *   기술 항목(post_install 절차, 포트, 비밀 변수, 모드)은 종류마다 형태가 달라
 *   (file_replace 와 json_vmarg 는 필요한 칸이 다르다) 반복 필드로 펴면 오히려 어렵다 —
 *   YAML 한 칸으로 둔다.
 */
class ConciergeGameResource extends Resource
{
    protected static ?string $model = ConciergeGame::class;

    protected static string|BackedEnum|null $navigationIcon = 'tabler-device-gamepad-2';

    protected static ?string $slug = 'concierge-games';

    public static function getNavigationGroup(): ?string
    {
        return trans('admin/dashboard.advanced');
    }

    public static function getNavigationLabel(): string
    {
        return trans('concierge::strings.catalog_title');
    }

    public static function getModelLabel(): string
    {
        return trans('concierge::strings.catalog_game');
    }

    public static function getPluralModelLabel(): string
    {
        return trans('concierge::strings.catalog_title');
    }

    public static function form(Schema $schema): Schema
    {
        // ⚠ 리소스 폼의 기본 그리드는 2열이라 섹션이 좌우로 쌓인다. 이 폼은 섹션마다 높이가
        //   크게 달라(기본 정보는 길고 크기·질문은 접혀 있다) 한쪽에 빈 공간이 길게 남는다.
        //   섹션은 위에서 아래로 한 줄씩 놓는다.
        return $schema->columns(1)->components([
            Section::make(trans('concierge::strings.catalog_section_basics'))
                ->description(trans('concierge::strings.catalog_section_basics_help'))
                ->schema([
                    TextInput::make('game_id')
                        ->label(trans('concierge::strings.catalog_field_id'))
                        ->helperText(trans('concierge::strings.catalog_help_id'))
                        ->required()
                        ->alphaDash()
                        ->unique(ignoreRecord: true),

                    // 🔴 egg 는 **이름으로** 참조한다 — id 는 패널을 재구축하면 달라진다.
                    //    임포트된 egg 중에서 고르게 해 "패널에 없는 egg" 를 처음부터 막는다.
                    Select::make('egg')
                        ->label(trans('concierge::strings.catalog_field_egg'))
                        ->helperText(trans('concierge::strings.catalog_help_egg'))
                        ->options(fn () => \App\Models\Egg::query()->orderBy('name')->pluck('name', 'name'))
                        ->searchable()
                        ->required(),

                    TextInput::make('name')
                        ->label(trans('concierge::strings.catalog_field_name'))
                        ->helperText(trans('concierge::strings.catalog_help_name'))
                        ->required(),

                    Textarea::make('summary')
                        ->label(trans('concierge::strings.catalog_field_summary'))
                        ->helperText(trans('concierge::strings.catalog_help_summary'))
                        ->rows(2)
                        ->columnSpanFull(),

                    // 로케일별 이름은 **선택**이다 — 단일 언어 패널 운영자에게 번역 작성을
                    // 강요하지 않는다. 비면 위의 기본 이름을 쓴다(#79·#81).
                    KeyValue::make('name_translations')
                        ->label(trans('concierge::strings.catalog_field_name_translations'))
                        ->helperText(trans('concierge::strings.catalog_help_translations'))
                        ->keyLabel(trans('concierge::strings.catalog_locale'))
                        ->valueLabel(trans('concierge::strings.catalog_field_name'))
                        ->addActionLabel(trans('concierge::strings.catalog_add_translation'))
                        ->columnSpanFull(),

                    KeyValue::make('summary_translations')
                        ->label(trans('concierge::strings.catalog_field_summary_translations'))
                        ->keyLabel(trans('concierge::strings.catalog_locale'))
                        ->valueLabel(trans('concierge::strings.catalog_field_summary'))
                        ->addActionLabel(trans('concierge::strings.catalog_add_translation'))
                        ->columnSpanFull(),

                    Toggle::make('available')
                        ->label(trans('concierge::strings.catalog_field_available'))
                        ->helperText(trans('concierge::strings.catalog_help_available'))
                        ->default(true)
                        ->live()
                        ->columnSpanFull(),

                    TextInput::make('unavailable_reason')
                        ->label(trans('concierge::strings.catalog_field_unavailable_reason'))
                        ->helperText(trans('concierge::strings.catalog_help_unavailable_reason'))
                        ->visible(fn ($get) => ! $get('available'))
                        ->columnSpanFull(),

                    // 못 만드는 **이유**를 설명하는 문장이라, 모르는 언어면 설명이 아니게 된다(#99).
                    KeyValue::make('unavailable_reason_translations')
                        ->label(trans('concierge::strings.catalog_field_reason_translations'))
                        ->keyLabel(trans('concierge::strings.catalog_locale'))
                        ->valueLabel(trans('concierge::strings.catalog_field_unavailable_reason'))
                        ->addActionLabel(trans('concierge::strings.catalog_add_translation'))
                        ->visible(fn ($get) => ! $get('available'))
                        ->columnSpanFull(),
                // 짧은 칸(식별자·egg·이름)만 나란히 두고, 긴 것은 전체 폭을 쓴다.
                ])->columns(2),

            Section::make(trans('concierge::strings.catalog_section_sizes'))
                ->description(trans('concierge::strings.catalog_section_sizes_help'))
                ->schema([
                    Repeater::make('sizes')
                        ->hiddenLabel()
                        ->schema([
                            TextInput::make('id')->label(trans('concierge::strings.catalog_size_id'))->required(),
                            TextInput::make('label')->label(trans('concierge::strings.catalog_size_label'))->required(),
                            // 라벨도 사용자가 읽는 값이다(#99) — 비우면 위 라벨을 쓴다.
                            KeyValue::make('label_translations')
                                ->label(trans('concierge::strings.catalog_field_label_translations'))
                                ->keyLabel(trans('concierge::strings.catalog_locale'))
                                ->valueLabel(trans('concierge::strings.catalog_size_label'))
                                ->addActionLabel(trans('concierge::strings.catalog_add_translation'))
                                ->columnSpanFull(),
                            TextInput::make('players')->label(trans('concierge::strings.catalog_size_players'))->numeric()->required(),
                            TextInput::make('memory')->label(trans('concierge::strings.catalog_size_memory'))->numeric()->suffix('MiB')->required(),
                            TextInput::make('disk')->label(trans('concierge::strings.catalog_size_disk'))->numeric()->suffix('MiB')->required(),
                            TextInput::make('cpu')->label(trans('concierge::strings.catalog_size_cpu'))->numeric()->suffix('%')->required(),
                        ])
                        ->columns(3)
                        ->itemLabel(fn (array $state) => trim(($state['label'] ?? '') . ' (' . ($state['id'] ?? '') . ')'))
                        ->addActionLabel(trans('concierge::strings.catalog_add_size'))
                        ->collapsed(),
                ]),

            Section::make(trans('concierge::strings.catalog_section_ask'))
                ->description(trans('concierge::strings.catalog_section_ask_help'))
                ->schema([
                    Repeater::make('ask')
                        ->hiddenLabel()
                        ->schema([
                            TextInput::make('env')->label(trans('concierge::strings.catalog_ask_env'))->required(),
                            TextInput::make('label')->label(trans('concierge::strings.catalog_ask_label'))->required(),
                            Select::make('type')
                                ->label(trans('concierge::strings.catalog_ask_type'))
                                ->options([
                                    'text' => 'text',
                                    'number' => 'number',
                                    'choice' => 'choice',
                                    'password' => 'password',
                                ])
                                ->required(),
                            TextInput::make('default')->label(trans('concierge::strings.catalog_ask_default')),
                            // 다른 칸은 라벨이 위에 있는데 토글만 라벨 옆에 붙어 한 칸이
                            // 뭉쳐 보였다 — 같은 배치로 맞춘다.
                            Toggle::make('optional')
                                ->label(trans('concierge::strings.catalog_ask_optional'))
                                ->inline(false),
                            TextInput::make('note')->label(trans('concierge::strings.catalog_ask_note'))->columnSpanFull(),
                            KeyValue::make('label_translations')
                                ->label(trans('concierge::strings.catalog_field_label_translations'))
                                ->keyLabel(trans('concierge::strings.catalog_locale'))
                                ->valueLabel(trans('concierge::strings.catalog_ask_label'))
                                ->addActionLabel(trans('concierge::strings.catalog_add_translation'))
                                ->columnSpanFull(),
                            KeyValue::make('note_translations')
                                ->label(trans('concierge::strings.catalog_field_note_translations'))
                                ->keyLabel(trans('concierge::strings.catalog_locale'))
                                ->valueLabel(trans('concierge::strings.catalog_ask_note'))
                                ->addActionLabel(trans('concierge::strings.catalog_add_translation'))
                                ->columnSpanFull(),
                        ])
                        ->columns(3)
                        ->itemLabel(fn (array $state) => trim(($state['label'] ?? '') . ' — ' . ($state['env'] ?? '')))
                        ->addActionLabel(trans('concierge::strings.catalog_add_ask'))
                        ->collapsed(),
                ]),

            Section::make(trans('concierge::strings.catalog_section_advanced'))
                ->description(trans('concierge::strings.catalog_section_advanced_help'))
                ->collapsed()
                ->schema([
                    // 줄 번호 거터가 달린 편집기(concierge::filament.forms.yaml-editor) —
                    // 검사 결과가 "3번째 줄"이라고 말하는데 편집기에서 세어야 한다면
                    // 그 안내는 반쪽이다. 문제가 있는 줄은 거터에서 색으로도 보인다.
                    ViewField::make('advanced_yaml')
                        ->hiddenLabel()
                        ->view('concierge::filament.forms.yaml-editor')
                        ->helperText(trans('concierge::strings.catalog_help_advanced'))
                        ->afterStateHydrated(function (ViewField $component, ?Model $record): void {
                            $advanced = $record?->advanced ?: [];
                            $component->state($advanced === [] ? '' : Yaml::dump($advanced, 6, 2));
                        })
                        // 저장을 막는 것은 **오류**뿐이다. 모르는 키 같은 경고는 통과시킨다 —
                        // 막으면 플러그인이 따라잡을 때까지 그 배포는 아무것도 못 고친다.
                        ->rules([
                            fn (Get $get) => function (string $attribute, $value, \Closure $fail) use ($get) {
                                foreach (AdvancedYaml::errors((string) $value, (string) $get('egg')) as $issue) {
                                    $fail(self::issueText($issue));
                                }
                            },
                        ]),

                    // 검사 결과는 **칸 바로 아래에 늘** 보인다 — 문제가 없을 때도.
                    // 아무것도 안 뜨면 "정상"인지 "검사가 안 돈 것"인지 알 수 없다(사용자 지적).
                    // 토스트에 기대지 않는 이유이기도 하다: 사이드바가 떠 있는 화면에서는
                    // 알림이 어디에 뜨는지 장담할 수 없지만, 이 줄은 늘 같은 자리에 있다.
                    Text::make(fn (Get $get) => new HtmlString(
                        self::checkPanel(AdvancedYaml::issues((string) $get('advanced_yaml'), (string) $get('egg')))
                    )),

                    // ⚠ 필드의 belowContent 로 붙이면 **페이지 맨 끝**(저장·취소 옆)으로 밀려난다
                    //   — 실측. 검사 버튼은 고치는 칸 바로 아래 있어야 의미가 있으므로
                    //   섹션 안에 액션 컴포넌트로 넣는다.
                    Actions::make([
                        Action::make('check_yaml')
                            ->label(trans('concierge::strings.catalog_yaml_check'))
                            ->button()
                            ->color('gray')
                            // 결과는 알림 한 번으로 끝낸다. 문제가 없을 때도 반드시 알린다 —
                            // 아무것도 안 뜨면 "정상"인지 "검사가 안 돈 것"인지 알 수 없다.
                            ->action(function (Get $get): void {
                                $issues = AdvancedYaml::issues((string) $get('advanced_yaml'), (string) $get('egg'));

                                if ($issues === []) {
                                    Notification::make()
                                        ->success()
                                        ->title(trans('concierge::strings.catalog_check_ok'))
                                        ->send();

                                    return;
                                }

                                // 자세한 내용은 칸 아래 목록에 이미 있다 — 여기서는 몇 건인지와
                                // 첫 문제만 짚어 준다.
                                Notification::make()
                                    ->status(AdvancedYaml::errors((string) $get('advanced_yaml')) === [] ? 'warning' : 'danger')
                                    ->title(self::issueHeading($issues))
                                    ->body(self::issueText($issues[0]))
                                    ->send();
                            }),

                        Action::make('yaml_help')
                            ->label(trans('concierge::strings.catalog_yaml_help'))
                            ->button()
                            ->color('gray')
                            ->modalHeading(trans('concierge::strings.catalog_doc_title'))
                            ->modalDescription(trans('concierge::strings.catalog_doc_intro'))
                            // 저장소의 문서를 그대로 렌더한다 — 화면과 repo 가 **같은 파일**을
                            // 본다. 둘로 나누면 한쪽만 고쳐지고, 그때부터 문서가 거짓말을 한다.
                            ->modalContent(fn () => new HtmlString(self::advancedDoc()))
                            ->modalWidth('4xl')
                            ->modalSubmitAction(false)
                            ->modalCancelActionLabel(trans('concierge::strings.card_cancel')),
                    ])->key('advanced_actions'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                TextColumn::make('name')
                    ->label(trans('concierge::strings.catalog_field_name'))
                    ->description(fn (ConciergeGame $game) => $game->summary)
                    ->searchable(),

                TextColumn::make('game_id')
                    ->label(trans('concierge::strings.catalog_field_id'))
                    ->badge()
                    ->color('gray')
                    ->searchable(),

                // ⚠ 카탈로그에 있는데 패널에 없는 egg 는 **개설 순간에야** 터진다.
                //   여기서 먼저 보여 준다 — scripts/validate-catalog.py 가 하던 일이다.
                IconColumn::make('egg')
                    ->label(trans('concierge::strings.catalog_egg_present'))
                    ->boolean()
                    ->state(fn (ConciergeGame $game) => $game->eggExists())
                    ->tooltip(fn (ConciergeGame $game) => $game->eggExists()
                        ? $game->egg
                        : trans('concierge::strings.catalog_egg_missing', ['egg' => $game->egg])),

                IconColumn::make('available')
                    ->label(trans('concierge::strings.catalog_field_available'))
                    ->boolean(),

                TextColumn::make('sizes')
                    ->label(trans('concierge::strings.catalog_section_sizes'))
                    ->state(fn (ConciergeGame $game) => count($game->sizes ?? []))
                    ->badge()
                    ->color('gray'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            // 만들기 버튼은 페이지(ListConciergeGames)가 낸다 — 여기서도 내면 둘이 된다.
            ->toolbarActions([DeleteBulkAction::make()]);
    }

    /** 문제 하나를 "3번째 줄 — …" 한 줄로. */
    private static function issueText(array $issue): string
    {
        $where = $issue['line'] === null
            ? trans('concierge::strings.catalog_check_nowhere')
            : trans('concierge::strings.catalog_check_line', ['line' => $issue['line']]);

        return $where . ' — ' . $issue['message'];
    }

    /** @param array<int, array<string, mixed>> $issues */
    private static function issueHeading(array $issues): string
    {
        $errors = count(array_filter($issues, fn ($i) => $i['severity'] === 'error'));
        $warnings = count($issues) - $errors;

        return implode(' · ', array_filter([
            $errors > 0 ? trans('concierge::strings.catalog_check_errors', ['n' => $errors]) : null,
            $warnings > 0 ? trans('concierge::strings.catalog_check_warnings', ['n' => $warnings]) : null,
        ]));
    }

    /**
     * 칸 아래 상시 표시되는 검사 결과. 문제가 없으면 그렇다고 말한다.
     *
     * @param array<int, array<string, mixed>> $issues
     */
    private static function checkPanel(array $issues): string
    {
        if ($issues === []) {
            return '<div style="display:flex;align-items:center;gap:.5rem;font-size:.875rem;color:#15803d">'
                . '<span aria-hidden="true">✓</span><span>' . e(trans('concierge::strings.catalog_check_ok')) . '</span></div>';
        }

        return '<div style="font-size:.8125rem;font-weight:600;margin-bottom:.15rem">'
            . e(self::issueHeading($issues)) . '</div>' . self::issueList($issues);
    }

    /** @param array<int, array<string, mixed>> $issues */
    private static function issueList(array $issues): string
    {
        // 오류를 먼저 — 저장을 막는 것이 무엇인지가 가장 급하다.
        usort($issues, fn ($a, $b) => ($a['severity'] === 'error' ? 0 : 1) <=> ($b['severity'] === 'error' ? 0 : 1));

        $rows = array_map(function (array $issue) {
            $error = $issue['severity'] === 'error';
            $where = $issue['line'] === null
                ? trans('concierge::strings.catalog_check_nowhere')
                : trans('concierge::strings.catalog_check_line', ['line' => $issue['line']]);

            return '<li style="display:flex;gap:.6rem;padding:.5rem 0;border-top:1px solid rgba(127,127,127,.22)">'
                . '<span style="flex:none;font-family:ui-monospace,monospace;font-size:.75rem;padding:.1rem .45rem;'
                . 'border-radius:.35rem;white-space:nowrap;'
                . ($error ? 'background:rgba(239,68,68,.18);color:#b91c1c' : 'background:rgba(245,158,11,.18);color:#b45309')
                . '">' . e($where) . '</span>'
                . '<span style="font-size:.875rem;line-height:1.5">' . e($issue['message']) . '</span></li>';
        }, $issues);

        return '<ul style="margin:0;padding:0;list-style:none">' . implode('', $rows) . '</ul>';
    }

    private static function codeBlock(string $code): string
    {
        return '<pre style="font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;'
            . 'font-size:.8125rem;line-height:1.6;white-space:pre;overflow-x:auto;'
            . 'padding:.9rem;border-radius:.5rem;background:rgba(127,127,127,.12)">' . e($code) . '</pre>';
    }

    /**
     * 고급 항목 문서(docs/catalog-advanced.<로케일>.md)를 렌더한다.
     *
     * 문서는 저장소에도 그대로 있다 — 화면에서 읽는 사람과 저장소에서 읽는 사람이 같은
     * 글을 본다. 나중에 카탈로그를 다루는 에이전트(#91)도 이 파일을 그대로 쓸 수 있다.
     */
    private static function advancedDoc(): string
    {
        $locale = app()->getLocale();

        foreach ([$locale, 'en'] as $candidate) {
            $path = plugin_path('concierge', 'docs', "catalog-advanced.{$candidate}.md");

            if (is_file($path)) {
                return '<div class="cg-doc">' . Markdown::render((string) file_get_contents($path)) . '</div>'
                    . self::docStyles();
            }
        }

        return '';
    }

    /** 모달 안에서 문서가 문서처럼 읽히도록 — 표·코드 블록·제목 간격. */
    private static function docStyles(): string
    {
        return <<<'HTML'
        <style>
            .cg-doc { font-size: .875rem; line-height: 1.65; }
            .cg-doc h1 { font-size: 1.25rem; font-weight: 700; margin: 0 0 .8rem; }
            .cg-doc h2 { font-size: 1.05rem; font-weight: 650; margin: 1.6rem 0 .5rem; }
            .cg-doc h3 { font-size: .95rem; font-weight: 600; margin: 1.2rem 0 .35rem;
                         font-family: ui-monospace, Menlo, Consolas, monospace; }
            .cg-doc p, .cg-doc ul, .cg-doc ol { margin: .5rem 0; }
            .cg-doc ul, .cg-doc ol { padding-inline-start: 1.2rem; }
            .cg-doc li { margin: .2rem 0; }
            .cg-doc hr { margin: 1.4rem 0; border: 0; border-top: 1px solid rgba(127,127,127,.25); }
            .cg-doc code { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: .8125rem;
                           background: rgba(127,127,127,.14); padding: .05rem .3rem; border-radius: .25rem; }
            .cg-doc pre { background: rgba(127,127,127,.12); padding: .8rem; border-radius: .5rem;
                          overflow-x: auto; margin: .6rem 0; }
            .cg-doc pre code { background: none; padding: 0; white-space: pre; }
            /* 표가 문서의 절반이다 — 형태와 enum 값이 전부 여기 있다. */
            .cg-doc table { width: 100%; border-collapse: collapse; margin: .6rem 0; font-size: .8125rem; }
            .cg-doc th, .cg-doc td { text-align: start; padding: .35rem .6rem;
                                     border-bottom: 1px solid rgba(127,127,127,.22); vertical-align: top; }
            .cg-doc th { font-weight: 600; background: rgba(127,127,127,.08); }
            .cg-doc blockquote { margin: .7rem 0; padding: .6rem .9rem; border-inline-start: 3px solid rgba(245,158,11,.6);
                                 background: rgba(245,158,11,.08); border-radius: 0 .35rem .35rem 0; }
            .cg-doc blockquote p { margin: 0; }
        </style>
        HTML;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListConciergeGames::route('/'),
            'create' => Pages\CreateConciergeGame::route('/create'),
            'edit' => Pages\EditConciergeGame::route('/{record}/edit'),
        ];
    }
}
