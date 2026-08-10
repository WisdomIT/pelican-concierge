<?php

namespace WisdomIT\Concierge\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * 사용량 로그는 **읽기와 삭제만** 허용한다. 손으로 만들거나 고칠 수 있으면
 * 감사 기록으로서의 의미가 없어진다.
 *
 * 권한 이름의 `wisdomAgent` 는 Provider 에서 Role::registerCustomDefaultPermissions() 로
 * 등록한다. Root Admin 은 AppServiceProvider 의 Gate::before 로 전부 통과한다.
 *
 * 클래스 위치가 곧 등록이다 — Laravel 이 Models\X → Policies\XPolicy 로 자동 탐색한다.
 */
class ConciergeUsagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('viewList wisdomAgent');
    }

    public function view(User $user, Model $model): bool
    {
        return $user->can('view wisdomAgent');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Model $model): bool
    {
        return false;
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->can('delete wisdomAgent');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete wisdomAgent');
    }
}
