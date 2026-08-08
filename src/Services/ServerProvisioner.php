<?php

namespace WisdomIT\WisdomAiAssistant\Services;

use App\Models\Allocation;
use App\Models\Egg;
use App\Models\Server;
use App\Models\User;
use App\Services\Deployment\FindViableNodesService;
use App\Services\Servers\ServerCreationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use WisdomIT\WisdomAiAssistant\Catalog\GameCatalog;
use WisdomIT\WisdomAiAssistant\Catalog\JavaRuntime;
use WisdomIT\WisdomAiAssistant\Tools\ToolInputException;

/**
 * 개설 대행 (#7). 대화로 받은 의도를 실제 개설로 옮긴다.
 *
 * `UserCreatableServers` 의 개설 폼을 쓰지 않는 이유(#1 제품 원칙): 그 폼은 egg 변수를 전부
 * 노출하고 자원을 MiB·% 숫자로 받는다. 여기서는 **게임과 인원**만 받고 나머지는 카탈로그가 정한다.
 *
 * ⚠ 한도 검사는 UCS 의 `UserResourceLimits` 를 그대로 쓴다. 에이전트가 화면보다 느슨하면
 *   그게 곧 한도 우회 경로가 된다.
 */
final class ServerProvisioner
{
    public function __construct(
        private readonly User $user,
        private readonly GameCatalog $catalog,
    ) {}

    /**
     * 개설 계획을 세운다. **아무것도 만들지 않는다** — 확인 카드와 실제 개설이 같은 함수를 써야
     * 사용자가 본 것과 만들어지는 것이 어긋나지 않는다.
     *
     * @param  array<string, mixed>  $answers
     * @return array{game: array<string, mixed>, size: array<string, mixed>, egg: Egg, name: string, environment: array<string, mixed>, allocations: Collection<int, Allocation>}
     *
     * @throws ToolInputException
     */
    public function plan(string $gameId, string $sizeId, string $name, array $answers): array
    {
        $game = $this->catalog->find($gameId);

        if (!$game) {
            throw new ToolInputException("No game \"{$gameId}\" in the catalog. Check list_available_games.");
        }

        if (($game['available'] ?? true) !== true) {
            $reason = $game['unavailable_reason'] ?? 'This game cannot be created self-service.';

            throw new ToolInputException("{$game['name']} cannot be created directly: {$reason} An admin has to do it.");
        }

        $size = $this->catalog->sizeFor($game, $sizeId);

        if (!$size) {
            $ids = implode(', ', array_column($game['sizes'] ?? [], 'id'));

            throw new ToolInputException("\"{$sizeId}\" is not a size for {$game['name']}. Valid values: {$ids}");
        }

        $this->assertWithinUserLimits($size);

        $egg = $this->catalog->eggFor($game);
        $allocations = $this->selectAllocations($game, $size);
        $environment = $this->buildEnvironment($game, $size, $egg, $answers, $allocations);

        // 이름을 안 정했으면 **스펙으로 짓는다**(#59): "게임 버전" 또는 "게임 N인".
        // 모델이 임의로 짓던 것보다 목록에서 알아보기 좋고, 카드에서 바로 고칠 수 있다.
        $name = mb_substr(trim($name), 0, 40) ?: $this->defaultName($game, $size, $environment);

        return [
            'game' => $game,
            'size' => $size,
            'egg' => $egg,
            'name' => $name,
            'environment' => $environment,
            'allocations' => $allocations,
            'image' => $this->imageFor($game, $environment),
        ];
    }

    /**
     * 스펙 기반 기본 이름 (#59).
     *
     * 버전이 있는 게임(마인크래프트류 — java_from 이 가리키는 변수)은 "게임 버전",
     * 아니면 "게임 N인". 같은 이름이 있으면 숫자를 붙인다 — 친구들 목록에서 헷갈리지 않게.
     *
     * @param array<string, mixed> $game
     * @param array<string, mixed> $size
     * @param array<string, mixed> $environment
     */
    private function defaultName(array $game, array $size, array $environment): string
    {
        $version = null;

        foreach (array_filter([$game['java_from'] ?? null, 'MINECRAFT_VERSION', 'MC_VERSION']) as $env) {
            if (!empty($environment[$env]) && strtolower((string) $environment[$env]) !== 'latest') {
                $version = (string) $environment[$env];

                break;
            }
        }

        $base = $version !== null
            ? "{$game['name']} {$version}"
            : trans('wisdom-ai-assistant::strings.default_server_name', ['game' => $game['name'], 'players' => $size['players'] ?? '?']);

        $name = $base;

        for ($n = 2; Server::query()->where('name', $name)->exists(); $n++) {
            $name = "{$base} {$n}";
        }

        return $name;
    }

    /**
     * 실행 이미지를 정한다.
     *
     * 마인크래프트 계열은 **고른 버전**이 필요한 Java 를 결정하므로 `java_from` 이 가리키는
     * 변수에서 버전을 읽어 계산한다. 그 외에는 카탈로그가 못박은 값을, 그것도 없으면
     * null 을 돌려 egg 기본값에 맡긴다.
     *
     * @param array<string, mixed> $game
     * @param array<string, mixed> $environment
     */
    private function imageFor(array $game, array $environment): ?string
    {
        if ($versionVar = $game['java_from'] ?? null) {
            return JavaRuntime::imageFor((string) ($environment[$versionVar] ?? ''));
        }

        return $game['image'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $plan  plan() 의 반환값
     *
     * @throws \Throwable
     */
    public function create(array $plan): Server
    {
        /** @var Collection<int, Allocation> $allocations */
        $allocations = $plan['allocations'];
        $size = $plan['size'];

        /** @var ServerCreationService $service */
        $service = app(ServerCreationService::class);

        return $service->handle([
            'name' => $plan['name'],
            // ⚠ 세션의 사용자다. 모델이 준 값이 아니다 — 남의 이름으로 만들 수 없다.
            'owner_id' => $this->user->id,
            'egg_id' => $plan['egg']->id,
            // ⚠ 비워 두면 ServerCreationService 가 egg 의 **첫 번째** 이미지를 쓴다.
            //   그 순서는 버전과 무관하고 egg 마다 다르다 — 자세한 근거는 JavaRuntime 참고.
            'image' => $plan['image'],
            'node_id' => $allocations->first()->node_id,
            'allocation_id' => $allocations->first()->id,
            'allocation_additional' => $allocations->skip(1)->pluck('id')->all(),
            'memory' => $size['memory'],
            'disk' => $size['disk'],
            'cpu' => $size['cpu'],
            'swap' => 0,
            'io' => 500,
            'environment' => $plan['environment'],
            'skip_scripts' => false,
            'start_on_completion' => true,
            'oom_killer' => false,
            'database_limit' => (int) config('user-creatable-servers.database_limit', 0),
            'allocation_limit' => (int) config('user-creatable-servers.allocation_limit', 0),
            'backup_limit' => (int) config('user-creatable-servers.backup_limit', 0),
        ]);
    }

    /**
     * UCS 의 한도를 그대로 적용한다. 한도 레코드가 **없으면 개설할 수 없다** — 화면에서도
     * 개설 버튼이 안 보이는 상태이므로 에이전트만 되면 안 된다.
     *
     * @param array<string, mixed> $size
     *
     * @throws ToolInputException
     */
    private function assertWithinUserLimits(array $size): void
    {
        $class = 'Boy132\\UserCreatableServers\\Models\\UserResourceLimits';

        if (!class_exists($class)) {
            // UCS 가 없으면 한도 개념 자체가 없다. 그 판단은 관리자 몫이라 통과시킨다.
            return;
        }

        $limits = $class::query()->where('user_id', $this->user->id)->first();

        if (!$limits) {
            throw new ToolInputException(
                'This account has no server allowance yet. An admin has to grant one.',
            );
        }

        if (!$limits->canCreateServer($size['cpu'], $size['memory'], $size['disk'])) {
            // ⚠ 어느 자원이 모자란지 수치로 말해야 한다. "한도 부족"만 던지면 사용자는
            //   메모리·디스크만 보고 "초과 안 했는데?"라고 생각한다 — 실제로 CPU 만 소진된
            //   상태(400/400)에서 그 혼란이 있었다.
            $used = [
                'cpu' => (int) $this->user->servers()->sum('cpu'),
                'memory' => (int) $this->user->servers()->sum('memory'),
                'disk' => (int) $this->user->servers()->sum('disk'),
            ];
            $short = [];

            foreach ([
                'cpu' => ['CPU', '%'],
                'memory' => ['memory', 'MB'],
                'disk' => ['disk', 'MB'],
            ] as $key => [$label, $unit]) {
                if ($used[$key] + $size[$key] > $limits->{$key}) {
                    $short[] = sprintf(
                        '%s (in use %d%s + needed %d%s > limit %d%s)',
                        $label, $used[$key], $unit, $size[$key], $unit, $limits->{$key}, $unit,
                    );
                }
            }

            throw new ToolInputException(
                'The remaining allowance cannot fit this size. Short on: '
                . ($short === [] ? 'server count' : implode(' / ', $short))
                . '. Pick a smaller size, delete an unused server, or ask an admin to raise the limit.',
            );
        }
    }

    /**
     * 필요한 개수만큼 할당(포트)을 고른다.
     *
     * 실측으로 확인된 요구다(#7): ARK 4개, 7DTD·발하임 3개, 좀보이드·새티스팩토리 2개.
     * 게임이 포트를 스스로 +1, +2 로 여는 경우가 있어 `contiguous` 면 **연속**이어야 한다.
     *
     * @param  array<string, mixed>  $game
     * @param  array<string, mixed>  $size
     * @return Collection<int, Allocation>
     *
     * @throws ToolInputException
     */
    private function selectAllocations(array $game, array $size): Collection
    {
        $count = max(1, (int) ($game['ports']['count'] ?? 1));
        $contiguous = (bool) ($game['ports']['contiguous'] ?? false);

        /** @var FindViableNodesService $finder */
        $finder = app(FindViableNodesService::class);

        $tags = array_filter(explode(',', (string) config('user-creatable-servers.deployment_tags', '')));
        $nodes = $finder->handle($size['memory'], $size['disk'], $size['cpu'], $tags)->pluck('id');

        if ($nodes->isEmpty()) {
            throw new ToolInputException(
                'No node can host this size right now. Pick a smaller size or ask an admin.',
            );
        }

        // ⚠ 이 범위가 예약 포트(25565 등)를 지키는 유일한 수단이다 — 범위 밖은 후보에서 빠진다.
        $ports = $this->allowedPorts();

        // 🔴 **전역 스코프를 벗겨야 한다.** Filament v4 는 테넌트 패널의 리소스 모델마다
        //    전역 스코프를 등록한다 — 서버 패널에 Allocations 리소스가 있어서, 사용자가
        //    **서버 화면에서** 채팅하면 Allocation 조회가 현재 서버로 스코프된다.
        //    할당 101개가 "그 서버의 1개"로 보였고, 그래서 서버 목록에서는 되고 콘솔에서는
        //    안 되는 간헐 실패("남은 포트가 없습니다")가 났다. 실측 계측으로 잡았다:
        //    allocations_total=1 (CLI 는 101).
        $free = Allocation::query()->withoutGlobalScopes()
            ->whereIn('node_id', $nodes)
            ->whereNull('server_id')
            ->when($ports !== [], fn ($query) => $query->whereIn('port', $ports))
            ->orderBy('node_id')
            ->orderBy('port')
            ->get();

        foreach ($free->groupBy('node_id') as $onNode) {
            $picked = $contiguous ? $this->pickContiguous($onNode, $count) : $onNode->take($count);

            if ($picked->count() === $count) {
                return $picked->values();
            }
        }

        // ⚠ 여기까지 왔다는 것은 **원인이 셋 중 하나**라는 뜻이고, 예전에는 전부 "포트 부족"으로
        //   뭉뚱그렸다. 그러면 사용자도 관리자도 무엇을 고쳐야 할지 알 수 없다.
        //   (실제로 한 번 이 메시지가 났는데 할당은 100개가 비어 있어 원인을 못 찾았다)
        $this->explainNoAllocation($free, $nodes, $count, $contiguous, $ports);
    }

    /**
     * 왜 못 골랐는지 구분해서 알린다. **항상 예외를 던진다.**
     *
     * 사용자에게는 할 수 있는 일을, 로그에는 관리자가 볼 수치를 남긴다.
     *
     * @param  Collection<int, Allocation>  $free  허용 범위 안의 빈 할당
     * @param  Collection<int, int>  $nodes  자원 조건을 통과한 노드
     * @param  array<int, int>  $ports  허용 포트 범위
     *
     * @throws ToolInputException
     */
    private function explainNoAllocation(Collection $free, Collection $nodes, int $count, bool $contiguous, array $ports): never
    {
        // 범위를 무시하고 세어 본다 — "포트가 없다"와 "허용 범위 밖에만 있다"는 다른 문제다.
        $outsideRange = Allocation::query()->withoutGlobalScopes()
            ->whereIn('node_id', $nodes)
            ->whereNull('server_id')
            ->count();

        // ⚠ 이 수치들이 **서로 모순되는 일이 실제로 있었다** — 웹 요청에서는 빈 할당이 0으로,
        //   같은 순간 CLI 조회에서는 100으로 보였다. 원인을 좁히려면 필터 없는 총계와
        //   어느 DB 를 보고 있는지까지 함께 남겨야 한다.
        Log::warning('wisdom-ai-assistant: 할당을 고르지 못했다', [
            'need' => $count,
            'contiguous' => $contiguous,
            'free_in_range' => $free->count(),
            'free_any_port' => $outsideRange,
            'nodes' => $nodes->all(),
            'allowed_ports' => $ports === [] ? '(제한 없음)' : count($ports) . '개',
            // 필터를 전혀 걸지 않은 총계. 이것마저 0 이면 쿼리가 다른 데이터를 보고 있다는 뜻이다.
            'allocations_total' => Allocation::query()->count(),
            'allocations_total_unscoped' => Allocation::query()->withoutGlobalScopes()->count(),
            'tenant' => \Filament\Facades\Filament::getTenant()?->uuid_short ?? null,
            'allocations_unassigned_total' => Allocation::query()->whereNull('server_id')->count(),
            'sample_free' => Allocation::query()->whereNull('server_id')->limit(3)->pluck('port')->all(),
            'db' => config('database.default') . ':' . (config('database.connections.' . config('database.default') . '.database') ?: '-'),
            'in_transaction' => DB::transactionLevel(),
        ]);

        if ($free->isEmpty() && $outsideRange > 0) {
            throw new ToolInputException(
                'The only free ports are outside the range allowed for new servers. An admin has to widen it.',
            );
        }

        if ($free->isEmpty()) {
            throw new ToolInputException(
                'This node has no free ports left. Delete an unused server or ask an admin.',
            );
        }

        if ($contiguous) {
            throw new ToolInputException(
                "This game needs {$count} **consecutive** ports, but the {$free->count()} free ones are "
                . 'scattered and no run of that length exists. This needs an admin.',
            );
        }

        throw new ToolInputException(
            "This game needs {$count} ports and no single node has that many free ({$free->count()} left). "
            . 'This needs an admin.',
        );
    }

    /**
     * @param  Collection<int, Allocation>  $onNode  포트 오름차순
     * @return Collection<int, Allocation>
     */
    private function pickContiguous(Collection $onNode, int $count): Collection
    {
        $values = $onNode->values();

        for ($start = 0; $start + $count <= $values->count(); $start++) {
            $run = $values->slice($start, $count);
            $first = $run->first()->port;

            $isRun = $run->values()->every(fn (Allocation $a, int $i) => $a->port === $first + $i);

            if ($isRun) {
                return $run;
            }
        }

        return collect();
    }

    /** @return array<int, int> */
    private function allowedPorts(): array
    {
        return \WisdomIT\WisdomAiAssistant\Support\PortPool::allowedPorts();
    }

    /**
     * 사용자에게 묻지 않는 기술 변수까지 여기서 다 채운다(#7 — "기술 변수를 사용자에게 묻지 않는다").
     *
     * 우선순위: egg 기본값 → 카탈로그 defaults → 인원 → 사용자 답변 → 파생 포트
     * (파생 포트가 마지막이다. 모델이 답변으로 덮어쓰면 안 된다.)
     *
     * @param  array<string, mixed>  $game
     * @param  array<string, mixed>  $size
     * @param  array<string, mixed>  $answers
     * @param  Collection<int, Allocation>  $allocations
     * @return array<string, mixed>
     *
     * @throws ToolInputException
     */
    private function buildEnvironment(array $game, array $size, Egg $egg, array $answers, Collection $allocations): array
    {
        $environment = [];

        foreach ($egg->variables as $variable) {
            $environment[$variable->env_variable] = $variable->default_value;
        }

        foreach ($game['defaults'] ?? [] as $key => $value) {
            $environment[$key] = $value;
        }

        // 인원은 크기에서 온다. 사용자에게 MiB 를 묻지 않는 것과 같은 이유로 숫자를 묻지 않는다.
        if ($playerVar = $game['player_var'] ?? null) {
            $environment[$playerVar] = $this->capped($game, $playerVar, $size['players']);
        }

        foreach ($this->validatedAnswers($game, $answers) as $key => $value) {
            $environment[$key] = $value;
        }

        foreach ($game['ports']['derive'] ?? [] as $derive) {
            $allocation = $allocations->get((int) $derive['index']);

            if ($allocation) {
                $environment[$derive['env']] = (string) $allocation->port;
            }
        }

        return $environment;
    }

    /**
     * 모델이 준 답변을 카탈로그의 `ask` 정의에 맞춰 검증한다.
     * 정의에 없는 키는 **버린다** — 모델이 임의의 egg 변수를 채워 넣는 경로가 되면 안 된다.
     *
     * @param  array<string, mixed>  $game
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     *
     * @throws ToolInputException
     */
    private function validatedAnswers(array $game, array $answers): array
    {
        $accepted = [];

        foreach ($game['ask'] ?? [] as $ask) {
            $env = $ask['env'];
            $value = $answers[$env] ?? null;

            if ($value === null || $value === '') {
                if (($ask['optional'] ?? false) === false && !array_key_exists('default', $ask)) {
                    throw new ToolInputException("\"{$ask['label']}\" is required.");
                }

                if (array_key_exists('default', $ask)) {
                    $accepted[$env] = $ask['default'];
                }

                continue;
            }

            if (($ask['type'] ?? null) === 'choice' && !in_array($value, $ask['choices'] ?? [], true)) {
                $choices = implode(', ', $ask['choices'] ?? []);

                throw new ToolInputException("\"{$ask['label']}\" must be one of: {$choices}");
            }

            // 게임이 요구하는 길이 제약(예: 발헤임 비밀번호 5~20자). 어기면 서버가 안 뜬다.
            $length = mb_strlen((string) $value);

            if (isset($ask['min']) && $length < (int) $ask['min']) {
                throw new ToolInputException("\"{$ask['label']}\" must be at least {$ask['min']} characters.");
            }

            if (isset($ask['max']) && $length > (int) $ask['max']) {
                throw new ToolInputException("\"{$ask['label']}\" must be at most {$ask['max']} characters.");
            }

            $accepted[$env] = $this->capped($game, $env, $value);
        }

        return $accepted;
    }

    /**
     * 카탈로그 상한을 넘는 값은 **거부한다**(#7 완료 조건). egg 검증만으로는 부족하다 —
     * 실측에서 `MAX_PLAYERS=10000` 이 그대로 통과했다.
     *
     * @param array<string, mixed> $game
     *
     * @throws ToolInputException
     */
    private function capped(array $game, string $env, mixed $value): mixed
    {
        $cap = $game['caps'][$env] ?? null;

        if ($cap === null || !is_numeric($value)) {
            return $value;
        }

        if ((int) $value > (int) $cap) {
            throw new ToolInputException("{$env} cannot exceed {$cap}.");
        }

        return $value;
    }
}
