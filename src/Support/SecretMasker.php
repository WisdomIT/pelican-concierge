<?php

namespace WisdomIT\Concierge\Support;

use App\Models\Server;
use WisdomIT\Concierge\Catalog\GameCatalog;

/**
 * 서버의 egg 변수 중 시크릿에 해당하는 것을 찾아, **값이 나타나는 모든 곳**을 가린다.
 *
 * 왜 값 기준인가 (#13) — 시크릿은 변수 이름이 붙은 채로 나오지 않는다. Pelican 콘솔은
 * 시작 명령을 그대로 출력하므로 `+set sv_licenseKey cfxk_xxx` 처럼 값만 흘러나온다.
 * 변수명 패턴만 보고 지우면 놓친다. 그래서 **선택은 이름으로, 치환은 값으로** 한다.
 *
 * 적용 지점은 두 곳이며 둘 다 빠뜨리면 안 된다:
 *   1) 도구 결과를 모델에 넘기기 전
 *   2) 그 결과를 로그 테이블에 저장하기 전
 */
final class SecretMasker
{
    public const PLACEHOLDER = '••••••';

    /**
     * 이름 패턴은 **안전망**이다. 1순위는 카탈로그의 `secrets` 선언(#16).
     *
     * 패턴만으로는 두 방향에서 틀린다. 이름에 안 드러나는 시크릿(예: `SERVER_TOKEN` 이 아니라
     * `CLUSTER_ID`)을 놓치고, 반대로 시크릿이 아닌 `_KEY` 변수까지 가려 로그를 못 읽게 만든다.
     * 카탈로그는 게임별로 무엇이 시크릿인지 아는 유일한 곳이므로 그쪽을 먼저 본다.
     *
     * 패턴을 지우지 않는 이유는 카탈로그에 없는 egg 로 만든 서버(관리자가 직접 만든 것)도
     * 에이전트가 읽기 때문이다. 그 경우 선언이 없으므로 패턴이 유일한 방어선이다.
     */
    private const SECRET_NAME_PATTERN = '/(PASSWORD|PASSWD|SECRET|TOKEN|LICENSE|APIKEY|API_KEY|_KEY|^KEY$|PASS)/i';

    /** 너무 짧은 값은 가리지 않는다 — "on", "20" 같은 값을 지우면 문장이 망가진다. */
    private const MIN_LENGTH = 5;

    /** @param array<int, string> $secrets */
    private function __construct(private readonly array $secrets) {}

    public static function forServer(Server $server): self
    {
        $declared = self::declaredFor($server);
        $secrets = [];

        foreach ($server->variables as $variable) {
            $name = (string) $variable->env_variable;

            if (!in_array($name, $declared, true) && !preg_match(self::SECRET_NAME_PATTERN, $name)) {
                continue;
            }

            $value = trim((string) ($variable->server_value ?? $variable->default_value ?? ''));

            if (mb_strlen($value) >= self::MIN_LENGTH) {
                $secrets[] = $value;
            }
        }

        // 긴 값부터 지운다 — 짧은 값이 긴 값의 일부일 때 앞에서 잘라먹지 않도록.
        usort($secrets, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        return new self(array_values(array_unique($secrets)));
    }

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * 이 서버의 게임이 카탈로그에서 시크릿이라고 선언한 변수 이름들.
     *
     * 카탈로그가 없거나(배포 과도기) 그 egg 가 카탈로그 밖이면 빈 배열 — 패턴이 받아낸다.
     *
     * @return array<int, string>
     */
    private static function declaredFor(Server $server): array
    {
        $game = (new GameCatalog())->findByEggName($server->egg?->name ?? '');

        return array_values(array_filter((array) ($game['secrets'] ?? []), 'is_string'));
    }

    public function mask(string $text): string
    {
        if ($this->secrets === []) {
            return $text;
        }

        return str_replace($this->secrets, self::PLACEHOLDER, $text);
    }
}
