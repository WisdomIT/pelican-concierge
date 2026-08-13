<?php

namespace WisdomIT\Concierge\Filament\Admin\Resources\ConciergeGames;

use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Yaml\Yaml;
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
        return $schema->components([
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
                        ->rows(2),

                    // 로케일별 이름은 **선택**이다 — 단일 언어 패널 운영자에게 번역 작성을
                    // 강요하지 않는다. 비면 위의 기본 이름을 쓴다(#79·#81).
                    KeyValue::make('name_translations')
                        ->label(trans('concierge::strings.catalog_field_name_translations'))
                        ->helperText(trans('concierge::strings.catalog_help_translations'))
                        ->keyLabel(trans('concierge::strings.catalog_locale'))
                        ->valueLabel(trans('concierge::strings.catalog_field_name'))
                        ->addActionLabel(trans('concierge::strings.catalog_add_translation')),

                    KeyValue::make('summary_translations')
                        ->label(trans('concierge::strings.catalog_field_summary_translations'))
                        ->keyLabel(trans('concierge::strings.catalog_locale'))
                        ->valueLabel(trans('concierge::strings.catalog_field_summary'))
                        ->addActionLabel(trans('concierge::strings.catalog_add_translation')),

                    Toggle::make('available')
                        ->label(trans('concierge::strings.catalog_field_available'))
                        ->helperText(trans('concierge::strings.catalog_help_available'))
                        ->default(true)
                        ->live(),

                    TextInput::make('unavailable_reason')
                        ->label(trans('concierge::strings.catalog_field_unavailable_reason'))
                        ->helperText(trans('concierge::strings.catalog_help_unavailable_reason'))
                        ->visible(fn ($get) => ! $get('available')),
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
                    Textarea::make('advanced_yaml')
                        ->hiddenLabel()
                        ->rows(14)
                        ->helperText(trans('concierge::strings.catalog_help_advanced'))
                        // ⚠ dehydrated(false) 로 두면 안 된다 — 폼 데이터에 값이 실리지 않아
                        //   저장 때 빈 YAML 로 읽히고, **기술 항목이 통째로 지워진다**(실측:
                        //   post_install·ports·secrets 10개가 날아갔다). 값은 그대로 싣고
                        //   HandlesAdvancedYaml 이 저장 직전에 advanced 로 접어 넣는다.
                        ->afterStateHydrated(function (Textarea $component, ?Model $record): void {
                            $advanced = $record?->advanced ?: [];
                            $component->state($advanced === [] ? '' : Yaml::dump($advanced, 6, 2));
                        })
                        // 여기서 검증하지 않으면 잘못된 YAML 이 조용히 빈 값으로 저장된다 —
                        // 개설 절차(post_install)가 통째로 사라지는 종류의 사고다.
                        ->rules([
                            fn () => function (string $attribute, $value, \Closure $fail) {
                                if (blank($value)) {
                                    return;
                                }

                                try {
                                    $parsed = Yaml::parse($value);
                                } catch (\Throwable $exception) {
                                    $fail(trans('concierge::strings.catalog_yaml_invalid', ['error' => $exception->getMessage()]));

                                    return;
                                }

                                if (! is_array($parsed)) {
                                    $fail(trans('concierge::strings.catalog_yaml_not_map'));
                                }
                            },
                        ]),
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
            ->toolbarActions([DeleteBulkAction::make()])
            ->headerActions([CreateAction::make()]);
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
