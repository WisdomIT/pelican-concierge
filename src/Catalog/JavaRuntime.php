<?php

namespace WisdomIT\WisdomAiAssistant\Catalog;

/**
 * 마인크래프트 버전 → 실행할 Java 이미지.
 *
 * **로더(Paper·Fabric·Forge)가 아니라 마인크래프트 버전이 결정한다.** 그래서 표가 하나뿐이고,
 * 세 카탈로그 항목이 `java_from` 으로 "어느 변수에 버전이 들어 있는지"만 알려준다.
 *
 * 근거: Minecraft Wiki — Tutorial:Update Java (https://minecraft.wiki/w/Tutorial:Update_Java)
 *   1.12 ~ 1.16.5      Java 8 이상
 *   1.17 ~ 1.17.1      Java 16 이상
 *   1.18 ~ 1.20.4      Java 17 이상
 *   1.20.5 ~ 1.21.11   Java 21 이상
 *   26.1 이상          Java 25 이상   ("Since 26.1 (26.1 Snapshot 1), Minecraft requires Java 25 or newer")
 *
 * ⚠ **2026년부터 버전 체계가 `YY.N` 으로 바뀌었다.** 26.1(2026-03-24)이 1.22 를 대체했다.
 *   그래서 `26.1` 은 `1.20.5` 보다 **큰 수**라 규칙 순서를 잘못 두면 조용히 Java 21 이 잡힌다.
 *   26.1 규칙이 반드시 맨 위에 있어야 한다.
 *
 * ⚠ **왜 egg 기본값을 쓰면 안 되는가** — `ServerCreationService` 는 이미지를 지정하지 않으면
 *   egg 의 **첫 번째** 이미지를 쓴다. 그 순서는 egg 마다 제각각이고 버전과 무관하다:
 *     Paper·Forge → java_25 가 첫 번째   (1.21 서버가 Java 25 로 뜬다)
 *     Fabric      → java_8 이 첫 번째    (1.21 서버가 Java 8 로 떠서 아예 못 뜬다)
 *   실측으로 둘 다 확인했다.
 *
 * ⚠ **요구 버전보다 높은 Java 를 쓰지 않는다.** 최신이 안전할 것 같지만 아니다 —
 *   Paper 1.21 을 Java 25 로 띄우면 내장 spark 프로파일러가 JVM 을 죽인다(기동 직후 core dump).
 *   실측 확인. 필요한 버전을 정확히 쓴다.
 */
final class JavaRuntime
{
    /**
     * 마인크래프트 버전 하한 → Java 메이저. **높은 것부터** 검사한다.
     *
     * @var array<string, int>
     */
    private const REQUIREMENTS = [
        // ⚠ 새 체계(YY.N)가 맨 위여야 한다. 26.1 은 1.20.5 보다 큰 수라 아래에 두면 안 걸린다.
        '26.1' => 25,
        '1.20.5' => 21,
        // 위키는 1.17~1.17.1 을 "Java 16 이상"으로 적지만 16 은 LTS 가 아니고 이미 EOL 이다.
        // "이상"이 허용하는 범위 안에서 17(LTS)로 올려 잡는다 — Paper 도 1.17 에 17 을 권장한다.
        '1.17' => 17,
    ];

    /** 위 표 어디에도 안 걸리는 옛 버전. */
    private const LEGACY_JAVA = 8;

    /** 버전을 알 수 없을 때(예: "latest") 쓸 값 = 표에서 가장 높은 요구 버전. */
    private const NEWEST_JAVA = 25;

    public static function imageFor(?string $minecraftVersion): string
    {
        return 'ghcr.io/pelican-eggs/yolks:java_' . self::majorFor($minecraftVersion);
    }

    private static function majorFor(?string $version): int
    {
        $version = trim((string) $version);

        // "latest" 이거나 숫자로 시작하지 않으면 최신을 고른다. 최신 마인크래프트가
        // 가장 높은 Java 를 요구하므로, 모를 때 낮게 잡는 것보다 이쪽이 안전하다.
        if ($version === '' || !preg_match('/^\d+(\.\d+)*$/', $version)) {
            return self::NEWEST_JAVA;
        }

        foreach (self::REQUIREMENTS as $minimum => $java) {
            if (version_compare($version, $minimum, '>=')) {
                return $java;
            }
        }

        return self::LEGACY_JAVA;
    }
}
