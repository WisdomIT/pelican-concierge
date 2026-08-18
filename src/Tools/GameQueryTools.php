<?php

namespace WisdomIT\Concierge\Tools;

use App\Models\Egg;
use Illuminate\Support\Facades\DB;
use WisdomIT\Concierge\Catalog\GameCatalog;
use WisdomIT\Concierge\Models\ConciergeGame;
use WisdomIT\Concierge\Services\PlayerCount;

/**
 * 접속자 수 규약을 읽고 잇는 도구 (#112).
 *
 * Player Counter 는 `game_queries` 한 행에 **게임 서버에게 인원을 묻는 방법**을 담고
 * (프로토콜 + 어느 포트로), `egg_game_query` 가 그것을 egg 에 잇는다. 연결이 없으면 그
 * egg 로 만든 서버는 접속자 수가 아예 뜨지 않는다.
 *
 * 손으로 하기 나쁜 일이라 도구가 됐다 — 실제로 이 패널에서 셋을 겪었다:
 *  · 시더가 돈 적이 없어 **표가 통째로 비어** 있었고 아무것도 그 사실을 말하지 않았다
 *  · 배포본 매핑에 팰월드가 없었다(프로토콜은 지원되는데 연결만 없었다)
 *  · Rust 가 **틀리게** 이어져 있었다 — egg 가 QUERY_PORT 를 따로 두는데 게임 포트로
 *    묻게 돼 있었다. 증상은 빈 위젯이고, 그건 "아무도 없음"과 구분되지 않는다
 *
 * 🔴 **추측하지 않는다.** 우리 카탈로그가 게임마다 query·query_port_variable·
 *    query_port_offset 을 이미 선언하고 있고 실서버로 검증된 값이다. 카탈로그에 없으면
 *    지어내지 말고 **묻는다** — 틀린 오프셋은 연결이 없는 것보다 나쁘다. 없으면 최소한
 *    없다는 게 보이지만, 틀리면 조용히 실패한다.
 *
 * 🔴 **Player Counter 가 없으면 이 클래스는 아무 일도 하지 않는다.** 모델을 문자열로
 *    들고 있는 것도 그래서다 — `use` 로 끌어오면 없는 클래스를 참조하게 되고, 그건 부팅이
 *    아니라 **런타임에** 터진다(PlayerCount 머리말의 규약과 같다).
 */
final class GameQueryTools
{
    private const MODEL_GAME_QUERY = 'Boy132\PlayerCounter\Models\GameQuery';

    /** Player Counter 가 구현한 프로토콜들. 여기 없는 게임은 물어볼 방법이 없다. */
    public const PROTOCOLS = ['source', 'goldsrc', 'minecraft_java', 'minecraft_bedrock', 'cfx', 'palworld'];

    public function __construct(private readonly GameCatalog $catalog = new GameCatalog()) {}

    /**
     * 규약과 연결 현황.
     *
     * 🔴 **연결이 없는 egg 를 함께 준다.** "왜 접속자 수가 안 뜨지"의 답이 그것이고,
     *    지금은 어디에서도 보이지 않는다. 카탈로그가 답을 알고 있으면 그것도 함께 적어,
     *    모델이 곧바로 제안할 수 있게 한다.
     *
     * @return array<string, mixed>
     */
    public function list(): array
    {
        $this->assertAvailable();

        $links = $this->links();
        $queries = $this->queries();

        $linked = [];
        $unlinked = [];

        foreach (Egg::query()->orderBy('name')->get() as $egg) {
            $queryId = $links[$egg->id] ?? null;

            if ($queryId !== null && isset($queries[$queryId])) {
                $linked[] = ['egg' => $egg->name] + $this->describe($queries[$queryId]);

                continue;
            }

            $unlinked[] = array_filter([
                'egg' => $egg->name,
                // 카탈로그가 이미 아는가 — 안다면 그대로 이으면 된다.
                'catalog_says' => $this->fromCatalog($egg->name),
            ], fn ($v) => $v !== null);
        }

        return [
            'linked' => $linked,
            'eggs_without_query' => $unlinked,
            'protocols' => self::PROTOCOLS,
            'note' => 'An egg in eggs_without_query shows no player count at all. Where catalog_says is present, '
                . 'set_egg_game_query with those values — it is our own verified declaration. Where it is absent, '
                . 'ask the administrator rather than guessing: a wrong port fails silently and looks like an empty server.',
        ];
    }

    /**
     * 이을 계획 — 확인 카드가 이 값을 그대로 보여준다.
     *
     * ⚠ 검사는 카드 앞에서 끝난다. egg 가 있는지, 프로토콜이 실재하는지, 포트 규칙이
     *   말이 되는지를 여기서 본다 — 승인 화면까지 갔다가 실패하면 확인의 뜻이 없다.
     *
     * @param  array<string, mixed>  $input
     * @return array{egg: string, egg_id: int, protocol: string, port_variable: ?string, port_offset: ?int, source: string, replaces: ?string}
     */
    public function plan(array $input): array
    {
        $this->assertAvailable();

        $name = trim((string) ($input['egg'] ?? ''));

        if ($name === '') {
            throw new ToolInputException('An egg name is required. Use list_game_queries to see which eggs have no player-count recipe.');
        }

        $egg = Egg::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first()
            ?? Egg::query()->where('name', 'like', '%' . $name . '%')->first();

        if ($egg === null) {
            throw new ToolInputException("This panel has no egg named \"{$name}\". Use list_eggs to see what is imported.");
        }

        // 입력이 비면 카탈로그가 답한다 — 그게 이 도구가 추측을 피하는 방법이다.
        $catalog = $this->fromCatalog($egg->name) ?? [];
        $protocol = trim((string) ($input['protocol'] ?? '')) ?: (string) ($catalog['protocol'] ?? '');

        if ($protocol === '') {
            throw new ToolInputException(sprintf(
                'No protocol given and the catalogue has no entry for "%s". Ask the administrator which of these it '
                . 'speaks (%s), and on which port — do not guess.',
                $egg->name,
                implode(', ', self::PROTOCOLS),
            ));
        }

        if (!in_array($protocol, self::PROTOCOLS, true)) {
            throw new ToolInputException(sprintf(
                'Player Counter does not implement "%s". It knows: %s. A game outside that list cannot be queried at all.',
                $protocol,
                implode(', ', self::PROTOCOLS),
            ));
        }

        $variable = trim((string) ($input['port_variable'] ?? '')) ?: ($catalog['port_variable'] ?? null);
        $offset = $input['port_offset'] ?? $catalog['port_offset'] ?? null;

        // 둘 다 주면 어느 쪽이 이기는지 모델도 사람도 모른다 — 변수가 이긴다는 사실만으로는
        // 부족하고, 애초에 그렇게 쓰지 않게 막는다.
        if (filled($variable) && $offset !== null && (int) $offset !== 0) {
            throw new ToolInputException('Give either a port variable or a port offset, not both — the port comes from one place.');
        }

        $current = $this->links()[$egg->id] ?? null;

        return [
            'egg' => $egg->name,
            'egg_id' => $egg->id,
            'protocol' => $protocol,
            'port_variable' => filled($variable) ? (string) $variable : null,
            'port_offset' => $offset === null ? null : (int) $offset,
            'source' => filled($input['protocol'] ?? null) ? 'given' : 'catalog',
            // 이미 이어져 있으면 새로 만드는 게 아니라 **바꾸는** 것이다. 카드가 그걸 말해야 한다.
            'replaces' => $current !== null ? ($this->describe($this->queries()[$current] ?? [])['query'] ?? null) : null,
        ];
    }

    /**
     * 실제로 잇는다.
     *
     * ⚠ 이은 뒤 **한 번 물어본다.** 연결이 생겼다는 것과 그 연결이 동작한다는 것은 다른
     *   사실이고, 다르다는 걸 나중에 아는 게 이 이슈의 출발점이었다.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function set(array $input): array
    {
        $plan = $this->plan($input);

        $queryId = $this->findOrCreateQuery($plan['protocol'], $plan['port_variable'], $plan['port_offset']);

        DB::table('egg_game_query')->updateOrInsert(
            ['egg_id' => $plan['egg_id']],
            ['game_query_id' => $queryId],
        );

        return [
            'egg' => $plan['egg'],
            'protocol' => $plan['protocol'],
            'port' => $this->portLabel($plan['port_variable'], $plan['port_offset']),
            'replaced' => $plan['replaces'],
            'check' => $this->check($plan['egg_id']),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function remove(array $input): array
    {
        $this->assertAvailable();

        $name = trim((string) ($input['egg'] ?? ''));
        $egg = Egg::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();

        if ($egg === null) {
            throw new ToolInputException("This panel has no egg named \"{$name}\".");
        }

        $removed = DB::table('egg_game_query')->where('egg_id', $egg->id)->delete();

        return [
            'egg' => $egg->name,
            'removed' => $removed > 0,
            'note' => $removed > 0
                ? 'Servers on this egg will no longer show a player count.'
                : 'That egg had no recipe to begin with.',
        ];
    }

    /**
     * 이 규약이 실제로 답을 받아 오는가 — 그 egg 의 **켜져 있는 서버 하나**로 확인한다.
     *
     * 결과가 없다고 해서 연결이 틀렸다고 단정하지 않는다. 서버가 꺼져 있을 수도, 게임 쪽
     * 조회 설정이 꺼져 있을 수도 있다 — 그 구분을 모델이 사용자에게 옮길 수 있게 사실만 준다.
     *
     * @return array<string, mixed>
     */
    private function check(int $eggId): array
    {
        $servers = \App\Models\Server::query()->where('egg_id', $eggId)->with('allocation')->get();

        if ($servers->isEmpty()) {
            return ['tested' => false, 'why' => 'No server on this panel uses that egg yet, so the recipe could not be tried.'];
        }

        $counter = new PlayerCount($this->catalog);

        foreach ($servers as $server) {
            // 🔴 0.0.0.0 이면 연결이 있어도 아무 일도 일어나지 않는다 — canRunQuery 가 거부한다.
            if (in_array((string) $server->allocation?->ip, ['0.0.0.0', '::', ''], true)) {
                return [
                    'tested' => false,
                    'why' => sprintf(
                        'The allocation for "%s" is %s, which is not an address — Player Counter refuses to query it. '
                        . 'Give the allocation a real IP and this recipe starts working.',
                        $server->name,
                        (string) $server->allocation?->ip ?: 'unset',
                    ),
                ];
            }

            $result = $counter->details($server);

            if (is_array($result)) {
                return [
                    'tested' => true,
                    'server' => $server->name,
                    'players' => ($result['current_players'] ?? '?') . '/' . ($result['max_players'] ?? '?'),
                    'hostname' => $result['hostname'] ?? null,
                ];
            }
        }

        return [
            'tested' => true,
            'answered' => false,
            'why' => 'The recipe is linked but no server answered. It may be stopped, or the game may need its query '
                . 'interface enabled — say both possibilities rather than declaring the recipe wrong.',
        ];
    }

    /** 같은 규약이 이미 있으면 그것을 쓴다 — 같은 내용의 행을 여럿 만들 이유가 없다. */
    private function findOrCreateQuery(string $protocol, ?string $variable, ?int $offset): int
    {
        $match = [
            'query_type' => $protocol,
            'query_port_offset' => $offset ?: null,
            'query_port_variable' => $variable ?: null,
        ];

        $model = self::MODEL_GAME_QUERY;

        return (int) $model::firstOrCreate($match)->id;
    }

    /**
     * 카탈로그가 이 egg 에 대해 아는 것 (#112) — 우리가 검증해 둔 값이다.
     *
     * @return ?array{protocol: string, port_variable: ?string, port_offset: ?int}
     */
    private function fromCatalog(string $eggName): ?array
    {
        $game = ConciergeGame::query()->whereRaw('lower(egg) = ?', [mb_strtolower($eggName)])->first();
        $advanced = $game?->advanced ?? [];
        $protocol = $advanced['query'] ?? null;

        if (!is_string($protocol) || $protocol === '') {
            return null;
        }

        return [
            'protocol' => $protocol,
            'port_variable' => $advanced['query_port_variable'] ?? null,
            'port_offset' => isset($advanced['query_port_offset']) ? (int) $advanced['query_port_offset'] : null,
        ];
    }

    /** @return array<int, int> egg_id => game_query_id */
    private function links(): array
    {
        return DB::table('egg_game_query')->pluck('game_query_id', 'egg_id')->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function queries(): array
    {
        return DB::table('game_queries')->get()->keyBy('id')
            ->map(fn ($row) => (array) $row)->all();
    }

    /** @return array<string, mixed> */
    private function describe(array $query): array
    {
        return [
            'query' => $query['query_type'] ?? '?',
            'port' => $this->portLabel($query['query_port_variable'] ?? null, $query['query_port_offset'] ?? null),
        ];
    }

    private function portLabel(?string $variable, ?int $offset): string
    {
        return match (true) {
            filled($variable) => "from the egg variable {$variable}",
            (int) $offset !== 0 => 'the allocation port plus ' . (int) $offset,
            default => 'the allocation port itself',
        };
    }

    /**
     * 🔴 Player Counter 가 없으면 여기서 멈춘다. 도구 노출에서도 같은 판정으로 걸러지지만,
     *    실행 경로에도 둔다 — 노출과 실행이 갈리면 목록에 없는 도구가 통과한다(#46 의 두 겹).
     */
    private function assertAvailable(): void
    {
        if (!PlayerCount::available()) {
            throw new ToolInputException(
                'Player Counter is not installed or not enabled on this panel, so there is nothing to configure. '
                . 'Player counts come from that plugin; without it no game can be queried.',
            );
        }
    }
}
