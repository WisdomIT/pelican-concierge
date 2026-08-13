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
                            Toggle::make('optional')->label(trans('concierge::strings.catalog_ask_optional')),
                            TextInput::make('note')->label(trans('concierge::strings.catalog_ask_note'))->columnSpanFull(),
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
                            fn () => function (string $attribute, $value, \Closure $fail) {
                                foreach (AdvancedYaml::errors((string) $value) as $issue) {
                                    $fail(self::issueText($issue));
                                }
                            },
                        ]),

                    // 검사 결과는 **칸 바로 아래**에 늘 보인다. 버튼을 눌러야만 알 수 있으면
                    // 대개 누르지 않고, 문제는 저장할 때에야 드러난다.
                    Text::make(fn (Get $get) => new HtmlString(
                        self::issueList(AdvancedYaml::issues((string) $get('advanced_yaml')))
                    ))
                        ->visible(fn (Get $get) => AdvancedYaml::issues((string) $get('advanced_yaml')) !== []),

                    // ⚠ 필드의 belowContent 로 붙이면 **페이지 맨 끝**(저장·취소 옆)으로 밀려난다
                    //   — 실측. 검사 버튼은 고치는 칸 바로 아래 있어야 의미가 있으므로
                    //   섹션 안에 액션 컴포넌트로 넣는다.
                    Actions::make([
                        Action::make('check_yaml')
                            ->label(trans('concierge::strings.catalog_yaml_check'))
                            ->button()
                            ->color('gray')
                            // 결과는 **열 때** 만든다. action() 안에서 모달을 붙이면 이미 실행이
                            // 끝난 뒤라 아무것도 열리지 않는다(실측).
                            ->modalHeading(fn (Get $get) => self::issueHeading(
                                AdvancedYaml::issues((string) $get('advanced_yaml'))
                            ))
                            ->modalContent(fn (Get $get) => new HtmlString(self::issueList(
                                AdvancedYaml::issues((string) $get('advanced_yaml'))
                            )))
                            ->modalSubmitAction(false)
                            ->modalCancelActionLabel(trans('concierge::strings.card_cancel')),

                        Action::make('yaml_help')
                            ->label(trans('concierge::strings.catalog_yaml_help'))
                            ->button()
                            ->color('gray')
                            ->modalHeading(trans('concierge::strings.catalog_section_advanced'))
                            ->modalDescription(trans('concierge::strings.catalog_yaml_help_intro'))
                            ->modalContent(new HtmlString(self::codeBlock(self::advancedExample())))
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

    /** 도움말 모달에 보여줄 예시. 실제로 쓰는 키만 담는다 — 지어낸 형식을 보여주지 않는다. */
    private static function advancedExample(): string
    {
        return <<<'YAML'
        # 접속자 수 조회 방식 (Player Counter 가 있을 때)
        query: minecraft_java
        query_port_variable: QUERY_PORT

        # 개설할 때 사용자에게 묻지 않고 고정으로 넣을 egg 변수
        defaults:
          BUILD_NUMBER: latest
          SERVER_JARFILE: server.jar

        # 이 게임이 필요로 하는 포트 수와 프로토콜
        ports:
          count: 1
          protocol: [tcp, udp]

        # 모델에게 보이지 않게 가릴 변수 (비밀번호·라이선스 키)
        secrets: [SERVER_PASSWORD, ADMIN_PASSWORD]

        # 모드·플러그인 설치 지원 여부
        mods:
          supported: true
          kind: plugin
          path: plugins/

        # 설치 직후 자동으로 처리할 일
        post_install:
          - type: file_replace
            path: eula.txt
            from: eula=false
            to: eula=true
            reason: 동의하지 않으면 첫 기동이 조용히 실패한다

        # 설치가 끝났는지 판단할 최소 용량(MB)
        install_min_mb: 300
        YAML;
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
