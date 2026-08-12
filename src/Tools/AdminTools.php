<?php

namespace WisdomIT\Concierge\Tools;

use App\Models\ActivityLog;
use App\Models\Allocation;
use App\Models\Node;
use App\Models\Role;
use App\Models\Server;
use App\Models\User;

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
