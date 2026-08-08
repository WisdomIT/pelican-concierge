<?php

namespace WisdomIT\WisdomAiAssistant\Support;

use Filament\Facades\Filament;

/**
 * 🔴 테넌시를 끄고 실행한다 (#7·#27).
 *
 * Filament v4 는 테넌트 패널의 리소스 모델(Allocation·Backup 등)에 전역 스코프를 건다.
 * 에이전트는 **서버 화면 안에서도** 도는데, 특정 서버가 아니라 사용자의 모든 서버를
 * 다루므로 그 스코프가 걸리면 전부 어긋난다. 실측으로 세 번 물렸다:
 *
 *  1. 빈 할당 찾기가 현재 서버 것만 봄 → "남은 포트가 없습니다" 오탐
 *  2. 개설 시 wings 설정의 할당이 비어 500 → 한도만 차지하는 좀비 서버
 *  3. **알림의 접속 주소가 `-`** — `$server->allocation` 이 다른 서버 화면에서 null
 *
 * 권한은 테넌시가 아니라 `serverFor()`/`accessibleServers()` 가 지킨다.
 */
final class Tenancy
{
    public static function without(callable $callback): mixed
    {
        $tenant = Filament::getTenant();

        if ($tenant === null) {
            return $callback();
        }

        Filament::setTenant(null, isQuiet: true);

        try {
            return $callback();
        } finally {
            Filament::setTenant($tenant, isQuiet: true);
        }
    }
}
