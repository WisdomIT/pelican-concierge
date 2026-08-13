<?php

namespace WisdomIT\Concierge\Tools;

use App\Enums\RolePermissionModels;
use App\Enums\RolePermissionPrefixes;
use App\Models\User;
use WisdomIT\Concierge\Services\ServerProvisioner;

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

    private ?bool $canCreate = null;

    public function __construct(private readonly User $user) {}

    /**
     * 받을 도구 갈래들.
     *
     * @return array<int, ToolGroup>
     */
    public function groups(): array
    {
        $groups = [ToolGroup::Care];

        // 개설은 UCS 가 있거나 패널의 create server 권한이 있어야 한다(#17). 못 하는
        // 사람에게 개설 도구를 쥐여 주면 불러 보고 실패한다 — 왕복이 낭비되고, 그보다
        // 나쁘게는 에이전트가 해 줄 수 있는 일처럼 말하게 된다(#48).
        if ($this->canCreateServers()) {
            $groups[] = ToolGroup::Create;
        }

        if ($this->isPanelAdmin()) {
            $groups[] = ToolGroup::Admin;
        }

        return $groups;
    }

    /** 이 사람이 서버를 만들 수 있는가 — 판정은 ServerProvisioner 가 이미 갖고 있다. */
    public function canCreateServers(): bool
    {
        return $this->canCreate ??= ServerProvisioner::creationGate($this->user) === null;
    }

    public function has(ToolGroup $group): bool
    {
        return in_array($group, $this->groups(), true);
    }

    /**
     * 관리 도구를 하나라도 쓸 수 있는 사람인가 — 그게 Admin 그룹을 받을 조건이다.
     *
     * ⚠ 한때 패널 리소스 권한(RolePermissionModels)만 훑었다. 그러면 이 플러그인이 등록한
     *   권한(`viewList wisdomAgent`)만 가진 사람이 **노출에서는 빠지는데 실행은 통과**한다 —
     *   두 겹이 갈리는 것이고, 실측에서 그랬다(#97). 판정을 도구 목록 자체에서 끌어오면
     *   "쓸 수 있는 도구가 있다 = 그룹을 받는다" 가 정의상 어긋날 수 없다.
     */
    public function isPanelAdmin(): bool
    {
        return $this->panelAdmin ??= AgentToolbox::hasAnyAdminTool($this);
    }

    private ?bool $panelAdmin = null;

    /**
     * 리소스별 권한 — 어드민 도구는 낱개로 이걸 물어 붙는다(#46).
     *
     * 예: `can(Prefixes::ViewAny, Models::Node)` = "노드 목록을 볼 수 있는가".
     */
    public function can(RolePermissionPrefixes $prefix, RolePermissionModels $model): bool
    {
        return $this->canPermission($prefix->value . ' ' . $model->value);
    }

    /**
     * 패널 리소스가 아닌 권한 — 이 플러그인이 직접 등록한 묶음(`wisdomAgent`)이 그렇다.
     *
     * ⚠ **화면과 도구는 같은 권한을 써야 한다**(#97). 사용량 화면은 정책에서
     *   `viewList wisdomAgent` 를 묻는데 도구는 `viewList user` 를 물어, 운영자가 사용량
     *   접근을 준 적 없는 사람이 대화로는 볼 수 있었다. 새 도구를 만들 때 그 데이터를
     *   보여주는 화면이 있다면 **그 화면의 정책과 같은 권한**을 쓸 것.
     */
    public function canPermission(string $permission): bool
    {
        return $this->checked[$permission] ??= $this->user->can($permission);
    }
}
