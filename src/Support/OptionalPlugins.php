<?php

namespace WisdomIT\Concierge\Support;

use App\Enums\PluginStatus;
use App\Models\Plugin;
use Throwable;

/**
 * 다른 플러그인 다섯과의 연동을 **한 곳에서** 판정한다 (#13).
 *
 * 🔴 `class_exists` 는 판정 근거가 못 된다. PluginService 는 PSR-4 오토로드를 **먼저**
 *    등록하고 나서 비활성 플러그인을 걸러낸다("Always autoload src directory" — 코어 주석).
 *    그래서 설치됐지만 **꺼둔** 플러그인에도 class_exists 는 true 다 — 프로바이더는
 *    부팅되지 않아 바인딩도 서비스도 없는데, 호출하면 대화 도중 예외로 터진다.
 *    관리자가 일부러 끈 플러그인이 그런 식으로 되살아나면 안 된다. **패널에 묻는다.**
 *
 * 판정은 요청 안에서만 메모이즈한다 — 관리자가 플러그인을 설치해 경고를 없앴는데
 * 경고가 계속 보이면 안 된다(#13 의 요구).
 */
final class OptionalPlugins
{
    /**
     * 연동 대상과 **검증된 최소 버전**(우리가 개발·실측한 버전).
     * 그 아래라고 막지는 않는다 — 남의 패널의 남의 플러그인이 패치 하나 뒤졌다고
     * 동작을 거부하면 불일치보다 나쁘다. 경고만 한다.
     *
     * @var array<string, string>
     */
    public const KNOWN = [
        'player-counter' => '1.0.2',
        'minecraft-modrinth' => '1.1.1',
        'rust-umod' => '1.0.0',
        'user-creatable-servers' => '1.1.1',
        'factorio-mod-installer' => '1.2.5',
    ];

    /** @var array<string, ?Plugin>|null */
    private static ?array $rows = null;

    /** 이 연동을 지금 호출해도 되는가 — 설치되어 있고 **켜져** 있는가. */
    public static function usable(string $id): bool
    {
        return self::row($id)?->status === PluginStatus::Enabled;
    }

    /** null = 설치 자체가 안 됨(디렉터리 없음). */
    public static function status(string $id): ?PluginStatus
    {
        return self::row($id)?->status;
    }

    public static function version(string $id): ?string
    {
        return self::row($id)?->version;
    }

    /** 켜져는 있는데 우리가 검증한 버전보다 낮은가 — 경고용, 차단 아님. */
    public static function belowKnownVersion(string $id): bool
    {
        $version = self::version($id);
        $known = self::KNOWN[$id] ?? null;

        return $version !== null && $known !== null
            && self::usable($id)
            && version_compare($version, $known, '<');
    }

    private static function row(string $id): ?Plugin
    {
        if (self::$rows === null) {
            try {
                // 한 요청에서 여러 연동을 여러 번 묻는다(도구·링크·위젯) — 한 번만 읽는다.
                self::$rows = Plugin::query()
                    ->whereIn('id', array_keys(self::KNOWN))
                    ->get()
                    ->keyBy('id')
                    ->all();
            } catch (Throwable) {
                // 패널이 반쯤 부팅된 구간에서도 죽지 않는다 — "없음"으로 판정하면
                // 연동 기능만 빠지고 나머지는 동작한다.
                self::$rows = [];
            }
        }

        return self::$rows[$id] ?? null;
    }
}
