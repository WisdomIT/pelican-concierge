<?php

namespace WisdomIT\Concierge\Models;

use App\Enums\SubuserPermission;
use App\Models\Server;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use WisdomIT\Concierge\Support\NoticeAudience;

/**
 * 서버 하나의 유휴 추적 상태 (#18).
 *
 * @property int $server_id
 * @property ?int $last_rx
 * @property ?Carbon $idle_since
 * @property ?Carbon $notified_at
 * @property ?Carbon $snoozed_at
 */
class ConciergeIdleWatch extends Model
{
    protected $table = 'concierge_idle_watches';

    protected $fillable = ['server_id', 'last_rx', 'idle_since', 'notified_at', 'snoozed_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_rx' => 'integer',
            'idle_since' => 'datetime',
            'notified_at' => 'datetime',
            'snoozed_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** 활동이 보였다. 타이머를 처음으로 되돌린다. */
    public function markActive(?int $rx): void
    {
        $this->forceFill([
            'last_rx' => $rx,
            'idle_since' => null,
            'notified_at' => null,
            'snoozed_at' => null,
        ])->save();
    }

    /** 아무도 없다. 시작 시각은 **처음 한 번만** 찍는다 — 매번 갱신하면 영영 도달하지 않는다. */
    public function markIdle(?int $rx): void
    {
        $this->forceFill([
            'last_rx' => $rx,
            'idle_since' => $this->idle_since ?? now(),
        ])->save();
    }

    /** 유휴 시작(또는 마지막 "더 켜두기") 이후 흐른 분. */
    public function idleMinutes(): int
    {
        $from = $this->snoozed_at ?? $this->idle_since;

        return $from === null ? 0 : (int) $from->diffInMinutes(now());
    }

    /**
     * 알릴 때가 된 유휴 서버들. 사이드바가 집어 사용자에게 말을 건다.
     *
     * ⚠ **임계값(분)을 여기서 거른다.** 판정 명령은 유휴 '시작'만 기록하므로, 이 필터가
     *   없으면 서버를 켜자마자 "0분째 아무도 없다"고 말을 건다 — 실제로 그렇게 됐다.
     *   기준 시각은 idleMinutes() 와 동일하게 snoozed_at 이 있으면 그쪽이다("더 켜두기" 뒤
     *   다시 처음부터 세는 규칙).
     * ⚠ 정지를 **결정할 수 있는 사람**에게만 간다(소유자 + control.stop 서브유저, #48).
     *
     * @return Collection<int, self>
     */
    public static function undeliveredFor(int $userId, int $thresholdMinutes): Collection
    {
        return static::query()
            ->whereNotNull('idle_since')
            ->whereNull('notified_at')
            // 정지 여부를 정할 수 있는 사람에게 간다 — 소유자와 control.stop 을 받은 친구(#48).
            ->whereHas('server', fn ($query) => NoticeAudience::scope($query, $userId, SubuserPermission::ControlStop))
            ->with('server')
            ->get()
            ->filter(fn (self $watch) => $watch->idleMinutes() >= $thresholdMinutes)
            ->values();
    }
}
