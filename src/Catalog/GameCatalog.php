<?php

namespace WisdomIT\Concierge\Catalog;

use App\Models\Egg;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use WisdomIT\Concierge\Models\ConciergeGame;

/**
 * 게임 카탈로그(#16) 읽기.
 *
 * 카탈로그는 **DB**(concierge_games)에 있고 관리 화면에서 고친다(#81). 종전에는 플러그인
 * 안의 `games.yaml` 이었는데, 운영자 데이터인데도 화면에서 못 고쳤고 무엇보다 플러그인
 * 업데이트가 그 파일을 지웠다. 배포본 YAML 은 신규 설치의 씨앗으로만 남는다.
 *
 * 카탈로그가 비면 개설·게임 목록 도구만 못 쓴다 — 나머지 도구는 정상 동작해야 하므로
 * 예외를 던지지 않고 빈 목록을 돌려준다.
 *
 * ⚠ 반환 형태는 **YAML 시절 배열 그대로**다. 소비자(개설·유휴 판정·마스킹·모드 설치)가
 *   그 형태를 알고 있고, 저장소가 바뀌었다고 그들을 전부 고칠 이유는 없다.
 */
final class GameCatalog
{
    /**
     * ⚠ **로케일별로** 담는다. 이름은 읽는 시점의 언어로 풀려 나오므로 한 덩어리로 캐시하면
     *   한 프로세스가 여러 언어를 오갈 때 틀린 언어가 나간다 — 유휴 감시(CheckIdleServers)가
     *   주인마다 각자 언어로 알리는 경로가 정확히 그렇다(실측: 두 번째 사용자부터 첫
     *   사용자의 언어로 나왔다).
     *
     * @var array<string, array<int, array<string, mixed>>>
     */
    private static array $games = [];

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        $locale = app()->getLocale();

        if (isset(self::$games[$locale])) {
            return self::$games[$locale];
        }

        // 마이그레이션 전(설치 직후 부팅 등)에도 죽지 않아야 한다 — 테이블이 아직 없으면
        // 빈 카탈로그다. 개설만 못 하고 나머지는 그대로 돈다.
        if (!Schema::hasTable('concierge_games')) {
            return self::$games[$locale] = [];
        }

        return self::$games[$locale] = ConciergeGame::query()
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->map(fn (ConciergeGame $game) => $game->toCatalogArray())
            ->all();
    }

    /** 화면에서 카탈로그를 고쳤다 — 이 요청에 캐시된 목록을 버린다. */
    public static function forget(): void
    {
        self::$games = [];
    }

    /** 셀프서비스로 만들 수 있는 게임만. 스팀 자격증명이 필요한 것 등은 빠진다. */
    public function selfServiceGames(): array
    {
        return array_values(array_filter($this->all(), fn (array $g) => ($g['available'] ?? true) === true));
    }

    /** @return ?array<string, mixed> */
    public function find(string $id): ?array
    {
        foreach ($this->all() as $game) {
            if ($game['id'] === $id) {
                return $game;
            }
        }

        return null;
    }

    /**
     * egg 로 카탈로그 항목을 되찾는다. 개설 이후(post_install)에는 game id 가 남아 있지 않고
     * 서버가 어떤 egg 를 쓰는지만 알 수 있기 때문이다.
     *
     * @return ?array<string, mixed>
     */
    public function findByEggName(string $eggName): ?array
    {
        foreach ($this->all() as $game) {
            if (($game['egg'] ?? null) === $eggName) {
                return $game;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $game */
    public function eggFor(array $game): Egg
    {
        $egg = Egg::query()->where('name', $game['egg'])->first();

        if (!$egg) {
            // 카탈로그에는 있는데 egg 가 임포트되지 않은 상태. 관리자만 고칠 수 있다.
            throw new RuntimeException("egg \"{$game['egg']}\" 가 패널에 임포트되어 있지 않습니다.");
        }

        return $egg;
    }

    /**
     * @param  array<string, mixed>  $game
     * @return ?array<string, mixed>
     */
    public function sizeFor(array $game, string $sizeId): ?array
    {
        foreach ($game['sizes'] ?? [] as $size) {
            if ($size['id'] === $sizeId) {
                return $size;
            }
        }

        return null;
    }
}
