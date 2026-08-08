<?php

namespace WisdomIT\WisdomAiAssistant\Catalog;

use App\Models\Egg;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * 게임 카탈로그(#16) 읽기.
 *
 * 카탈로그는 이 플러그인의 `resources/catalog/games.yaml` 이다. 배포 특정 값이 없는
 * 범용 지식이라 플러그인과 함께 배포된다 — 운영자가 자기 패널의 egg 에 맞게 고쳐도 된다.
 * 파일이 없으면 빈 목록으로 동작한다(죽지 않는다) — 다만 개설 가능한 게임이 사라진다.
 *
 * 카탈로그가 없으면 개설·게임 목록 도구만 못 쓴다 — 나머지 도구는 정상 동작해야 하므로
 * 예외를 던지지 않고 빈 목록을 돌려준다.
 */
final class GameCatalog
{
    /** @var ?array<int, array<string, mixed>> */
    private static ?array $games = null;

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        if (self::$games !== null) {
            return self::$games;
        }

        $path = plugin_path('wisdom-ai-assistant', 'resources', 'catalog', 'games.yaml');

        if (!is_file($path)) {
            return self::$games = [];
        }

        $parsed = Yaml::parseFile($path);

        return self::$games = is_array($parsed['games'] ?? null) ? $parsed['games'] : [];
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
