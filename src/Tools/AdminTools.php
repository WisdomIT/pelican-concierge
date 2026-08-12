<?php

namespace WisdomIT\Concierge\Tools;

use App\Enums\SuspendAction;
use App\Models\ActivityLog;
use App\Models\Allocation;
use App\Models\Node;
use App\Models\Role;
use App\Models\Server;
use App\Models\User;
use App\Services\Allocations\AssignmentService;
use App\Services\Servers\DetailsModificationService;
use App\Services\Servers\SuspensionService;
use App\Services\Users\UserCreationService;
use App\Services\Users\UserUpdateService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Password;
use Spatie\Permission\Models\Permission;

/**
 * 패널 관리 화면을 **읽는** 도구들 (#46).
 *
 * 원칙은 #36 그대로다 — 요청자가 관리 화면에서 볼 수 있는 것만 본다. 노출은
 * RequesterScope 가 리소스별 권한으로 거르고(AgentToolbox::ADMIN_TOOL_PERMISSIONS),
 * 여기서는 조회만 한다. **바꾸는 도구는 하나도 없다**(변경은 #47).
 *
 * 출력은 사람이 읽는 요약이다 — 모델이 그대로 옮겨 적어도 말이 되게 쓴다.
 * 화면 한 번이면 알 것을 모델이 열 번 물어보게 만들지 않는 것이 목표다.
 */
final class AdminTools
{
    /** 노드가 죽었는지 산 채로 물어보는 데 쓰는 값 — statistics() 는 실패해도 0 을 준다. */
    private const OFFLINE = 'unreachable (wings is not answering)';

    public function __construct(private readonly User $user) {}

    /** 노드 전체 — 어느 노드가 죽었는지, 어디가 꽉 찼는지 한눈에. */
    public function listNodes(): string
    {
        $nodes = Node::query()->withCount('servers')->orderBy('id')->get();

        if ($nodes->isEmpty()) {
            return 'No nodes exist on this panel yet.';
        }

        $lines = $nodes->map(function (Node $node) {
            $stats = $node->statistics();
            $live = ((int) ($stats['memory_total'] ?? 0)) > 0;

            $allocated = $node->servers()->sum('memory');

            return sprintf(
                "- %s (id %d)%s — %d servers, %s\n"
                . "  memory: %s allocated of %s configured%s\n"
                . "  disk: %s allocated of %s configured",
                $node->name,
                $node->id,
                $node->isUnderMaintenance() ? ' **maintenance mode**' : '',
                $node->servers_count,
                $live
                    ? sprintf(
                        'host in use: %s memory, %s disk, cpu %s%%',
                        $this->bytes((int) $stats['memory_used']),
                        $this->bytes((int) $stats['disk_used']),
                        round((float) $stats['cpu_percent'], 1),
                    )
                    : self::OFFLINE,
                $this->mib((int) $allocated),
                $this->mib((int) $node->memory),
                $node->memory_overallocate > 0 ? " (overallocate {$node->memory_overallocate}%)" : '',
                $this->mib((int) $node->servers()->sum('disk')),
                $this->mib((int) $node->disk),
            );
        });

        return "Nodes on this panel:\n" . $lines->implode("\n");
    }

    /** 노드 하나를 깊이 — 접속이 되는지, 무엇이 돌고 있는지. */
    public function getNodeStatus(array $input): string
    {
        $node = $this->resolveNode($input);
        $stats = $node->statistics();
        $system = $node->systemInformation();
        $live = ((int) ($stats['memory_total'] ?? 0)) > 0;

        $servers = $node->servers()->orderBy('name')->get(['id', 'name', 'memory', 'disk', 'status'])
            ->map(fn (Server $s) => sprintf(
                '  - %s (id %d) — %s memory, %s disk%s',
                $s->name,
                $s->id,
                $this->mib((int) $s->memory),
                $this->mib((int) $s->disk),
                $s->status !== null ? " [{$s->status->value}]" : '',
            ))->implode("\n");

        $wings = isset($system['exception'])
            ? "wings: NOT REACHABLE — {$system['exception']}"
            : sprintf(
                'wings %s on %s (%s, %d cpu cores, kernel %s)',
                $system['version'] ?? '?',
                $system['os'] ?? '?',
                $system['architecture'] ?? '?',
                (int) ($system['cpu_count'] ?? 0),
                $system['kernel_version'] ?? '?',
            );

        return sprintf(
            "Node %s (id %d)%s\n%s\n%s\nallocations: %d total, %d free\nservers (%d):\n%s",
            $node->name,
            $node->id,
            $node->isUnderMaintenance() ? ' — **maintenance mode: no new servers land here**' : '',
            $wings,
            $live
                ? sprintf(
                    "host usage: memory %s of %s, disk %s of %s, cpu %s%%, load %s/%s/%s",
                    $this->bytes((int) $stats['memory_used']),
                    $this->bytes((int) $stats['memory_total']),
                    $this->bytes((int) $stats['disk_used']),
                    $this->bytes((int) $stats['disk_total']),
                    round((float) $stats['cpu_percent'], 1),
                    round((float) $stats['load_average1'], 2),
                    round((float) $stats['load_average5'], 2),
                    round((float) $stats['load_average15'], 2),
                )
                : 'host usage: ' . self::OFFLINE,
            $node->allocations()->count(),
            $node->allocations()->whereNull('server_id')->count(),
            $node->servers()->count(),
            $servers === '' ? '  (none)' : $servers,
        );
    }

    /** 사용자 목록 — 누가 있고, 무엇을 갖고 있고, 언제 마지막으로 움직였나. */
    public function listPanelUsers(array $input): string
    {
        $search = trim((string) ($input['search'] ?? ''));

        $users = User::query()
            ->when($search !== '', fn ($q) => $q
                ->where(fn ($w) => $w
                    ->whereRaw('lower(username) like ?', ['%' . mb_strtolower($search) . '%'])
                    ->orWhereRaw('lower(email) like ?', ['%' . mb_strtolower($search) . '%'])))
            ->withCount('servers')
            // 소유 서버 이름까지 준다 — 개수만 주면 모델이 "무엇을 갖고 있나"를 알아내려고
            // 서버 목록·노드를 헤매고 다닌다(실측).
            ->with(['roles', 'servers:id,owner_id,name'])
            ->orderBy('id')
            ->limit(50)
            ->get();

        if ($users->isEmpty()) {
            return $search === '' ? 'No users exist.' : "No user matches \"{$search}\".";
        }

        // 마지막 활동은 한 번의 조회로 모아 온다 — 사용자마다 묻지 않는다.
        $lastSeen = ActivityLog::query()
            ->where('actor_type', (new User())->getMorphClass())
            ->whereIn('actor_id', $users->pluck('id'))
            ->selectRaw('actor_id, max(timestamp) as last_at')
            ->groupBy('actor_id')
            ->pluck('last_at', 'actor_id');

        $lines = $users->map(function (User $u) use ($lastSeen) {
            $roles = $u->roles->pluck('name')->implode(', ');

            $owned = $u->servers->take(5)->pluck('name')->implode(', ');

            return sprintf(
                '- %s (id %d) — %d servers%s%s%s',
                $u->username,
                $u->id,
                $u->servers_count,
                $owned === '' ? '' : " ({$owned}" . ($u->servers_count > 5 ? ', …' : '') . ')',
                $roles === '' ? '' : ", roles: {$roles}",
                isset($lastSeen[$u->id]) ? ', last activity ' . $lastSeen[$u->id] : ', never active',
            );
        });

        return sprintf("Users (%d shown%s):\n%s", $users->count(), $search === '' ? ', newest ids last' : ", matching \"{$search}\"", $lines->implode("\n"));
    }

    /** 역할과 그 권한 — "이 사람이 왜 그걸 못 하지"의 답이 여기 있다. */
    public function listRoles(): string
    {
        $roles = Role::query()->with('permissions')->withCount('users')->orderBy('id')->get();

        $lines = $roles->map(function (Role $role) {
            // Root Admin 은 권한 표에 아무것도 없이 전부 통과한다 — 그대로 적으면 오해한다.
            if ($role->name === Role::ROOT_ADMIN) {
                return sprintf('- %s (id %d) — %d users — **implicitly holds every permission**', $role->name, $role->id, $role->users_count);
            }

            $permissions = $role->permissions->pluck('name')->sort()->values();

            return sprintf(
                "- %s (id %d) — %d users — %d permissions%s",
                $role->name,
                $role->id,
                $role->users_count,
                $permissions->count(),
                $permissions->isEmpty() ? '' : ":\n  " . $permissions->implode(', '),
            );
        });

        return "Roles on this panel:\n" . $lines->implode("\n");
    }

    /** 포트 현황 — "서버를 못 만든다"의 흔한 원인이 여기다. */
    public function listNodeAllocations(array $input): string
    {
        $node = $this->resolveNode($input);

        $allocations = $node->allocations()->with('server:id,name')->orderBy('ip')->orderBy('port')->get();

        if ($allocations->isEmpty()) {
            return "Node {$node->name} has no allocations at all — nothing can be deployed here until ports are added.";
        }

        $free = $allocations->whereNull('server_id');

        // 포트는 수백 개일 수 있다 — 쓰이는 것만 낱개로, 빈 것은 범위로 접는다.
        $used = $allocations->whereNotNull('server_id')
            ->map(fn (Allocation $a) => sprintf('  - %s:%d → %s', $a->ip, $a->port, $a->server?->name ?? '?'))
            ->implode("\n");

        return sprintf(
            "Allocations on node %s: %d total, %d free, %d in use\nfree ports: %s\nin use:\n%s",
            $node->name,
            $allocations->count(),
            $free->count(),
            $allocations->count() - $free->count(),
            $free->isEmpty() ? '(none — new servers cannot be deployed here)' : $this->ranges($free->pluck('port')->all()),
            $used === '' ? '  (none)' : $used,
        );
    }

    // ── 여기부터는 바꾼다 (#47). 전부 확인 카드를 거친 뒤에만 불린다. ──

    /** 점검 모드 — 켜면 새 서버가 이 노드에 배치되지 않는다. 이미 있는 서버는 그대로 돈다. */
    public function setNodeMaintenance(array $input): string
    {
        $node = $this->resolveNode($input);
        $on = (bool) ($input['enabled'] ?? true);

        $node->update(['maintenance_mode' => $on]);

        return $on
            ? "Node {$node->name} is now in maintenance mode — no new servers will be deployed here. Existing servers keep running."
            : "Node {$node->name} is out of maintenance mode — it can take new servers again.";
    }

    /** 포트 추가 — 개설이 막히는 가장 흔한 원인이 빈 포트 부족이다. */
    public function addNodeAllocations(array $input): string
    {
        $node = $this->resolveNode($input);
        $ip = trim((string) ($input['ip'] ?? '0.0.0.0'));
        $ports = array_values(array_filter(array_map('trim', (array) ($input['ports'] ?? []))));

        if ($ports === []) {
            throw new ToolInputException('ports is required — a list like ["27600", "27700-27709"].');
        }

        $before = $node->allocations()->count();

        app(AssignmentService::class)->handle($node, [
            'allocation_ip' => $ip,
            'allocation_ports' => $ports,
        ]);

        $added = $node->allocations()->count() - $before;

        return "Added {$added} allocation(s) on {$node->name} at {$ip}. The node now has "
            . $node->allocations()->whereNull('server_id')->count() . ' free port(s).';
    }

    /** 포트 회수 — 서버가 쓰고 있으면 건드리지 않는다. */
    public function removeNodeAllocation(array $input): string
    {
        $allocation = $this->resolveAllocation($input);
        $label = $allocation->ip . ':' . $allocation->port;
        $node = $allocation->node->name;

        $allocation->delete();

        return "Removed allocation {$label} from node {$node}.";
    }

    /** 정지·해제 — 정지된 서버는 꺼지고 주인도 켤 수 없다. */
    public function setServerSuspended(array $input): string
    {
        $server = $this->resolveServer($input);
        $suspend = (bool) ($input['suspended'] ?? true);

        app(SuspensionService::class)->handle($server, $suspend ? SuspendAction::Suspend : SuspendAction::Unsuspend);

        return $suspend
            ? "Server {$server->name} is suspended — it has been stopped and its owner cannot start it."
            : "Server {$server->name} is no longer suspended — its owner can start it again.";
    }

    /**
     * 사용자 만들기 — 비밀번호는 **받지 않는다.**
     *
     * 비워 두면 패널이 무작위 값을 넣고 초대 메일(재설정 링크)을 보낸다. 채팅으로
     * 비밀번호를 주고받으면 대화 기록에 남는다 — 그 경로를 아예 만들지 않는다.
     */
    public function createPanelUser(array $input): string
    {
        $email = trim((string) ($input['email'] ?? ''));
        $username = trim((string) ($input['username'] ?? ''));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ToolInputException('A valid email is required.');
        }

        if (User::query()->whereRaw('lower(email) = ?', [mb_strtolower($email)])->exists()) {
            throw new ToolInputException("A user with email {$email} already exists.");
        }

        if ($username !== '' && User::query()->whereRaw('lower(username) = ?', [mb_strtolower($username)])->exists()) {
            throw new ToolInputException("The username \"{$username}\" is taken.");
        }

        $user = app(UserCreationService::class)->handle(array_filter([
            'email' => $email,
            'username' => $username ?: null,
        ]));

        return "Created user {$user->username} ({$email}). They were emailed a link to set their own password — "
            . 'no password was set here.';
    }

    /** 역할 부여·회수 — "왜 못 하지"의 반대편. */
    public function setUserRole(array $input): string
    {
        [$user, $role] = $this->resolveUserRole($input);
        $grant = (bool) ($input['granted'] ?? true);

        if ($grant) {
            $user->assignRole($role);

            return "{$user->username} now holds the role \"{$role->name}\".";
        }

        $user->removeRole($role);

        return "{$user->username} no longer holds the role \"{$role->name}\".";
    }

    /** 소유자 이전 — 서버의 주인이 바뀐다. 이전 주인은 접근을 잃는다. */
    public function transferServerOwner(array $input): string
    {
        $server = $this->resolveServer($input);
        $newOwner = $this->resolveUser((string) ($input['owner'] ?? ''));

        if ($server->owner_id === $newOwner->id) {
            throw new ToolInputException("{$newOwner->username} already owns that server.");
        }

        $previous = $server->user?->username ?? '?';

        app(DetailsModificationService::class)->handle($server, [
            'name' => $server->name,
            'owner_id' => $newOwner->id,
            'external_id' => $server->external_id,
            'description' => $server->description,
        ]);

        return "Server {$server->name} now belongs to {$newOwner->username} (was {$previous}). "
            . 'The previous owner lost access unless they are a subuser.';
    }

    /**
     * 계정 정보 수정 (#63) — **비밀번호는 다루지 않는다.**
     *
     * 바꿀 수 있는 것은 아이디·이메일·언어·시간대뿐이다. 비밀번호가 필요하면
     * send_password_reset 이 링크를 보낸다 — 채팅에 비밀번호가 오갈 자리를 만들지 않는다.
     */
    public function updatePanelUser(array $input): string
    {
        $user = $this->targetableUser((string) ($input['user'] ?? ''));

        $changes = [];

        foreach (['username', 'email', 'language', 'timezone'] as $field) {
            $value = trim((string) ($input[$field] ?? ''));

            if ($value === '' || $value === (string) $user->{$field}) {
                continue;
            }

            $changes[$field] = $value;
        }

        if ($changes === []) {
            throw new ToolInputException('Nothing to change — pass at least one of username, email, language, timezone.');
        }

        if (isset($changes['email'])) {
            if (!filter_var($changes['email'], FILTER_VALIDATE_EMAIL)) {
                throw new ToolInputException('That email is not valid.');
            }

            if (User::query()->whereRaw('lower(email) = ?', [mb_strtolower($changes['email'])])->whereKeyNot($user->id)->exists()) {
                throw new ToolInputException('Another account already uses that email.');
            }
        }

        if (isset($changes['username'])
            && User::query()->whereRaw('lower(username) = ?', [mb_strtolower($changes['username'])])->whereKeyNot($user->id)->exists()) {
            throw new ToolInputException('That username is taken.');
        }

        $before = collect($changes)->keys()->mapWithKeys(fn (string $f) => [$f => (string) $user->{$f}])->all();

        app(UserUpdateService::class)->handle($user, $changes);

        $summary = collect($changes)
            ->map(fn (string $value, string $field) => "{$field}: " . ($before[$field] ?: '(empty)') . " → {$value}")
            ->implode(', ');

        return "Updated {$user->username} — {$summary}.";
    }

    /** 비밀번호 재설정 링크 — 비밀번호를 대신 정해 주는 대신 본인이 정하게 한다. */
    public function sendPasswordReset(array $input): string
    {
        $user = $this->targetableUser((string) ($input['user'] ?? ''));

        $status = Password::broker(Filament::getPanel('app')->getAuthPasswordBroker())
            ->sendResetLink(['email' => $user->email]);

        if ($status !== Password::RESET_LINK_SENT) {
            throw new ToolInputException("Could not send the reset link ({$status}). Check the panel's mail settings.");
        }

        return "A password reset link was emailed to {$user->username}. The link expires, and nobody else can read it — "
            . 'you never see or set their password.';
    }

    /** 2단계 인증 해제 — 잠긴 사람을 들여보내는 대신 계정은 그만큼 약해진다. */
    public function clearUserMfa(array $input): string
    {
        $user = $this->targetableUser((string) ($input['user'] ?? ''));

        if (blank($user->mfa_app_secret) && !$user->mfa_email_enabled) {
            throw new ToolInputException("{$user->username} has no two-factor set up — nothing to clear.");
        }

        $user->forceFill([
            'mfa_app_secret' => null,
            'mfa_app_recovery_codes' => null,
            'mfa_email_enabled' => false,
        ])->saveOrFail();

        return "Two-factor authentication was removed for {$user->username}. They can sign in with their password alone "
            . 'until they set it up again — tell them to turn it back on.';
    }

    /**
     * 역할 만들기·권한 편집 (#63).
     *
     * 넘긴 목록이 **그 역할의 권한 전부**가 된다 — 빠진 것은 회수된다. 패널의 역할
     * 화면과 같은 규칙이고, 카드가 전체 목록을 그대로 보여준다.
     */
    public function setRolePermissions(array $input): string
    {
        $name = trim((string) ($input['role'] ?? ''));
        $permissions = $this->rolePermissions($input);

        if ($name === '') {
            throw new ToolInputException('role is required — the name of a role to edit, or a new name to create.');
        }

        $role = Role::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();
        $created = $role === null;

        if ($role !== null && $role->name === Role::ROOT_ADMIN) {
            throw new ToolInputException('Root Admin holds everything implicitly — its permissions cannot be edited.');
        }

        if ($created) {
            $role = Role::query()->create(['name' => $name, 'guard_name' => Role::DEFAULT_GUARD_NAME]);
        }

        // ⚠ Spatie 는 **이미 존재하는 권한만** 붙일 수 있다 — 패널의 역할 화면도 같은 이유로
        //   firstOrCreate 를 쓴다. 없는 이름을 그냥 넘기면 "no permission named …" 로 죽는다.
        $models = collect($permissions)->map(fn (string $name) => Permission::firstOrCreate([
            'name' => $name,
            'guard_name' => Role::DEFAULT_GUARD_NAME,
        ]));

        $role->syncPermissions($models);

        return sprintf(
            '%s the role "%s" with %d permissions: %s',
            $created ? 'Created' : 'Updated',
            $role->name,
            count($permissions),
            implode(', ', $permissions),
        );
    }

    /**
     * 역할이 받을 권한 목록을 검사한다 — 패널이 아는 이름만, 그리고 **자기가 가진 것만**.
     *
     * @param  array<string, mixed>  $input
     * @return array<int, string>
     */
    public function rolePermissions(array $input): array
    {
        $given = array_values(array_unique(array_map('trim', (array) ($input['permissions'] ?? []))));

        if ($given === []) {
            throw new ToolInputException(
                'permissions is required — a list like ["viewList node", "view node"]. '
                . 'An empty list would strip the role instead; say so explicitly if that is the intent.',
            );
        }

        $valid = [];

        foreach (Role::getPermissionList() as $model => $prefixes) {
            foreach ($prefixes as $prefix) {
                $valid[] = "{$prefix} {$model}";
            }
        }

        $unknown = array_diff($given, $valid);

        if ($unknown !== []) {
            throw new ToolInputException('Unknown permissions: ' . implode(', ', $unknown));
        }

        // ⚠ 자기가 못 하는 일을 남에게 줄 수 없다 — 서브유저 초대와 같은 규칙(#62).
        //   루트 관리자는 전부 통과하므로 이 검사에 걸리지 않는다.
        $beyond = array_values(array_filter($given, fn (string $p) => !$this->user->can($p)));

        if ($beyond !== []) {
            throw new ToolInputException(
                'You cannot grant permissions you do not hold yourself: ' . implode(', ', $beyond),
            );
        }

        return $given;
    }

    /** 손댈 수 있는 사용자인가 — 루트 관리자는 대상이 될 수 없다. */
    public function targetableUser(string $reference): User
    {
        $user = $this->resolveUser($reference);

        if (!$this->user->canTarget($user)) {
            throw new ToolInputException("You cannot change {$user->username}'s account.");
        }

        return $user;
    }

    /** @return array{0: User, 1: Role} */
    private function resolveUserRole(array $input): array
    {
        $user = $this->resolveUser((string) ($input['user'] ?? ''));
        $reference = trim((string) ($input['role'] ?? ''));

        if ($reference === '') {
            throw new ToolInputException('role is required. Check list_roles first.');
        }

        $role = Role::query()
            ->where(fn ($q) => $q->where('id', is_numeric($reference) ? (int) $reference : 0)
                ->orWhereRaw('lower(name) = ?', [mb_strtolower($reference)]))
            ->first();

        if ($role === null) {
            throw new ToolInputException("No role matches \"{$reference}\". Get the exact name from list_roles.");
        }

        // ⚠ 루트 관리자는 손댈 수 없다 — 자기보다 센 계정을 에이전트로 우회해 건드리는 길을 막는다.
        if (!$this->user->canTarget($user)) {
            throw new ToolInputException("You cannot change roles for {$user->username}.");
        }

        return [$user, $role];
    }

    public function userFacts(string $reference): User
    {
        return $this->resolveUser($reference);
    }

    /** @return array{0: User, 1: Role} */
    public function userRoleFacts(array $input): array
    {
        return $this->resolveUserRole($input);
    }

    private function resolveUser(string $reference): User
    {
        $reference = trim($reference);

        if ($reference === '') {
            throw new ToolInputException('user is required — a username, email or id.');
        }

        $user = User::query()
            ->where(fn ($q) => $q
                ->where('id', is_numeric($reference) ? (int) $reference : 0)
                ->orWhereRaw('lower(username) = ?', [mb_strtolower($reference)])
                ->orWhereRaw('lower(email) = ?', [mb_strtolower($reference)]))
            ->first();

        if ($user === null) {
            throw new ToolInputException("No user matches \"{$reference}\". Check list_panel_users.");
        }

        return $user;
    }

    /**
     * 관리자 권한으로 대상 서버 찾기 — 자기 서버가 아니어도 된다.
     *
     * ⚠ 그래도 아무나 못 만진다. `viewList server` 를 가진 사람만 이 도구를 받고(노출),
     *   여기서 canTarget 으로 그 서버의 노드에 손댈 수 있는지 다시 본다.
     */
    private function resolveServer(array $input): Server
    {
        $reference = trim((string) ($input['server'] ?? ''));

        if ($reference === '') {
            throw new ToolInputException('server is required.');
        }

        $server = Server::query()
            ->where(fn ($q) => $q
                ->where('uuid_short', $reference)
                ->orWhere('uuid', $reference)
                ->orWhere('id', is_numeric($reference) ? (int) $reference : 0)
                ->orWhereRaw('lower(name) = ?', [mb_strtolower($reference)]))
            ->with(['node', 'user'])
            ->first();

        if ($server === null) {
            throw new ToolInputException("No server matches \"{$reference}\".");
        }

        if (!$this->user->canTarget($server->node)) {
            throw new ToolInputException("That server is on node \"{$server->node->name}\", which you cannot administer.");
        }

        return $server;
    }

    private function resolveAllocation(array $input): Allocation
    {
        $node = $this->resolveNode($input);
        $port = (int) ($input['port'] ?? 0);

        $allocation = $node->allocations()->where('port', $port)
            ->when(filled($input['ip'] ?? null), fn ($q) => $q->where('ip', trim((string) $input['ip'])))
            ->with(['node', 'server'])
            ->first();

        if ($allocation === null) {
            throw new ToolInputException("Node {$node->name} has no allocation on port {$port}.");
        }

        if ($allocation->server_id !== null) {
            throw new ToolInputException(
                "Port {$port} is in use by \"{$allocation->server?->name}\" — free it on that server first.",
            );
        }

        return $allocation;
    }

    /** 카드가 보여줄 사실 — 모델이 쓴 문장이 아니라 **우리가 조회한 값**이다. */
    public function nodeFacts(array $input): Node
    {
        return $this->resolveNode($input);
    }

    public function allocationFacts(array $input): Allocation
    {
        return $this->resolveAllocation($input);
    }

    public function serverFacts(array $input): Server
    {
        return $this->resolveServer($input);
    }

    /** @param array<string, mixed> $input */
    private function resolveNode(array $input): Node
    {
        $reference = trim((string) ($input['node'] ?? ''));

        if ($reference === '') {
            throw new ToolInputException('node is required. Check list_nodes first.');
        }

        $node = Node::query()
            ->where(fn ($q) => $q->where('id', is_numeric($reference) ? (int) $reference : 0)
                ->orWhereRaw('lower(name) = ?', [mb_strtolower($reference)]))
            ->first();

        if ($node === null) {
            throw new ToolInputException("No node matches \"{$reference}\". Get the exact name or id from list_nodes.");
        }

        // ⚠ 루트가 아닌 관리자는 자기에게 열린 노드만 다룬다 — 목록 권한이 곧 전권은 아니다.
        if (!$this->user->canTarget($node)) {
            throw new ToolInputException("You do not have access to node \"{$node->name}\".");
        }

        return $node;
    }

    /** 빈 포트 목록을 27500-27599 처럼 접는다 — 수백 줄을 모델에 붓지 않는다. */
    private function ranges(array $ports): string
    {
        sort($ports);
        $ranges = [];
        $start = $previous = null;

        foreach ($ports as $port) {
            if ($start === null) {
                $start = $previous = $port;

                continue;
            }

            if ($port === $previous + 1) {
                $previous = $port;

                continue;
            }

            $ranges[] = $start === $previous ? (string) $start : "{$start}-{$previous}";
            $start = $previous = $port;
        }

        if ($start !== null) {
            $ranges[] = $start === $previous ? (string) $start : "{$start}-{$previous}";
        }

        return implode(', ', $ranges);
    }

    private function mib(int $mib): string
    {
        return $mib === 0 ? 'unlimited' : ($mib >= 1024 ? round($mib / 1024, 1) . 'GB' : $mib . 'MB');
    }

    private function bytes(int $bytes): string
    {
        return round($bytes / 1_000_000_000, 1) . 'GB';
    }
}
