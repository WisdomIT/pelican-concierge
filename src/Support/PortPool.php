<?php

namespace WisdomIT\WisdomAiAssistant\Support;

use App\Models\Allocation;

/**
 * 개설 허용 포트 풀 (#7·#27).
 *
 * `UCS_DEPLOYMENT_PORTS` 가 **예약 포트(25565)를 지키는 유일한 수단**이다 —
 * 풀 밖 포트는 후보에서 구조적으로 빠진다. 개설과 포트 추가가 같은 규칙을 써야 하므로
 * 한 곳에 둔다.
 */
final class PortPool
{
    /** @return array<int, int> */
    public static function allowedPorts(): array
    {
        $ports = [];

        foreach (array_filter(explode(',', (string) config('user-creatable-servers.deployment_ports', ''))) as $part) {
            if (str_contains($part, '-')) {
                [$from, $to] = array_map('intval', explode('-', $part, 2));
                $ports = array_merge($ports, range($from, $to));

                continue;
            }

            $ports[] = (int) $part;
        }

        return $ports;
    }

    /**
     * 노드에서 풀 안의 빈 할당 하나. 없으면 null.
     *
     * ⚠ `withoutGlobalScopes()` — 테넌트 패널에서 Allocation 이 현재 서버로 스코프되는
     *   함정(#7 실측)은 여기서도 똑같이 적용된다.
     */
    public static function firstFree(int $nodeId): ?Allocation
    {
        $ports = self::allowedPorts();

        return Allocation::query()->withoutGlobalScopes()
            ->where('node_id', $nodeId)
            ->whereNull('server_id')
            ->when($ports !== [], fn ($query) => $query->whereIn('port', $ports))
            ->orderBy('port')
            ->first();
    }
}
