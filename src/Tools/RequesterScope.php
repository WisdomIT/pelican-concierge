<?php

namespace WisdomIT\Concierge\Tools;

use App\Enums\RolePermissionModels;
use App\Enums\RolePermissionPrefixes;
use App\Models\User;

/**
 * 이 요청자가 어느 갈래의 도구를 받는가 (#45).
 *
 * 에이전트의 능력은 **요청자 권한의 거울**이다(#36) — 화면에서 못 하는 일은 에이전트로도
 * 못 한다. 그 판정을 한 곳에 모은다: 도구 노출도, 시스템 프롬프트의 절도 여기를 본다.
 *
 * ⚠ 어드민 여부는 `root_admin` 한 줄로 재지 않는다. Pelican 은 역할마다 리소스별 권한
 *   (`viewList node`, `update user` …)을 따로 주므로, "노드만 보는 관리자"는 노드 도구만
 *   받아야 한다. 여기서 리소스별로 묻고, 도구도 리소스별로 붙는다(#46).
 */
final class RequesterScope
{
    /** @var array<string, bool> 권한 문자열 → 허용 여부. 한 요청에서 여러 번 묻는다. */
    private array $checked = [];

    public function __construct(private readonly User $user) {}

    /**
     * 받을 도구 갈래들.
     *
     * @return array<int, ToolGroup>
     */
    public function groups(): array
    {
        $groups = [ToolGroup::Care, ToolGroup::Create];

        if ($this->isPanelAdmin()) {
            $groups[] = ToolGroup::Admin;
        }

        return $groups;
    }

    public function has(ToolGroup $group): bool
    {
        return in_array($group, $this->groups(), true);
    }

    /** 관리 화면을 조금이라도 쓸 수 있는 사람인가. */
    public function isPanelAdmin(): bool
    {
        foreach (RolePermissionModels::cases() as $model) {
            if ($this->can(RolePermissionPrefixes::ViewAny, $model)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 리소스별 권한 — 어드민 도구는 낱개로 이걸 물어 붙는다(#46).
     *
     * 예: `can(Prefixes::ViewAny, Models::Node)` = "노드 목록을 볼 수 있는가".
     */
    public function can(RolePermissionPrefixes $prefix, RolePermissionModels $model): bool
    {
        $permission = $prefix->value . ' ' . $model->value;

        return $this->checked[$permission] ??= $this->user->can($permission);
    }
}
