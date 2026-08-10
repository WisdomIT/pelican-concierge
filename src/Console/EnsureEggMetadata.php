<?php

namespace WisdomIT\Concierge\Console;

use App\Models\Egg;
use Illuminate\Console\Command;

/**
 * 모드 플러그인들이 요구하는 egg 태그·feature 를 보정한다.
 *
 * **왜 필요한가** — Modrinth·uMod 플러그인은 어느 서버에 탭을 띄울지 **egg 의 tags/features** 로
 * 판정하는데, pelican-eggs 원본에는 그 값이 없다. 그래서 플러그인을 깔아도 탭이 안 보인다.
 *
 *   Modrinth: 태그 `minecraft` + 로더 태그(paper/fabric/forge) + feature `modrinth_plugins|mods`
 *   Rust uMod: 태그 `rust` (+ 서버 변수 FRAMEWORK=oxide — 카탈로그가 묻는다)
 *   Factorio: egg 이름에 factorio 만 있으면 됨 — 보정 불필요
 *
 * ⚠ **egg 를 재임포트하면 이 값이 초기화된다.** 그래서 한 번 고치고 끝이 아니라
 *   멱등 명령으로 만들어 deploy.sh 가 배포 때마다 돌린다.
 */
class EnsureEggMetadata extends Command
{
    protected $signature = 'concierge:egg-metadata';

    protected $description = '모드 플러그인이 요구하는 egg 태그·feature 를 보정한다';

    /**
     * egg 이름 → 추가할 값. **추가만 하고 빼지 않는다** — 원본 값을 건드리면 안 된다.
     *
     * @var array<string, array{tags?: string[], features?: string[]}>
     */
    private const REQUIRED = [
        // Modrinth 는 로더를 egg 태그에서 찾고(플러그인/모드 구분은 feature),
        // 로더 이름은 modrinth API 의 loader 목록과 일치해야 한다(소문자).
        'Paper' => ['tags' => ['paper'], 'features' => ['modrinth_plugins']],
        'Fabric' => ['tags' => ['fabric'], 'features' => ['modrinth_mods']],
        'Forge Minecraft' => ['tags' => ['forge'], 'features' => ['modrinth_mods']],
        // uMod 는 태그 rust 또는 feature umod_plugins 를 본다.
        'Rust' => ['tags' => ['rust']],
    ];

    /**
     * egg 이름 → 보장할 **변수** (#31).
     *
     * 팰월드 REST 쿼리는 egg 의 시작 스크립트(PalworldServerConfigParser)가 환경 변수
     * `REST_API_ENABLED`/`REST_API_PORT` 를 읽어 PalWorldSettings.ini 에 쓰는 구조다.
     * 원본 egg 에는 이 변수가 없어서 값을 넘겨도 컨테이너 환경에 실리지 않는다
     * (Pelican 은 egg 에 정의된 변수만 통과시킨다) — 그래서 변수 정의 자체를 보장한다.
     *
     * ⚠ 태그·feature 처럼 **egg 재임포트가 지운다.** 같은 이유로 멱등이어야 한다.
     *
     * @var array<string, array<int, array<string, mixed>>>
     */
    private const REQUIRED_VARIABLES = [
        'Palworld' => [
            [
                'env_variable' => 'REST_API_ENABLED',
                'name' => 'REST API Enabled',
                'description' => 'Writes RESTAPIEnabled into PalWorldSettings.ini (used for player-count queries).',
                'default_value' => 'True',
                'user_viewable' => false,
                'user_editable' => false,
                'rules' => ['required', 'string', 'in:True,False'],
            ],
            [
                'env_variable' => 'REST_API_PORT',
                'name' => 'REST API Port',
                'description' => 'Port the REST API listens on. Assigned automatically at creation.',
                'default_value' => '8212',
                'user_viewable' => true,
                'user_editable' => false,
                'rules' => ['required', 'numeric'],
            ],
        ],
    ];

    public function handle(): int
    {
        $this->ensureVariables();

        foreach (self::REQUIRED as $name => $required) {
            $egg = Egg::query()->where('name', $name)->first();

            if ($egg === null) {
                $this->warn("egg 없음: {$name} (임포트 전이면 정상)");

                continue;
            }

            $tags = array_values(array_unique(array_merge((array) $egg->tags, $required['tags'] ?? [])));
            $features = array_values(array_unique(array_merge((array) $egg->features, $required['features'] ?? [])));

            if ($tags === (array) $egg->tags && $features === (array) $egg->features) {
                $this->line("{$name}: 이미 충족");

                continue;
            }

            $egg->forceFill(['tags' => $tags, 'features' => $features])->save();
            $this->info("{$name}: tags=" . implode(',', $tags) . ' features=' . implode(',', $features));
        }

        return self::SUCCESS;
    }

    /** egg 에 없는 변수를 만든다. 있으면 건드리지 않는다 — 사용자가 바꾼 값을 보존한다. */
    private function ensureVariables(): void
    {
        foreach (self::REQUIRED_VARIABLES as $name => $variables) {
            $egg = Egg::query()->where('name', $name)->first();

            if ($egg === null) {
                continue;
            }

            foreach ($variables as $spec) {
                $exists = $egg->variables()->where('env_variable', $spec['env_variable'])->exists();

                if ($exists) {
                    continue;
                }

                $egg->variables()->create($spec + ['sort' => 99]);
                $this->info("{$name}: 변수 {$spec['env_variable']} 추가");
            }
        }
    }
}
