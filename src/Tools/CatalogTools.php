<?php

namespace WisdomIT\Concierge\Tools;

use App\Models\Egg;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Yaml\Yaml;
use WisdomIT\Concierge\Catalog\AdvancedYaml;
use WisdomIT\Concierge\Catalog\GameCatalog;
use WisdomIT\Concierge\Models\ConciergeGame;
use WisdomIT\Concierge\Services\PlayerCount;

/**
 * 카탈로그를 읽고 고치는 도구 (#91).
 *
 * 카탈로그를 패널에 맞추는 일 — egg 변수를 읽고, 그중 무엇을 사용자에게 물을지 고르고,
 * 자원을 잡고, 가릴 값을 정하는 것 — 은 폼으로는 길고 대화로는 자연스럽다. 이 클래스는
 * 그 대화가 실제로 카탈로그를 바꿀 수 있게 한다.
 *
 * 🔴 **에이전트가 쓴 항목도 검사기를 통과해야 한다.** AdvancedYaml 이 형태·egg 대조를
 *    모두 보므로, 사람이 화면에서 쓴 것과 같은 기준으로 걸러진다. 카드는 검사를 통과하지
 *    못한 항목을 애초에 제안하지 않는다 — 통과 못 할 것을 사람에게 눌러 달라고 하는 것은
 *    확인 카드의 뜻을 저버리는 일이다.
 *
 * ⚠ 권한은 egg 를 따른다(#91 결정) — 읽기 `viewList egg`, 쓰기 `update egg`. 관리 화면
 *   (ConciergeGamePolicy)과 **같은 기준**이다. 갈리면 "화면에서는 되는데 대화로는 안 된다"가
 *   생긴다.
 */
final class CatalogTools
{
    /**
     * 카탈로그 전체. 개설이 꺼진 항목도 포함한다 — 관리자는 "왜 이 게임이 안 보이지"를
     * 물을 수 있어야 하고, list_available_games 는 그 질문에 답할 수 없다(그쪽은 개설용이라
     * 켜진 것만 준다).
     *
     * @return array<string, mixed>
     */
    public function list(): array
    {
        $eggs = Egg::query()->pluck('name')->all();

        $games = ConciergeGame::query()->orderBy('sort')->orderBy('id')->get()
            ->map(fn (ConciergeGame $game) => array_filter([
                'id' => $game->game_id,
                'name' => $game->name,
                'egg' => $game->egg,
                // 🔴 개설하려다 실패할 때에야 드러나던 것 — 목록에서 먼저 말한다.
                'egg_missing' => in_array($game->egg, $eggs, true) ? null : true,
                'available' => $game->available,
                'unavailable_reason' => $game->unavailable_reason,
                'sizes' => count($game->sizes ?? []),
                'asks' => count($game->ask ?? []),
            ], fn ($v) => $v !== null && $v !== false))
            ->all();

        return array_filter([
            'count' => count($games),
            'games' => $games,
            'eggs_without_entry' => array_values(array_diff(
                $eggs,
                ConciergeGame::query()->pluck('egg')->all(),
            )),
            // 항목이 없는 egg 옆에 **규약이 없는 egg** 도 함께 준다 (#112) — 둘 다
            // "왜 이게 안 보이지"의 답이고, 한 번에 보여야 관리자가 한 번에 고친다.
            'eggs_without_player_count' => $this->eggsWithoutPlayerCount($eggs),
            'note' => 'Use get_catalog_game for one entry in full, including its advanced block.',
        ], fn ($v) => $v !== null);
    }

    /**
     * 접속자 수 규약이 없는 egg 들 (#112).
     *
     * 🔴 **Player Counter 가 없으면 null** — 그러면 위에서 키째 빠진다. 없는 플러그인의
     *    설정거리를 목록에 얹으면 모델이 그것을 권하게 되고, 관리자는 없는 화면을 찾는다.
     *
     * @param  array<int, string>  $eggs
     * @return ?array<int, string>
     */
    private function eggsWithoutPlayerCount(array $eggs): ?array
    {
        if (!PlayerCount::available()) {
            return null;
        }

        $linked = Egg::query()
            ->whereIn('id', DB::table('egg_game_query')->pluck('egg_id'))
            ->pluck('name')
            ->all();

        return array_values(array_diff($eggs, $linked));
    }

    /**
     * 항목 하나를 전부. 고치려면 지금 값을 알아야 한다.
     *
     * @return array<string, mixed>
     */
    public function get(array $input): array
    {
        $game = $this->find($input);

        return [
            'id' => $game->game_id,
            'name' => $game->name,
            'name_translations' => $game->name_translations,
            'summary' => $game->summary,
            'summary_translations' => $game->summary_translations,
            'egg' => $game->egg,
            'egg_missing' => $game->eggExists() ? null : true,
            'available' => $game->available,
            'unavailable_reason' => $game->unavailable_reason,
            'sizes' => $game->sizes ?? [],
            'ask' => $game->ask ?? [],
            'advanced' => ($game->advanced ?: []) === [] ? null : Yaml::dump($game->advanced, 6, 2),
        ];
    }

    /**
     * 저장할 값과, 그 값이 검사를 통과하는지. 카드와 실행이 **같은 계산**을 쓴다 —
     * 갈리면 카드에 보인 것과 저장되는 것이 달라진다.
     *
     * @return array{game: ?ConciergeGame, values: array<string, mixed>, issues: array<int, array<string, mixed>>}
     */
    public function plan(string $tool, array $input): array
    {
        $game = $tool === 'create_catalog_game' ? null : $this->find($input);

        $advancedYaml = trim((string) ($input['advanced'] ?? ''));

        if ($advancedYaml === '' && $game !== null && !array_key_exists('advanced', $input)) {
            // 안 건드린 것과 비우라는 것은 다르다 — 키가 아예 없으면 지금 값을 지킨다.
            $advancedYaml = ($game->advanced ?: []) === [] ? '' : Yaml::dump($game->advanced, 6, 2);
        }

        $egg = (string) ($input['egg'] ?? $game?->egg ?? '');

        $values = array_filter([
            'game_id' => (string) ($input['id'] ?? $game?->game_id ?? ''),
            'name' => (string) ($input['name'] ?? $game?->name ?? ''),
            'summary' => $input['summary'] ?? $game?->summary,
            'egg' => $egg,
            'available' => array_key_exists('available', $input)
                ? (bool) $input['available']
                : ($game?->available ?? true),
            'unavailable_reason' => $input['unavailable_reason'] ?? $game?->unavailable_reason,
            'sizes' => $input['sizes'] ?? $game?->sizes ?? [],
            'ask' => $input['ask'] ?? $game?->ask ?? [],
            'advanced' => $advancedYaml === '' ? [] : (array) Yaml::parse($advancedYaml),
        ], fn ($v) => $v !== null);

        return [
            'game' => $game,
            'values' => $values,
            // 사람이 화면에서 쓴 것과 **같은 검사**다.
            'issues' => AdvancedYaml::issues($advancedYaml, $egg),
        ];
    }

    /** 저장을 막아야 하는 문제만 — 경고는 카드에 적되 막지는 않는다. */
    public function blockingIssues(string $tool, array $input): array
    {
        return array_values(array_filter(
            $this->plan($tool, $input)['issues'],
            fn (array $issue) => $issue['severity'] === 'error',
        ));
    }

    /** @return array<string, mixed> */
    public function save(string $tool, array $input): array
    {
        ['game' => $game, 'values' => $values] = $this->plan($tool, $input);

        $this->assertEgg((string) $values['egg']);

        if ($game === null) {
            $values['sort'] = (int) (ConciergeGame::query()->max('sort') ?? 0) + 1;
            $game = ConciergeGame::create($values);
        } else {
            $game->fill($values)->save();
        }

        GameCatalog::forget();

        return array_filter([
            'saved' => $game->game_id,
            'name' => $game->name,
            'egg' => $game->egg,
            // 방금 쓴 항목이 query 를 선언했는데 그 egg 에 규약이 없다면 지금 말한다 (#112) —
            // 같은 사실을 두 곳에 따로 적게 두지 않는다.
            'player_count' => $this->playerCountHint($game),
        ], fn ($v) => $v !== null);
    }

    /**
     * 이 항목이 접속자 수를 선언했는데 egg 에 규약이 없으면 그 사실 (#112).
     *
     * 🔴 Player Counter 가 없으면 null — 없는 플러그인의 설정을 권하지 않는다.
     */
    private function playerCountHint(ConciergeGame $game): ?string
    {
        if (!PlayerCount::available()) {
            return null;
        }

        $declared = ($game->advanced ?? [])['query'] ?? null;

        if (!is_string($declared) || $declared === '') {
            return null;
        }

        $eggId = Egg::query()->whereRaw('lower(name) = ?', [mb_strtolower((string) $game->egg)])->value('id');

        if ($eggId === null || DB::table('egg_game_query')->where('egg_id', $eggId)->exists()) {
            return null;
        }

        return sprintf(
            'This entry declares the "%s" query, but the egg "%s" has no player-count recipe yet, so servers on it will '
            . 'show nobody online. set_egg_game_query with just the egg picks these same values up.',
            $declared,
            $game->egg,
        );
    }

    /** @return array<string, mixed> */
    public function delete(array $input): array
    {
        $game = $this->find($input);
        $id = $game->game_id;

        $game->delete();
        GameCatalog::forget();

        return [
            'deleted' => $id,
            // 지운 것은 목록에서의 자리뿐이다 — 카드에도 같은 말을 적는다.
            'note' => 'Servers created from this entry are untouched; only the catalogue entry is gone.',
        ];
    }

    public function find(array $input): ConciergeGame
    {
        $id = trim((string) ($input['id'] ?? ''));

        $game = ConciergeGame::query()->where('game_id', $id)->first();

        if ($game === null) {
            throw new ToolInputException(
                "No catalogue entry with id \"{$id}\". Use list_catalog_games to see what exists.",
            );
        }

        return $game;
    }

    /** egg 는 이름으로 참조한다 — 없는 이름을 저장하면 개설할 때에야 터진다. */
    private function assertEgg(string $name): void
    {
        if ($name === '' || Egg::query()->where('name', $name)->exists()) {
            return;
        }

        throw new ToolInputException(
            "This panel has no egg named \"{$name}\". Use list_eggs to see what is imported.",
        );
    }
}
