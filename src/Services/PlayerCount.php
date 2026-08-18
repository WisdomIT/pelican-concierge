<?php

namespace WisdomIT\Concierge\Services;

use App\Models\Server;
use Illuminate\Support\Facades\Log;
use WisdomIT\Concierge\Catalog\GameCatalog;
use WisdomIT\Concierge\Support\OptionalPlugins;

/**
 * 접속자 수 조회 (#18·#53). 유휴 판정·콘솔 위젯·에이전트 도구가 **같은 코드로 같은 숫자**를 본다.
 *
 * 🔴 **조회 대상은 할당의 IP 다** (#82). 한때는 전역 설정(`query_host`, 기본 172.17.0.1)으로
 *    도커 게이트웨이에 되돌아 들어갔다 — 이 배포의 할당이 전부 `0.0.0.0` 이라 그 주소로는
 *    아무 데도 닿을 수 없었기 때문이다. 하지만 그 우회에는 값이 없었다: 노드가 둘이면
 *    표현할 수 없고(노드마다 주소가 다르다), 틀렸을 때 조용히 실패하며, 무엇보다
 *    **할당이 제 주소를 갖게 되면 필요가 없다.**
 *
 * ⚠ `query_host` 는 남겨 둔다. 다만 뜻이 바뀌었다 — 기본 경로가 아니라, 패널이 자기
 *   할당 주소로 되돌아 들어갈 수 없는 배포(헤어핀 NAT 등)를 위한 **덮어쓰기**다.
 *   비어 있는 것이 정상이고, 비어 있으면 할당의 IP 를 쓴다.
 *
 * ⚠ 할당이 `0.0.0.0` 이면 조회를 시도하지 않는다. 그건 주소가 아니라 "전부"라는 뜻이고,
 *   Player Counter 자신도 같은 이유로 거부한다(canRunQuery). 그 경우 덮어쓰기가 없으면
 *   **셀 수 없음**으로 답한다 — 아무 데나 찔러 보고 실패로 적는 것보다 낫다.
 *
 * ⚠ 이 쿼리도 그 서버의 rx 를 올린다. 유휴 판정에서 접속자 수를 쓰는 게임이 rx 를 보지 않는
 *   이유다 — 새 호출처를 늘릴 때(위젯 폴링 등) 그 전제는 그대로 유지된다.
 *
 * 🔴 **Player Counter 플러그인은 선택 사항이다.** 프로토콜 구현(스키마)만 빌려 쓰는데,
 *   그게 없으면 `app()` 이 BindingResolutionException 을 던진다 — 부팅이 아니라 **런타임**에,
 *   그것도 콘솔 위젯·유휴 크론·에이전트 도구 세 곳에서 동시에 터진다. 설치 직후엔 멀쩡해
 *   보이다가 콘솔을 여는 순간 500 이 되는 실패 방식이다.
 *   그래서 클래스 존재를 먼저 확인하고, 없으면 **"이 게임은 셀 수 없음"(null)** 과 같이
 *   취급한다 — 접속자 기능만 조용히 빠지고 나머지는 그대로 동작한다.
 *   (ModInstaller 가 Modrinth·uMod 를 다루는 방식과 같은 규약이다.)
 */
class PlayerCount
{
    /** Player Counter 가 제공하는 쿼리 스키마 레지스트리. */
    private const QUERY_SERVICE = 'Boy132\PlayerCounter\Extensions\Query\QueryTypeService';

    public function __construct(private readonly GameCatalog $catalog) {}

    /**
     * @return int|false|null  접속자 수 / 쿼리 실패 / 쿼리를 선언하지 않은 게임
     */
    public function for(Server $server): int|false|null
    {
        $result = $this->details($server);

        if ($result === null) {
            return null;
        }

        return $result === false ? false : ($result['current_players'] ?? false);
    }

    /**
     * 위젯·도구용 상세: 인원과 닉네임 목록까지.
     *
     * @return array<string, mixed>|false|null
     */
    public function details(Server $server): array|false|null
    {
        $game = $this->catalog->findByEggName($server->egg?->name ?? '');
        $type = $game['query'] ?? null;

        if (!is_string($type) || !self::available()) {
            return null;
        }

        $schema = app(self::QUERY_SERVICE)->get($type);

        if ($schema === null) {
            // 카탈로그가 없는 쿼리 종류를 가리킨다 — 설정 실수다. 조용히 넘어가면 계속 틀린다.
            Log::warning('concierge: 카탈로그가 모르는 쿼리 종류를 가리킨다', [
                'egg' => $server->egg?->name,
                'query' => $type,
            ]);

            return false;
        }

        $host = $this->queryHost($server);

        if ($host === null) {
            return false;
        }

        $result = $schema->process($server, $host, $this->queryPort($server, $game));

        return $result ?? false;
    }

    /**
     * 이 게임이 접속자 수를 셀 수 있는가 (위젯 표시 여부).
     *
     * ⚠ 카탈로그뿐 아니라 **Player Counter 설치 여부까지** 본다. 여기서 걸러야 위젯이
     *   아예 그려지지 않는다 — details() 만 막으면 빈 위젯이 남는다.
     */
    public function supports(Server $server): bool
    {
        return self::available()
            && is_string($this->catalog->findByEggName($server->egg?->name ?? '')['query'] ?? null);
    }

    /**
     * 접속자 수가 왜 없는지 (#15). null = 셀 수 있음 / 'game' = 게임이 쿼리를 선언하지
     * 않음 / 'plugin' = 게임은 되는데 Player Counter 가 없거나 꺼짐.
     * 마지막 경우를 구분해야 도구 응답이 "설치하면 됩니다"를 말할 수 있다.
     */
    public function unavailableReason(Server $server): ?string
    {
        $declared = is_string($this->catalog->findByEggName($server->egg?->name ?? '')['query'] ?? null);

        if (!$declared) {
            return 'game';
        }

        return self::available() ? null : 'plugin';
    }

    /** Player Counter 플러그인이 설치되어 있고 **켜져** 있는가.
     *  ⚠ class_exists 로는 안 된다 — 꺼진 플러그인에도 true 다(#13).
     *  공개다: 규약 도구(GameQueryTools)와 도구 노출 판정이 **같은 판정**을 봐야 한다(#112). */
    public static function available(): bool
    {
        return OptionalPlugins::usable('player-counter');
    }

    /** @param array<string, mixed> $game */
    /**
     * 어디로 물을 것인가 (#82).
     *
     * 할당의 IP 가 답이다. 덮어쓰기가 설정돼 있으면 그것이 이긴다 — 패널이 자기 할당
     * 주소로 되돌아 들어갈 수 없는 배포를 위한 탈출구다.
     *
     * @return ?string null = 물을 곳이 없다(할당이 0.0.0.0 인데 덮어쓰기도 없다)
     */
    private function queryHost(Server $server): ?string
    {
        $override = trim((string) config('concierge.query_host', ''));

        if ($override !== '') {
            return $override;
        }

        $ip = trim((string) $server->allocation?->ip);

        // 🔴 0.0.0.0 은 주소가 아니라 "전부"다. 그리로 조회를 보내면 실패하거나 — 더 나쁘게 —
        //    루프백의 엉뚱한 서비스에 닿는다. Player Counter 도 같은 이유로 거부한다.
        return ($ip === '' || $ip === '0.0.0.0' || $ip === '::') ? null : $ip;
    }

    private function queryPort(Server $server, array $game): int
    {
        if ($variable = $game['query_port_variable'] ?? null) {
            $value = $server->variables()->where('env_variable', $variable)->first()?->server_value;

            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return $server->allocation->port + (int) ($game['query_port_offset'] ?? 0);
    }
}
