<?php

namespace WisdomIT\Concierge\Support;

use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * 사용자가 읽고 정하는 시각의 시간대 (#79).
 *
 * 이 플러그인에는 시간 기준이 **둘** 있고, 섞으면 안 된다:
 *
 *  · **서버 기준** — 할당량 초기화, 사용량 집계·도표. 관리자가 서버 로그와 대조하는
 *    값이라 사람마다 달라지면 안 된다. `UsageLimiter::timezone()` 이 정한다.
 *  · **사용자 기준**(여기) — 예약 시각, 백업 만든 때처럼 **사용자가 직접 정하고 읽는**
 *    값. 언어를 프로필에서 가져오듯 시간대도 프로필에서 가져온다.
 *
 * 한때 표시 시간대가 `Asia/Seoul` 로 박혀 있었다. 우리 배포에서는 맞았지만, 그건
 * 운영자의 사정이지 코드가 알 일이 아니다 — 허브에서 받은 패널에서는 모든 예약이
 * 엉뚱한 시각으로 보이고, 그대로 저장되면 **엉뚱한 시각에 돈다**.
 */
final class UserTime
{
    /** 이 사용자가 시각을 읽는 시간대. 프로필에 없으면 앱 기준으로 물러난다. */
    public static function timezoneFor(?User $user): string
    {
        $tz = trim((string) ($user->timezone ?? ''));

        if ($tz !== '' && in_array($tz, timezone_identifiers_list(), true)) {
            return $tz;
        }

        return (string) config('app.timezone', 'UTC');
    }

    /** 로그인한 사용자 기준. 배치·콘솔처럼 사용자가 없는 자리는 앱 기준이 된다. */
    public static function timezone(): string
    {
        return self::timezoneFor(auth()->user());
    }

    /**
     * 저장된 시각(UTC)을 사용자가 읽을 문자열로. 시간대를 함께 적는다 —
     * "04:00" 만 있으면 어느 시계 기준인지 알 수 없고, 예약은 그게 전부다.
     */
    public static function format(?\DateTimeInterface $at, ?User $user = null, string $format = 'Y-m-d H:i'): string
    {
        if ($at === null) {
            return '';
        }

        $tz = $user === null ? self::timezone() : self::timezoneFor($user);

        return Carbon::instance(\DateTime::createFromInterface($at))->timezone($tz)->format($format) . ' (' . $tz . ')';
    }
}
