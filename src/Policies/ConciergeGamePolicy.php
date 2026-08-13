<?php

namespace WisdomIT\Concierge\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * 게임 카탈로그의 접근 제어 (#81 후속 · #91).
 *
 * 🔴 **정책이 없으면 화면이 열린다.** 리소스에 canViewAny 를 두지 않고 모델에 정책도 없으면
 *    Filament 는 통과시킨다 — 실측에서 관리자가 아닌 사용자(test·ebo)도 카탈로그 화면에
 *    접근할 수 있었다. 카탈로그는 어느 게임을 만들 수 있는지를 정하는 운영자 데이터다.
 *
 * ⚠ 기준은 **egg 권한**이다(#91 결정). 카탈로그는 egg 위에 얹힌 계층이고, 에이전트 도구도
 *   같은 권한을 요구한다(읽기 viewList egg / 쓰기 update egg) — 화면과 도구의 기준이
 *   갈리면 "화면에서는 되는데 대화로는 안 된다"가 생긴다.
 *
 * 사용량 로그(ConciergeUsagePolicy)가 `wisdomAgent` 를 쓰는 것과 다른 이유: 그쪽은 이
 * 플러그인이 남긴 기록이라 플러그인 권한이 맞고, 카탈로그는 **패널의 게임 정의**에 대한
 * 것이라 egg 를 따르는 편이 운영자의 직관과 맞는다.
 *
 * 클래스 위치가 곧 등록이다 — Laravel 이 Models\X → Policies\XPolicy 로 자동 탐색한다.
 * Root Admin 은 AppServiceProvider 의 Gate::before 로 전부 통과한다.
 */
class ConciergeGamePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('viewList egg');
    }

    public function view(User $user, Model $model): bool
    {
        return $user->can('view egg');
    }

    public function create(User $user): bool
    {
        return $user->can('update egg');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->can('update egg');
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->can('update egg');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('update egg');
    }

    /** 카탈로그는 되살릴 대상이 아니다(soft delete 를 쓰지 않는다). */
    public function restore(User $user, Model $model): bool
    {
        return false;
    }

    public function forceDelete(User $user, Model $model): bool
    {
        return false;
    }
}
