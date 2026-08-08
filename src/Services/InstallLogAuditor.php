<?php

namespace WisdomIT\WisdomAiAssistant\Services;

use Anthropic\Client;
use App\Models\Server;
use App\Repositories\Daemon\DaemonServerRepository;
use Throwable;
use WisdomIT\WisdomAiAssistant\Models\WisdomAiAssistantSettings;
use WisdomIT\WisdomAiAssistant\Support\SecretMasker;

/**
 * 설치 로그를 읽고 **설치가 실제로 끝났는지** 판정한다 (#7).
 *
 * **왜 용량이 아니라 로그인가** — 용량으로 하려다 실패했다(실측):
 *  - wings 는 **꺼져 있는 서버의 볼륨 용량을 집계하지 않는다.** 실제 276MB 인 서버가 9바이트로
 *    보고됐고, 하필 갓 설치돼 아직 켜본 적 없는 서버가 정확히 그 상태다.
 *  - **재설치는 볼륨을 비우지 않는다.** 실패한 재설치 뒤에도 이전 설치의 179MB 가 남아
 *    용량으로는 판정이 뒤집히지 않는다.
 *
 * 로그는 설치 직후 바로, 서버를 켜지 않고도 읽을 수 있고 실패가 확실히 드러난다:
 *
 *   Downloading forge version null
 *   link is invalid. Exiting now          ← 이 뒤로 아무것도 설치되지 않았다
 *
 * ⚠ **게임마다 성공 문구가 다르다.** 18종에 문구를 박아 넣는 대신 모델에게 로그를 읽힌다 —
 *   그게 이 플러그인이 존재하는 이유이기도 하다.
 *
 * ⚠ 판정만 한다. **아무것도 실행하지 않는다** — 재설치는 사용자가 카드에서 결정한다.
 */
final class InstallLogAuditor
{
    /** 모델에 넘길 로그 끝부분 길이. 실패는 항상 끝에 있고, 앞부분은 apt 출력뿐이다. */
    private const TAIL_CHARS = 6000;

    /** 판정은 짧은 JSON 하나면 된다. */
    private const MAX_TOKENS = 512;

    public function __construct(private readonly WisdomAiAssistantSettings $settings) {}

    /**
     * @return ?array{ok: bool, reason: string}  판정할 수 없으면 null
     */
    public function audit(Server $server): ?array
    {
        $log = $this->tail($server);

        if ($log === null) {
            return null;
        }

        try {
            $response = (new Client(apiKey: $this->settings->apiKey()))->messages->create(
                maxTokens: self::MAX_TOKENS,
                messages: [['role' => 'user', 'content' => $log]],
                model: $this->settings->model,
                system: $this->prompt(),
            );
        } catch (Throwable) {
            // 모델을 못 부르는 것과 설치가 실패한 것은 다르다. 단정하지 않는다.
            return null;
        }

        return $this->parse($this->textOf($response));
    }

    /**
     * ⚠ 설치 로그에는 시작 명령이 섞여 들어올 수 있다 → **모델에 넘기기 전에 마스킹한다.**
     *   도구 결과와 같은 규칙이다(#13).
     */
    private function tail(Server $server): ?string
    {
        try {
            /** @var DaemonServerRepository $repository */
            $repository = app(DaemonServerRepository::class);
            $log = $repository->setServer($server)->getInstallLogs();
        } catch (Throwable) {
            return null;
        }

        $log = trim((string) $log);

        if ($log === '') {
            return null;
        }

        return SecretMasker::forServer($server)->mask(mb_substr($log, -self::TAIL_CHARS));
    }

    /**
     * ⚠ `reason` 은 **사용자가 읽는다** — 알림 문구에 그대로 박힌다. 지시는 영어로 쓰되
     *   (토큰이 싸다) 결과 문장은 패널 기본 언어로 받는다.
     */
    private function prompt(): string
    {
        $code = (string) config('app.locale', 'en');
        $language = class_exists(\Locale::class) ? \Locale::getDisplayLanguage($code, 'en') : $code;

        return <<<PROMPT
            Read a game server's install script log and judge whether the install **finished properly**.

            Answer only in this JSON shape. Write nothing else.

            {"ok": true|false, "reason": "one sentence in {$language}"}

            How to judge:
            - ok=true if the game files were actually downloaded and the install completed
            - ok=false if a download link was wrong, it exited partway, or required files never arrived
            - apt output, progress bars and warnings alone are not a failure
            - **When you cannot tell, answer ok=true.** Calling a healthy server broken is the worse mistake.

            The reason is read by someone with no server knowledge. Do not copy the log — say in one
            plain sentence what did not work, avoiding technical terms.
            PROMPT;
    }

    /**
     * ⚠ **블록 종류를 반드시 걸러야 한다.** Opus 5 는 thinking 이 기본으로 켜져 있어 응답
     *   첫 블록이 `thinking` 이다. SDK 객체는 없는 속성 접근에 예외를 던지므로
     *   `$block->text ?? ''` 로는 못 넘어간다 — 실제로 그렇게 작업이 죽었다.
     */
    private function textOf(mixed $response): string
    {
        $text = '';

        foreach ($response->content ?? [] as $block) {
            if (($block->type ?? null) === 'text') {
                $text .= $block->text;
            }
        }

        return $text;
    }

    /** @return ?array{ok: bool, reason: string} */
    private function parse(string $text): ?array
    {
        // 모델이 코드펜스를 두르는 경우가 있다.
        if (preg_match('/\{.*}/s', $text, $matches) !== 1) {
            return null;
        }

        $decoded = json_decode($matches[0], true);

        if (!is_array($decoded) || !isset($decoded['ok'])) {
            return null;
        }

        return [
            'ok' => (bool) $decoded['ok'],
            'reason' => trim((string) ($decoded['reason'] ?? '')),
        ];
    }
}
