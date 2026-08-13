<?php

namespace WisdomIT\Concierge\Models;

use App\Models\Server;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 서버 하나의 설치 완료 판정 결과 (#7). 서버당 한 행이고 재설치할 때마다 갱신된다.
 *
 * @property int $id
 * @property int $server_id
 * @property string $status
 * @property int $attempts
 * @property ?int $observed_bytes
 * @property ?int $floor_mb
 * @property Carbon $updated_at
 */
class ConciergeInstallCheck extends Model
{
    /** 설치가 끝까지 정상으로 끝났다. */
    public const STATUS_OK = 'ok';

    /** 설치 로그가 실패를 가리킨다. `reason` 에 근거가 있다. */
    public const STATUS_FAILED = 'failed';

    /** 판정하지 못했다(로그를 못 읽음·모델 호출 실패). **실패로 단정하지 않는다.** */
    public const STATUS_UNKNOWN = 'unknown';

    protected $table = 'concierge_install_checks';

    protected $fillable = [
        'server_id',
        'status',
        'reason',
        'attempts',
        'observed_bytes',
        'floor_mb',
        'notified_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'observed_bytes' => 'integer',
            'floor_mb' => 'integer',
            'notified_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * 아직 사용자에게 알리지 않은 판정들. 사이드바가 이걸 집어 말을 건다.
     *
     * ⚠ **성공도 알린다.** 설치는 몇 분 걸리고 그동안 사용자는 다른 화면을 보고 있다.
     *   실패만 알리면 성공했을 때는 아무 말이 없어서, 사용자가 "다 됐냐"고 물어봐야 한다 —
     *   기다리게 만든 쪽이 먼저 끝났다고 말하는 게 맞다.
     *
     * ⚠ `notified_at` 이 유일한 중복 방지 장치다. 알린 뒤 반드시 찍을 것.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, self>
     */
    public static function undeliveredFor(int $userId): \Illuminate\Database\Eloquent\Collection
    {
        return static::query()
            // 판정하지 못한 것(unknown)은 알리지 않는다 — 할 말이 없다.
            ->whereIn('status', [self::STATUS_FAILED, self::STATUS_OK])
            ->whereNull('notified_at')
            // 남의 서버 실패를 알려주면 안 된다. 소유자에게만 간다 —
            // 서브유저는 개설한 당사자가 아니므로 재설치를 결정할 위치가 아니다.
            // ⚠ 여기만 **소유자 전용**이다(#48). 실패 알림에는 재설치 카드가 붙고, 재설치는
            //   서버를 통째로 다시 까는 일이라 친구가 결정할 것이 아니다. 서브유저 권한에도
            //   재설치에 해당하는 항목이 없다.
            ->whereHas('server', fn ($query) => $query->where('owner_id', $userId))
            ->with('server')
            ->get();
    }

    public function markNotified(): void
    {
        $this->forceFill(['notified_at' => now()])->save();
    }

    /**
     * 한 줄 판정. `get_server_status` 가 그대로 실어 보낸다 — **모델이 읽는 글이라
     * 영어다**(#79). 사용자에게는 모델이 그 사람의 언어로 옮겨 말한다.
     */
    public function summary(): string
    {
        return match ($this->status) {
            self::STATUS_OK => 'The install logs confirm it installed correctly.',
            self::STATUS_FAILED => '⚠ The install did not finish properly: ' . $this->reason
                . ' (it has to be reinstalled)',
            default => 'The install logs could not be checked.',
        };
    }
}
