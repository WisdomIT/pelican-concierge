<?php

namespace WisdomIT\Concierge\Filament\Admin\Resources\ConciergeUsages\Pages;

use App\Models\User;
use Carbon\Carbon;
use Filament\Resources\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use WisdomIT\Concierge\Filament\Admin\Resources\ConciergeUsages\ConciergeUsageResource;
use WisdomIT\Concierge\Filament\Admin\Widgets\UsageTabs;
use WisdomIT\Concierge\Models\ConciergeSettings;
use WisdomIT\Concierge\Models\ConciergeUsage;
use WisdomIT\Concierge\Services\UsageLimiter;

/**
 * 사용자별 사용량 통계 (#32) — 한 사용자 = 한 행.
 *
 * 창은 **지금부터 거꾸로** 1일·7일·30일(롤링)이다 — "최근"이라는 말 그대로.
 * 한도 사용률만 예외로 한도(#4)와 같은 창(서버 기준시 경계)을 본다 —
 * 채팅의 게이지와 같은 숫자가 나와야 관리자와 사용자가 같은 것을 보고 말한다.
 *
 * 집계는 usages 를 user_id 로 묶은 **서브쿼리 조인 하나**다 — 행마다
 * 서브쿼리를 돌리지 않는다(이슈의 주의사항).
 */
class UsageStats extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = ConciergeUsageResource::class;

    protected string $view = 'concierge::filament.admin.usage-stats';

    public function getTitle(): string
    {
        return trans('concierge::strings.usage_title');
    }

    /** @return array<class-string> */
    protected function getHeaderWidgets(): array
    {
        return [UsageTabs::class];
    }

    public function table(Table $table): Table
    {
        $rule = UsageLimiter::rules(ConciergeSettings::current())[0] ?? null;
        $userRule = ($rule['scope'] ?? null) === 'user' ? $rule : null;

        return $table
            ->query(fn (): Builder => $this->statsQuery($userRule))
            ->defaultSort('m_total', 'desc')
            ->paginated([25, 50])
            ->columns(array_values(array_filter([
                TextColumn::make('username')
                    ->label(trans('concierge::strings.field_user'))
                    ->searchable(),

                // 한도 사용률 — 채팅 게이지와 같은 창·같은 눈. 규칙이 사용자별일 때만
                // 열이 존재한다(패널 전체·무제한이면 사용자별 %가 성립하지 않는다).
                $userRule === null ? null : TextColumn::make('limit_used')
                    ->label(trans('concierge::strings.stats_col_limit'))
                    ->badge()
                    ->alignRight()
                    ->state(fn ($record) => (int) floor(((int) $record->limit_used) / $userRule['amount'] * 100) . '%')
                    ->color(fn ($record) => match (true) {
                        (int) $record->limit_used >= $userRule['amount'] => 'danger',
                        (int) $record->limit_used >= $userRule['amount'] * 0.8 => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),

                $this->windowColumn('total', trans('concierge::strings.stats_col_total')),
                $this->windowColumn('1d', trans('concierge::strings.stats_col_1d')),
                $this->windowColumn('7d', trans('concierge::strings.stats_col_7d')),
                $this->windowColumn('30d', trans('concierge::strings.stats_col_30d')),
            ])));
    }

    /** 창 하나 = 열 하나 — 메시지 수를 크게, 토큰을 설명줄로. */
    private function windowColumn(string $key, string $label): TextColumn
    {
        return TextColumn::make("m_{$key}")
            ->label($label)
            ->alignRight()
            ->state(fn ($record) => number_format((int) $record->{"m_{$key}"}))
            ->description(fn ($record) => number_format((int) $record->{"t_{$key}"}) . ' ' . trans('concierge::strings.stats_tokens'))
            ->sortable();
    }

    /**
     * @param  ?array{metric: string, scope: string, period: string, amount: int}  $userRule
     */
    private function statsQuery(?array $userRule): Builder
    {
        $windows = [
            '1d' => Carbon::now()->subDay(),
            '7d' => Carbon::now()->subDays(7),
            '30d' => Carbon::now()->subDays(30),
        ];

        $agg = ConciergeUsage::query()
            ->selectRaw('user_id')
            ->selectRaw('count(*) as m_total')
            ->selectRaw('coalesce(sum(input_tokens + output_tokens), 0) as t_total');

        foreach ($windows as $key => $since) {
            $at = $since->format('Y-m-d H:i:s');
            $agg->selectRaw("count(case when created_at >= ? then 1 end) as m_{$key}", [$at]);
            $agg->selectRaw("coalesce(sum(case when created_at >= ? then input_tokens + output_tokens end), 0) as t_{$key}", [$at]);
        }

        if ($userRule !== null) {
            $at = UsageLimiter::windowStart($userRule['period'])->format('Y-m-d H:i:s');

            if ($userRule['metric'] === 'messages') {
                // 한도 판정과 같은 눈 — 카드 결정·차단 시도는 세지 않는다.
                $counted = "'" . implode("', '", [ConciergeUsage::STATUS_OK, ConciergeUsage::STATUS_ERROR, ConciergeUsage::STATUS_AWAITING]) . "'";
                $agg->selectRaw("count(case when created_at >= ? and status in ({$counted}) then 1 end) as limit_used", [$at]);
            } else {
                $agg->selectRaw('coalesce(sum(case when created_at >= ? then input_tokens + output_tokens end), 0) as limit_used', [$at]);
            }
        }

        $agg->groupBy('user_id');

        return User::query()
            ->joinSub($agg, 'agg', 'agg.user_id', '=', 'users.id')
            ->select('users.*')
            ->addSelect('agg.*');
    }
}
