<?php

namespace WisdomIT\Concierge\Services;

use App\Models\Server;
use Illuminate\Support\Facades\Log;
use WisdomIT\Concierge\Catalog\GameCatalog;
use WisdomIT\Concierge\Support\OptionalPlugins;

/**
 * 접속자 수 조회 (#18·#53). 유휴 판정·콘솔 위젯·에이전트 도구가 **같은 코드로 같은 숫자**를 본다.
 *
 * ⚠ 할당 IP 는 0.0.0.0(전체 바인드)이라 그대로 쿼리할 수 없다 — 도커 게이트웨이로 되돌아
 *   들어간다. Player Counter 위젯이 이 배포에서 동작하지 않는 이유이기도 하다
 *   (canRunQuery 가 0.0.0.0 을 거부). 그래서 스키마(프로토콜 구현)만 빌려 쓰고
 *   대상 주소는 우리가 정한다.
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

        $result = $schema->process(
            $server,
            config('concierge.query_host', '172.17.0.1'),
            $this->queryPort($server, $game),
        );

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

    /** Player Counter 플러그인이 설치되어 있고 **켜져** 있는가.
     *  ⚠ class_exists 로는 안 된다 — 꺼진 플러그인에도 true 다(#13). */
    private static function available(): bool
    {
        return OptionalPlugins::usable('player-counter');
    }

    /** @param array<string, mixed> $game */
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
