<?php

namespace WisdomIT\Concierge\Models;

use App\Models\Backup;
use App\Models\Server;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * 백업·복원이 끝나기를 기다리는 표시 (#36).
 *
 * 상태 자체는 갖지 않는다 — 완료 여부는 `Backup.completed_at`(백업)과 `Server.status`(복원)가
 * 이미 안다. 이 행이 대답하는 질문은 **"아직 사용자에게 말하지 않았는가"** 하나뿐이다.
 *
 * @property int $id
 * @property int $server_id
 * @property ?int $user_id
 * @property ?string $backup_uuid
 * @property string $kind
 * @property ?Carbon $notified_at
 */
class ConciergeBackupWatch extends Model
{
    public const KIND_BACKUP = 'backup';

    public const KIND_RESTORE = 'restore';

    /**
     * 이만큼 지나도 안 끝나면 감시를 접는다.
     *
     * ⚠ 없으면 실패한 백업을 영원히 기다린다 — 폴링만 늘고 알림은 영영 안 간다.
     */
    private const TTL_HOURS = 6;

    protected $table = 'concierge_backup_watches';

    protected $fillable = ['server_id', 'user_id', 'backup_uuid', 'kind', 'notified_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['notified_at' => 'datetime'];
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function backup(): ?Backup
    {
        return $this->backup_uuid === null
            ? null
            : Backup::query()->where('uuid', $this->backup_uuid)->first();
    }

    /**
     * 끝나서 알릴 때가 된 것들.
     *
     * ⚠ **시킨 사람에게** 간다(#48). 친구가 백업을 눌렀는데 결과가 주인에게 가면 안 된다.
     *   user_id 가 없는 옛 행은 소유자에게 남겨 둔다.
     *
     * @return Collection<int, self>
     */
    public static function readyFor(int $userId): Collection
    {
        return static::query()
            ->whereNull('notified_at')
            ->where('created_at', '>=', now()->subHours(self::TTL_HOURS))
            ->where(fn ($query) => $query
                ->where('user_id', $userId)
                ->orWhere(fn ($q) => $q->whereNull('user_id')
                    ->whereHas('server', fn ($s) => $s->where('owner_id', $userId))))
            ->with('server')
            ->get()
            ->filter(fn (self $watch) => $watch->isDone())
            ->values();
    }

    /** 오래된 감시는 조용히 접는다(호출부에서 정리). */
    public static function pruneStale(): int
    {
        return static::query()
            ->whereNull('notified_at')
            ->where('created_at', '<', now()->subHours(self::TTL_HOURS))
            ->delete();
    }

    /**
     * 끝났는가.
     *
     * - 백업: 데몬이 완료를 알리면 `completed_at` 이 찍힌다 (실패도 찍힌다 — is_successful 로 가른다)
     * - 복원: 복원 중에는 서버 `status` 가 restoring_backup 이고, 끝나면 null 로 돌아온다
     */
    public function isDone(): bool
    {
        if ($this->kind === self::KIND_RESTORE) {
            return $this->server?->status === null;
        }

        return $this->backup()?->completed_at !== null;
    }

    public function succeeded(): bool
    {
        return $this->kind === self::KIND_RESTORE
            ? $this->server?->status === null
            : (bool) $this->backup()?->is_successful;
    }

    public function markNotified(): void
    {
        $this->forceFill(['notified_at' => now()])->save();
    }
}
