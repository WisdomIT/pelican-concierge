<?php

namespace WisdomIT\WisdomAiAssistant\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Support\Facades\Log;
use Throwable;
use WisdomIT\WisdomAiAssistant\Catalog\GameCatalog;

/**
 * 설치가 끝난 뒤 카탈로그의 `post_install` 을 적용한다 (#7).
 *
 * **왜 필요한가** — 사용자에게 물을 이유가 없는 전제인데 안 하면 첫 기동이 실패하는 것들이다.
 *  - 마인크래프트 `eula.txt` : 미동의면 조용히 꺼진다(종료 코드 0이라 실패로도 안 보인다)
 *  - 좀보이드 `-Xmx8g` 하드코딩 : 컨테이너 제한을 넘어 OOMKilled. 메모리를 올려도 안 풀린다
 *
 * ⚠ **`initialInstall` 로 거르면 안 된다.** 재설치는 볼륨을 비우므로 이 수정들이 함께 날아간다.
 *   성공한 **모든** 설치에서 실행해야 한다.
 */
final class PostInstallRunner
{
    public function __construct(private readonly GameCatalog $catalog) {}

    public function run(Server $server): void
    {
        $game = $this->catalog->findByEggName($server->egg?->name ?? '');
        $actions = $game['post_install'] ?? [];

        if ($actions === []) {
            return;
        }

        foreach ($actions as $action) {
            try {
                match ($action['type']) {
                    'file_replace' => $this->fileReplace($server, $action),
                    'json_vmarg' => $this->jsonVmArg($server, $action),
                    default => null,
                };
            } catch (Throwable $exception) {
                // 여기서 던지면 설치 완료 처리 전체가 깨진다. 서버는 만들어진 상태이므로
                // 실패를 기록만 하고 넘어간다 — 에이전트가 사후 진단으로 커버한다(#7).
                Log::warning('wisdom-ai-assistant: post_install 실패', [
                    'server' => $server->uuid_short,
                    'action' => $action['type'],
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * ⚠ **파일이 없으면 `to` 내용으로 새로 만든다.**
     *
     * `eula.txt` 는 설치 스크립트가 아니라 **첫 기동**이 만든다. 설치 완료 시점에는 아직 없으므로
     * "있으면 바꾼다"만 하면 아무 일도 일어나지 않고, 첫 기동이 `eula=false` 를 써놓고
     * 그대로 꺼진다(종료 코드 0이라 실패로도 안 보인다 — #13 의 1순위 진단).
     * 미리 써두어야 **첫 기동부터** 성공한다.
     *
     * @param array<string, mixed> $action
     */
    private function fileReplace(Server $server, array $action): void
    {
        $repository = $this->files($server);
        $path = '/' . ltrim((string) $action['path'], '/');

        try {
            $content = $repository->getContent($path, 65536);
        } catch (Throwable) {
            $repository->putContent($path, (string) $action['to']);

            return;
        }

        if (!str_contains($content, (string) $action['from'])) {
            return;
        }

        $repository->putContent($path, str_replace($action['from'], $action['to'], $content));
    }

    /**
     * JSON 안의 JVM 인자를 배정 메모리에 맞춰 고친다(좀보이드).
     *
     * @param array<string, mixed> $action
     */
    private function jsonVmArg(Server $server, array $action): void
    {
        $repository = $this->files($server);
        $path = '/' . ltrim((string) $action['path'], '/');

        $json = json_decode($repository->getContent($path, 65536), true);

        if (!is_array($json) || !is_array($json['vmArgs'] ?? null)) {
            return;
        }

        // 컨테이너 제한 전부를 힙에 주면 JVM 자체 오버헤드에서 터진다 → 카탈로그가 비율을 준다.
        $megabytes = (int) floor($server->memory * (float) $action['value_ratio']);
        $prefix = (string) $action['arg'];

        $json['vmArgs'] = array_map(
            fn (string $arg) => str_starts_with($arg, $prefix) ? "{$prefix}{$megabytes}m" : $arg,
            $json['vmArgs'],
        );

        $repository->putContent($path, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '');
    }

    private function files(Server $server): DaemonFileRepository
    {
        /** @var DaemonFileRepository $repository */
        $repository = app(DaemonFileRepository::class);

        return $repository->setServer($server);
    }
}
