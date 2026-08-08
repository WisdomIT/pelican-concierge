<?php

namespace WisdomIT\WisdomAiAssistant\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Support\Str;
use WisdomIT\WisdomAiAssistant\Tools\ToolInputException;

/**
 * 모드 플러그인(Modrinth·uMod)의 서비스 계층을 에이전트 도구에서 재사용한다 (#16).
 *
 * **왜 감싸는가** — 두 플러그인은 남의 코드다. 없을 수도 있고(class_exists 가드),
 * 버전이 올라가며 API 가 바뀔 수도 있다. 결합을 이 한 파일로 모아 둔다.
 *
 * ⚠ **버전 호환은 플러그인 서비스가 이미 해 준다.** Modrinth 의 getProjects/getProjectVersions 는
 *   서버의 마인크래프트 버전·로더로 걸러진 결과만 돌려준다 — 우리가 다시 거를 필요가 없고,
 *   직접 거르려 들면 오히려 어긋난다.
 *
 * Factorio 는 다루지 않는다 — 모드 다운로드에 factorio.com 계정 인증이 필요해서
 * 에이전트가 대신할 수 없다. 그 게임은 전용 탭 버튼(suggest_page)으로 안내한다.
 */
final class ModInstaller
{
    /** 검색 결과 상한. 모델 컨텍스트로 들어가므로 잡아 둔다. */
    private const SEARCH_LIMIT = 8;

    /** 이 서버에서 쓸 수 있는 공급자. 없으면 null — 도구가 사유를 안내한다. */
    public static function providerFor(Server $server): ?string
    {
        if (class_exists('Boy132\MinecraftModrinth\Services\MinecraftModrinthService')
            && self::modrinthType($server) !== null) {
            return 'modrinth';
        }

        if (class_exists('Boy132\RustUMod\Services\RustUModService')
            && app('Boy132\RustUMod\Services\RustUModService')->isRustServer($server)) {
            return 'umod';
        }

        return null;
    }

    /**
     * 검색. 서버의 게임·버전·로더에 **호환되는 것만** 나온다.
     *
     * @return array{provider: string, context: string, results: array<int, array<string, mixed>>}
     */
    public function search(Server $server, ?string $query): array
    {
        $provider = self::providerFor($server);

        if ($provider === 'modrinth') {
            return $this->searchModrinth($server, $query);
        }

        if ($provider === 'umod') {
            return $this->searchUmod($server, $query);
        }

        throw new ToolInputException(
            'This server cannot search or install mods through the agent. '
            . 'Only Minecraft (Paper, Fabric, Forge) and Rust (FRAMEWORK=oxide) are supported. '
            . 'Factorio mods must be installed on its own tab (factorio_mods via suggest_page).',
        );
    }

    /**
     * 설치 계획 — 확인 카드에 띄울 사실. **아직 아무것도 설치하지 않는다.**
     *
     * 여기서 버전·파일명까지 확정해야 카드가 "무엇이 설치되는지"를 보여줄 수 있다.
     *
     * @return array{provider: string, title: string, version: string, filename: string}
     */
    public function plan(Server $server, string $mod): array
    {
        $provider = self::providerFor($server);

        if ($provider === 'modrinth') {
            return $this->planModrinth($server, $mod);
        }

        if ($provider === 'umod') {
            return $this->planUmod($server, $mod);
        }

        throw new ToolInputException('This server cannot install mods through the agent.');
    }

    /** 실제 설치. `plan()` 과 같은 입력으로 다시 해석한다 — 카드와 실행이 어긋나면 안 된다. */
    public function install(Server $server, string $mod): string
    {
        $provider = self::providerFor($server);

        return match ($provider) {
            'modrinth' => $this->installModrinth($server, $mod),
            'umod' => $this->installUmod($server, $mod),
            default => throw new ToolInputException('This server cannot install mods through the agent.'),
        };
    }

    /**
     * 설치된 것 목록. 업데이트가 있는지까지 본다 (#40).
     *
     * ⚠ Modrinth 플러그인에는 이름이 비슷한 두 메서드가 있다:
     *   `getInstalledMods()` 는 **소문자 파일명 배열**, `getInstalledModsMetadata()` 는
     *   메타데이터 배열이다. 화면 탭이 보는 것은 후자다 — 혼동하면 "설치된 게 없다"고 오진한다.
     *
     * @return array<string, mixed>
     */
    public function listInstalled(Server $server): array
    {
        $provider = self::providerFor($server);

        if ($provider === 'modrinth') {
            $service = app('Boy132\MinecraftModrinth\Services\MinecraftModrinthService');
            $type = self::modrinthType($server);

            $mods = array_map(function (array $mod) use ($service, $server) {
                $versions = $service->getProjectVersions($mod['project_id'], $server);

                return [
                    'id' => $mod['project_id'],
                    'title' => $mod['project_title'] ?? $mod['project_id'],
                    'version' => $mod['version_number'] ?? '?',
                    'filename' => $mod['filename'] ?? '',
                    'update_available' => $service->isUpdateAvailable($mod, $versions),
                    'latest_version' => $versions[0]['version_number'] ?? null,
                ];
            }, $service->getInstalledModsMetadata($server, $type));

            return ['provider' => 'modrinth', 'installed' => array_values($mods)];
        }

        if ($provider === 'umod') {
            // uMod 플러그인은 메타데이터를 남기지 않는다 — 파일 목록이 곧 설치 목록이다.
            $files = app(DaemonFileRepository::class)->setServer($server)->getDirectory('oxide/plugins');

            $installed = array_values(array_map(
                fn (array $f) => ['id' => $f['name'], 'title' => $f['name'], 'version' => '?', 'filename' => $f['name'], 'update_available' => false],
                array_filter($files, fn (array $f) => str_ends_with((string) ($f['name'] ?? ''), '.cs')),
            ));

            return ['provider' => 'umod', 'installed' => $installed, 'context' => 'uMod plugins record no version, so only file names are shown.'];
        }

        throw new ToolInputException('This server cannot manage mods through the agent.');
    }

    /**
     * 제거 계획(카드용). 무엇이 지워지는지 확정한다.
     *
     * @return array{provider: string, title: string, version: string, filename: string}
     */
    public function planRemoval(Server $server, string $mod): array
    {
        [$provider, $entry] = $this->installedEntry($server, $mod);

        return [
            'provider' => $provider,
            'title' => $entry['title'],
            'version' => $entry['version'],
            'filename' => $entry['filename'],
        ];
    }

    /**
     * 제거. **파일과 메타데이터를 함께** 지운다.
     *
     * 🔴 한쪽만 지우면 패널 탭 표시가 어긋나고 다음 설치·업데이트가 꼬인다.
     */
    public function remove(Server $server, string $mod): string
    {
        [$provider, $entry] = $this->installedEntry($server, $mod);
        $folder = $provider === 'umod' ? 'oxide/plugins' : self::modrinthType($server)->getFolder();

        app(DaemonFileRepository::class)->setServer($server)
            ->deleteFiles($folder, [$entry['filename']]);

        if ($provider === 'modrinth') {
            app('Boy132\MinecraftModrinth\Services\MinecraftModrinthService')
                ->removeModMetadata($server, self::modrinthType($server), $entry['id']);
        }

        return sprintf(
            "Removed '%s'.%s",
            $entry['title'],
            $provider === 'umod'
                ? ' Oxide unloads it immediately, so no restart is needed.'
                : ' A server restart is needed to apply it.',
        );
    }

    /**
     * 업데이트 = 최신 버전 설치 + 옛 파일 제거.
     *
     * ⚠ 순서가 중요하다. 새것을 먼저 받고 옛것을 지운다 — 반대로 하면 실패했을 때
     *   아무것도 없는 상태가 된다.
     */
    public function update(Server $server, string $mod): string
    {
        [$provider, $entry] = $this->installedEntry($server, $mod);

        if ($provider !== 'modrinth') {
            throw new ToolInputException('Plugins on this server record no version, so they cannot be auto-updated. Remove and install again.');
        }

        $old = $entry['filename'];
        $message = $this->installModrinth($server, $entry['id']);

        // 파일 이름이 그대로면(같은 파일명 유지) 지우면 안 된다 — 방금 받은 것을 지우게 된다.
        $new = app('Boy132\MinecraftModrinth\Services\MinecraftModrinthService')
            ->getInstalledMod($server, self::modrinthType($server), $entry['id'])['filename'] ?? null;

        if ($old !== '' && $new !== $old) {
            app(DaemonFileRepository::class)->setServer($server)
                ->deleteFiles(self::modrinthType($server)->getFolder(), [$old]);
        }

        return $message;
    }

    /**
     * 설치된 것 중에서 이름·id·파일명으로 하나를 찾는다.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function installedEntry(Server $server, string $mod): array
    {
        $listing = $this->listInstalled($server);
        $needle = mb_strtolower(trim($mod));

        foreach ($listing['installed'] as $entry) {
            foreach (['id', 'title', 'filename'] as $key) {
                if (mb_strtolower((string) $entry[$key]) === $needle) {
                    return [$listing['provider'], $entry];
                }
            }
        }

        throw new ToolInputException("'{$mod}' is not installed on this server. Check list_installed_mods first.");
    }

    // ── Modrinth (마인크래프트) ──────────────────────────────────

    private function searchModrinth(Server $server, ?string $query): array
    {
        $service = app('Boy132\MinecraftModrinth\Services\MinecraftModrinthService');
        $type = self::modrinthType($server);

        $found = $service->getProjects($server, $type, 1, $query ?: null);

        $results = array_map(fn (array $hit) => [
            // slug 가 설치할 때 쓰는 식별자다 — 모델에게 명시한다.
            'slug' => $hit['slug'] ?? $hit['project_id'] ?? '',
            'title' => $hit['title'] ?? '',
            'description' => Str::limit((string) ($hit['description'] ?? ''), 120),
            'downloads' => $hit['downloads'] ?? 0,
        ], array_slice($found['hits'] ?? [], 0, self::SEARCH_LIMIT));

        return [
            'provider' => 'modrinth',
            'context' => sprintf(
                'Filtered to %s compatible with %s on this server (showing %d of %d)',
                $type->value === 'plugin' ? 'plugins' : 'mods',
                $this->minecraftVersion($server),
                $found['total_hits'] ?? 0,
                count($results),
            ),
            'results' => $results,
        ];
    }

    /** @return array{provider: string, title: string, version: string, filename: string} */
    private function planModrinth(Server $server, string $slug): array
    {
        [$project, $version, $file] = $this->resolveModrinth($server, $slug);

        return [
            'provider' => 'modrinth',
            'title' => $project['title'] ?? $slug,
            'version' => $version['version_number'] ?? '?',
            'filename' => $file['filename'] ?? '?',
        ];
    }

    private function installModrinth(Server $server, string $slug): string
    {
        $service = app('Boy132\MinecraftModrinth\Services\MinecraftModrinthService');
        $type = self::modrinthType($server);
        [$project, $version, $file] = $this->resolveModrinth($server, $slug);

        /** @var DaemonFileRepository $files */
        $files = app(DaemonFileRepository::class);
        $files->setServer($server)->pull($file['url'], $type->getFolder())->throw();

        // 메타데이터를 남겨야 패널의 Modrinth 탭에 "설치됨"으로 보이고 업데이트 확인이 된다.
        $saved = $service->saveModMetadata(
            $server,
            $type,
            $project['id'] ?? $project['project_id'],
            $project['slug'] ?? $slug,
            $project['title'] ?? $slug,
            $version['id'],
            $version['version_number'],
            $file['filename'],
            $project['author'] ?? null,
        );

        if (!$saved) {
            throw new ToolInputException('The file downloaded but the install record could not be saved. Check the Modrinth tab on the panel.');
        }

        // ⚠ wings 의 pull 은 **비동기**다 — 응답이 와도 파일은 몇 초 뒤에 생긴다(실측).
        //   "설치했습니다"라고 단정하면 그 몇 초 사이에 재시작한 사용자가 혼란스럽다.
        return sprintf(
            "Started downloading '%s' %s into %s/ (done in a few seconds). A restart is needed to apply it.",
            $project['title'] ?? $slug,
            $version['version_number'],
            $type->getFolder(),
        );
    }

    /**
     * slug → (프로젝트, 이 서버에 맞는 최신 버전, 대표 파일).
     *
     * ⚠ getProjectVersions 가 이미 **이 서버의 버전·로더로 거른** 결과다. 비어 있으면
     *   "호환 버전 없음"이지 "프로젝트 없음"이 아닐 수 있다 — 메시지에서 구분한다.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function resolveModrinth(Server $server, string $slug): array
    {
        $project = \Illuminate\Support\Facades\Http::asJson()->timeout(5)
            ->get("https://api.modrinth.com/v2/project/{$slug}")->json();

        if (!is_array($project) || !isset($project['id'])) {
            throw new ToolInputException("Modrinth has no project '{$slug}'. Use the slug exactly as search_mods returned it.");
        }

        $versions = app('Boy132\MinecraftModrinth\Services\MinecraftModrinthService')
            ->getProjectVersions($project['id'], $server);

        if ($versions === []) {
            throw new ToolInputException(sprintf(
                "'%s' has no build for Minecraft %s on this server.",
                $project['title'] ?? $slug,
                $this->minecraftVersion($server),
            ));
        }

        $version = $versions[0];
        $files = array_values(array_filter($version['files'] ?? [], fn ($f) => $f['primary'] ?? false));
        $file = $files[0] ?? ($version['files'][0] ?? null);

        if ($file === null) {
            throw new ToolInputException('The selected version has no downloadable file.');
        }

        return [$project, $version, $file];
    }

    private static function modrinthType(Server $server): ?object
    {
        $types = \Boy132\MinecraftModrinth\Enums\ModrinthProjectType::fromServer($server);

        return $types[0] ?? null;
    }

    private function minecraftVersion(Server $server): string
    {
        foreach (['MINECRAFT_VERSION', 'MC_VERSION'] as $env) {
            $value = $server->variables->firstWhere('env_variable', $env)?->server_value;

            if ($value) {
                return (string) $value;
            }
        }

        return '?';
    }

    // ── uMod (Rust) ──────────────────────────────────────────────

    private function searchUmod(Server $server, ?string $query): array
    {
        $found = app('Boy132\RustUMod\Services\RustUModService')->getUModPlugins(1, (string) $query);

        $results = array_map(fn (array $hit) => [
            'slug' => $hit['name'] ?? '',
            'title' => $hit['title'] ?? ($hit['name'] ?? ''),
            'description' => Str::limit((string) ($hit['description'] ?? ''), 120),
            'downloads' => $hit['downloads'] ?? 0,
        ], array_slice($found['data'] ?? [], 0, self::SEARCH_LIMIT));

        return [
            'provider' => 'umod',
            'context' => sprintf('uMod plugin search (showing %d of %d)', count($results), $found['total'] ?? 0),
            'results' => $results,
        ];
    }

    /** @return array{provider: string, title: string, version: string, filename: string} */
    private function planUmod(Server $server, string $name): array
    {
        $hit = $this->resolveUmod($name);

        return [
            'provider' => 'umod',
            'title' => $hit['title'] ?? $name,
            'version' => (string) ($hit['latest_release_version'] ?? 'latest'),
            'filename' => basename((string) $hit['download_url']),
        ];
    }

    private function installUmod(Server $server, string $name): string
    {
        $hit = $this->resolveUmod($name);

        /** @var DaemonFileRepository $files */
        $files = app(DaemonFileRepository::class);
        $files->setServer($server)->pull($hit['download_url'], 'oxide/plugins')->throw();

        return sprintf(
            "Started downloading '%s' into oxide/plugins/. Oxide loads it automatically — no restart needed.",
            $hit['title'] ?? $name,
        );
    }

    /** @return array<string, mixed> */
    private function resolveUmod(string $name): array
    {
        $found = app('Boy132\RustUMod\Services\RustUModService')->getUModPlugins(1, $name);

        foreach ($found['data'] ?? [] as $hit) {
            if (strcasecmp((string) ($hit['name'] ?? ''), $name) === 0 && !empty($hit['download_url'])) {
                return $hit;
            }
        }

        throw new ToolInputException("uMod has no plugin '{$name}'. Use the slug exactly as search_mods returned it.");
    }
}
