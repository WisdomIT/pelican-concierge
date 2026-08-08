<?php

namespace WisdomIT\WisdomAiAssistant\Support;

use App\Repositories\Daemon\DaemonServerRepository;

/**
 * 컨테이너의 최근 콘솔 출력.
 *
 * **왜 파일 로그로는 부족한가** — 앱이 뜨기 전에 죽으면 `/logs/latest.log` 는 아예 생기지 않는다.
 * 실제로 겪은 사례: 메모리 한도가 0 인 자바 서버가 `-Xmx0M` 으로 실행돼 JVM 이 시작조차 못 했다.
 * 파일만 보면 "한 번도 실행된 적 없음"으로 보이고, 진짜 원인은 콘솔에만 남는다.
 *
 * ⚠ **Pelican 내부에 두 겹으로 결합한다** — 패널을 올릴 때 함께 확인할 것.
 *   1) `DaemonRepository::getHttpClient()` (protected) 를 상속으로 빌려 쓴다.
 *   2) wings 의 `GET /api/servers/{uuid}/logs?size=N` 엔드포인트. Pelican 본체는 콘솔을
 *      websocket 으로 받으므로 이 엔드포인트를 쓰지 않는다 → 조용히 사라질 수 있다.
 */
final class ConsoleLog extends DaemonServerRepository
{
    /**
     * @return array<int, string> 오래된 줄부터
     */
    public function recentLines(int $size = 200): array
    {
        $data = $this->getHttpClient()
            ->get("/api/servers/{$this->server->uuid}/logs", ['size' => $size])
            ->throw()
            ->json('data');

        if (!is_array($data)) {
            return [];
        }

        return array_values(array_map(self::strip(...), $data));
    }

    /**
     * 콘솔에는 색상 제어문자가 섞여 있다. 그대로 모델에 넘기면 토큰만 먹고 읽기도 나쁘다.
     */
    private static function strip(string $line): string
    {
        return trim(preg_replace('/\e\[[0-9;]*[A-Za-z]|\r/', '', $line) ?? $line);
    }
}
