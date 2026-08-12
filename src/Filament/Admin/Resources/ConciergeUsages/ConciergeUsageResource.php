<?php

namespace WisdomIT\Concierge\Filament\Admin\Resources\ConciergeUsages;

use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use WisdomIT\Concierge\Filament\Admin\Resources\ConciergeUsages\Pages;
use WisdomIT\Concierge\Filament\Admin\Resources\ConciergeUsages\Pages\ListConciergeUsages;
use WisdomIT\Concierge\Models\ConciergeUsage;

/**
 * 목록은 **대화 1건 = 1행**이다.
 *
 * 에이전트는 여러 번 주고받으며 일하므로 메시지를 낱개로 늘어놓으면 읽을 수가 없다.
 * 각 행은 그 대화의 **첫 메시지** 레코드이고(그래서 첫 질문이 그대로 제목이 된다),
 * 메시지 수·토큰 합계는 아래 selectSub 로 붙인다. 전체 대화는 모달에서 채팅 형태로 본다.
 */
class ConciergeUsageResource extends Resource
{
    protected static ?string $model = ConciergeUsage::class;

    protected static string|BackedEnum|null $navigationIcon = 'tabler-chart-bar';

    protected static ?string $slug = 'concierge-usage';

    public static function getNavigationGroup(): ?string
    {
        return trans('admin/dashboard.advanced');
    }

    public static function getNavigationLabel(): string
    {
        return trans('concierge::strings.usage_title');
    }

    public static function getModelLabel(): string
    {
        return trans('concierge::strings.conversation_label');
    }

    public static function getPluralModelLabel(): string
    {
        return trans('concierge::strings.usage_title');
    }

    /**
     * 대화당 첫 메시지만 남기고, 그 대화의 집계를 컬럼처럼 붙인다.
     * 행이 진짜 모델 인스턴스라서 키가 살아 있고 → 모달·삭제가 그대로 동작한다.
     * (groupBy 로 뭉개면 레코드 키가 사라져 액션이 깨진다)
     */
    public static function getEloquentQuery(): Builder
    {
        $sub = fn (string $expression) => fn ($query) => $query
            ->from('concierge_usages as agg')
            ->whereColumn('agg.conversation_id', 'concierge_usages.conversation_id')
            ->selectRaw($expression);

        return parent::getEloquentQuery()
            ->select('concierge_usages.*')
            ->selectSub($sub('count(*)'), 'messages_count')
            ->selectSub($sub('coalesce(sum(input_tokens), 0)'), 'total_input_tokens')
            ->selectSub($sub('coalesce(sum(output_tokens), 0)'), 'total_output_tokens')
            // 카드 결정 행(#6)은 문제가 아니다 — 버튼을 누른 기록일 뿐이다.
            ->selectSub($sub("count(case when status not in ('ok', 'card_resolved') then 1 end)"), 'problem_count')
            ->selectSub(fn ($query) => $query
                ->from('concierge_tool_calls as tc')
                ->whereColumn('tc.conversation_id', 'concierge_usages.conversation_id')
                ->selectRaw('count(*)'), 'tool_calls_count')
            // 사용자가 지운 대화(#8) — 기록은 그대로 남지만, 지워졌다는 사실이 보여야 한다.
            // 이 화면은 대화 테이블을 읽지 않으므로(집계는 usages 기준) 따로 묻는다.
            ->selectSub(fn ($query) => $query
                ->from('concierge_conversations as cc')
                ->whereColumn('cc.id', 'concierge_usages.conversation_id')
                ->selectRaw('count(case when cc.deleted_at is not null then 1 end)'), 'conversation_deleted')
            ->whereIn('concierge_usages.id', fn ($query) => $query
                ->from('concierge_usages')
                ->selectRaw('min(id)')
                ->groupBy('conversation_id'));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            // 행 아무 데나 눌러도 대화가 열린다.
            ->recordAction('view')
            ->columns([
                // 시각 표시는 패널 규약대로 보는 사용자의 프로필 timezone (저장은 UTC).
                TextColumn::make('created_at')
                    ->label(trans('concierge::strings.field_started_at'))
                    ->dateTime('Y-m-d H:i', timezone: user()->timezone ?? 'UTC')
                    ->sortable(),

                TextColumn::make('user.username')
                    ->label(trans('concierge::strings.field_user'))
                    ->icon('tabler-user')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('user_message')
                    ->label(trans('concierge::strings.field_first_message'))
                    ->limit(60)
                    ->placeholder(trans('concierge::strings.content_not_logged'))
                    ->searchable(),

                // 사용자가 지운 대화 표시(#8). 지워도 이 화면의 기록·집계는 그대로다.
                TextColumn::make('conversation_deleted')
                    ->label(trans('concierge::strings.field_deleted'))
                    ->badge()
                    ->color('warning')
                    ->formatStateUsing(fn ($state) => $state ? trans('concierge::strings.conversation_deleted_badge') : '')
                    ->placeholder(''),

                TextColumn::make('messages_count')
                    ->label(trans('concierge::strings.field_messages'))
                    ->badge()
                    ->alignRight(),

                TextColumn::make('tool_calls_count')
                    ->label(trans('concierge::strings.field_tools'))
                    ->badge()
                    ->color('gray')
                    ->alignRight()
                    // 도구를 안 쓴 대화는 그냥 잡담이다 — 0 을 굳이 그리지 않는다.
                    ->formatStateUsing(fn (int $state) => $state > 0 ? (string) $state : ''),

                TextColumn::make('total_input_tokens')
                    ->label(trans('concierge::strings.field_input_tokens'))
                    ->numeric()
                    ->alignRight()
                    ->summarize(Sum::make()->label(trans('concierge::strings.sum'))),

                TextColumn::make('total_output_tokens')
                    ->label(trans('concierge::strings.field_output_tokens'))
                    ->numeric()
                    ->alignRight()
                    ->summarize(Sum::make()->label(trans('concierge::strings.sum'))),

                // 정상 대화에는 아무 것도 안 띄운다 — 눈에 걸리는 건 문제가 있을 때뿐이어야 한다.
                TextColumn::make('problem_count')
                    ->label(trans('concierge::strings.field_problems'))
                    ->badge()
                    ->color('danger')
                    ->formatStateUsing(fn (int $state) => $state > 0 ? (string) $state : '')
                    ->placeholder(''),
            ])
            ->filters([
                SelectFilter::make('user_id')
                    ->label(trans('concierge::strings.field_user'))
                    ->relationship('user', 'username')
                    ->searchable()
                    ->preload(),

                Filter::make('has_problem')
                    ->label(trans('concierge::strings.filter_has_problem'))
                    ->query(fn (Builder $query) => $query->whereExists(fn ($sub) => $sub
                        ->from('concierge_usages as p')
                        ->whereColumn('p.conversation_id', 'concierge_usages.conversation_id')
                        ->whereNotIn('p.status', [ConciergeUsage::STATUS_OK, ConciergeUsage::STATUS_CARD]))),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label(trans('concierge::strings.view_conversation'))
                    ->modalHeading(fn (ConciergeUsage $record) => sprintf(
                        '%s · %s%s',
                        $record->user?->username ?? '-',
                        $record->created_at->timezone(user()->timezone ?? 'UTC')->format('Y-m-d H:i'),
                        // 목록 쿼리의 selectSub 값 — 모달에서도 지워진 대화임이 보여야 한다(#8).
                        ($record->conversation_deleted ?? 0)
                            ? ' · ' . trans('concierge::strings.conversation_deleted_badge')
                            : '',
                    ))
                    ->modalContent(fn (ConciergeUsage $record) => view(
                        'concierge::filament.admin.conversation',
                        ['messages' => static::conversationOf($record)],
                    ))
                    // 읽기 전용이므로 저장 버튼이 없어야 한다.
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(trans('concierge::strings.close')),

                // ⚠ 행은 대화의 첫 메시지일 뿐이다. 그냥 지우면 나머지가 고아로 남는다.
                DeleteAction::make()
                    ->label(trans('concierge::strings.delete_conversation'))
                    ->action(fn (ConciergeUsage $record) => static::deleteConversations([$record->conversation_id])),
            ])
            ->toolbarActions([
                DeleteBulkAction::make()
                    ->label(trans('concierge::strings.delete_conversation'))
                    ->action(fn (Collection $records) => static::deleteConversations(
                        $records->pluck('conversation_id')->all(),
                    )),
            ])
            ->emptyStateIcon('tabler-chart-bar')
            ->emptyStateDescription('');
    }

    /** @return Collection<int, ConciergeUsage> */
    private static function conversationOf(ConciergeUsage $record): Collection
    {
        return ConciergeUsage::query()
            ->where('conversation_id', $record->conversation_id)
            // 무엇을 보고 답했는지가 진단의 핵심이라 도구 이력을 함께 싣는다.
            ->with(['toolCalls.server'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /** @param array<int, ?string> $conversationIds */
    private static function deleteConversations(array $conversationIds): void
    {
        ConciergeUsage::query()
            ->whereIn('conversation_id', array_filter($conversationIds))
            ->delete();
    }

    /** @return array<string, mixed> */
    public static function getPages(): array
    {
        return [
            'index' => ListConciergeUsages::route('/'),
            // 사용자별 통계·도표 (#32) — 화면 상단의 탭(UsageTabs)이 셋을 오간다.
            'stats' => Pages\UsageStats::route('/stats'),
            'charts' => Pages\UsageCharts::route('/charts'),
        ];
    }
}
