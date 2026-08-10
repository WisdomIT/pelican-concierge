<?php

namespace WisdomIT\Concierge\Support;

use App\Models\Allocation;

/**
 * 개설 허용 포트 풀 (#7·#27).
 *
 * `UCS_DEPLOYMENT_PORTS` 가 **예약 포트(25565)를 지키는 유일한 수단**이다 —
 * 풀 밖 포트는 후보에서 구조적으로 빠진다. 개설과 포트 추가가 같은 규칙을 써야 하므로
 * 한 곳에 둔다.
 *
 * UCS 가 **없을 때의 폴백은 "제한 없음"이다(#15 확정)** — 빈 풀이면 노드의 아무 빈
 * 할당이나 쓴다. 개설이 계속 되는 대신 예약 포트 보호가 사라진다는 뜻이고, README 의
 * 연동 표에 그렇게 적혀 있다. 일부러 usable() 이 아니라 config 를 읽는다: 패널은 꺼진
 * 플러그인의 config 도 로드하므로, UCS 를 꺼도 포트 제한(인프라 사실)은 유지된다.
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
