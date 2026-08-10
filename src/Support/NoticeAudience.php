<?php

namespace WisdomIT\Concierge\Support;

use App\Enums\SubuserPermission;
use Illuminate\Database\Eloquent\Builder;

/**
 * 선제 알림을 **누가 받는가** (#48).
 *
 * 🔴 처음에는 전부 `owner_id` 기준이었다 — 그래서 친구(서브유저)는 자기가 켠 서버의 알림도
 *    못 받았다. 이 플랫폼은 친구들과 함께 쓰는 것이 목적이라 그건 결함이다.
 *
 * 다만 **아무에게나 보내면 안 된다.** 알림에는 카드가 붙고 카드는 실제로 무언가를 한다 —
 * 정지 카드는 `control.stop` 이 있는 사람에게만 의미가 있다. 그래서 알림마다 "그 결정을
 * 내릴 수 있는 사람"으로 범위를 정한다.
 */
final class NoticeAudience
{
    /**
     * 이 사용자가 **그 권한을 가지고** 관여하는 서버들로 좁힌다.
     *
     * 소유자는 언제나 포함된다(서브유저 권한과 무관하게 자기 서버의 모든 권한을 가진다).
     *
     * @param  Builder<\App\Models\Server>  $query
     * @return Builder<\App\Models\Server>
     */
    public static function scope(Builder $query, int $userId, SubuserPermission $permission): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('owner_id', $userId)
            ->orWhereHas('subusers', fn (Builder $s) => $s
                ->where('user_id', $userId)
                // 권한 목록은 JSON 배열이다. 화면이 주는 것과 같은 문자열을 그대로 본다.
                ->whereJsonContains('permissions', $permission->value)));
    }
}
