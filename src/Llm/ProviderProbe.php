<?php

namespace WisdomIT\Concierge\Llm;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use Throwable;

/**
 * 공급자 엔드포인트 찔러보기 (#3) — 키 검증과 모델 목록.
 *
 * 셋 다 `GET /models` 를 쓴다: 토큰을 쓰지 않는 가장 싼 인증 검사이고,
 * 로컬 엔드포인트에서는 그 응답이 곧 모델 드롭다운의 재료다.
 */
final class ProviderProbe
{
    /**
     * 키(와 주소)가 실제로 통하는지 확인한다.
     *
     * @return ?string null = 정상. 아니면 사용자에게 보여줄 실패 사유.
     */
    public static function verify(string $provider, ?string $apiKey, ?string $baseUrl): ?string
    {
        if ($provider !== 'openai-compatible' && blank($apiKey)) {
            return trans('concierge::strings.verify_no_key');
        }

        if ($provider === 'openai-compatible' && blank($baseUrl)) {
            return trans('concierge::strings.verify_no_base_url');
        }

        try {
            [$url, $headers] = self::modelsEndpoint($provider, $apiKey, $baseUrl);

            $response = (new Client(['timeout' => 8, 'connect_timeout' => 5, 'http_errors' => false]))
                ->get($url, ['headers' => $headers]);

            $code = $response->getStatusCode();

            // Gemini 는 잘못된 키에 401 이 아니라 400(API_KEY_INVALID)을 준다.
            $badKeyCodes = $provider === 'gemini' ? [400, 401, 403] : [401, 403];

            return match (true) {
                $code === 200 => null,
                in_array($code, $badKeyCodes, true) => trans('concierge::strings.verify_bad_key'),
                default => trans('concierge::strings.verify_http', ['code' => $code]),
            };
        } catch (ConnectException) {
            return trans('concierge::strings.verify_unreachable');
        } catch (Throwable $exception) {
            return $exception->getMessage();
        }
    }

    /**
     * 이 모델로는 이 어시스턴트를 못 돌린다 — id 에 이 조각이 들어가면 뺀다 (#80).
     *
     * 🔴 **잘못 거르는 쪽이 안 거르는 것보다 나쁘다.** 사용자가 text-embedding-3-large 를
     *    고르면 어시스턴트가 통째로 망가지고, 왜인지도 알 수 없다.
     *
     * 실제 응답을 보고 정했다(2026-08 실측: OpenAI 124개, Gemini 52개):
     *  · 다른 감각(임베딩·음성·이미지·영상·음악): embedding, whisper, transcribe, tts,
     *    audio, image, imagen, veo, sora, lyria, nano-banana, moderation, aqa
     *  · 대화형이 아닌 것: instruct·babbage·davinci(구 completions API),
     *    realtime·live(스트리밍 세션), search-preview·search-api(도구 호출 불가)
     *  · 우리 도구 규약과 맞지 않는 특수 모델: robotics, computer-use, deep-research,
     *    antigravity, gemma(함수 호출 미지원)
     *
     * ⚠ 이 목록은 **낡는다**. 새 감각이 생기면 한동안 목록에 섞이고, 그때는 고른 사람이
     *   오류를 본다 — 새 대화 모델을 아예 못 고르는 것보다 가벼운 실패라 이 방향을 택했다.
     */
    private const NOT_CHAT = [
        'embedding', 'whisper', 'transcribe', 'tts', 'audio', 'image', 'imagen', 'veo', 'sora',
        'lyria', 'nano-banana', 'moderation', 'aqa', 'instruct', 'babbage', 'davinci',
        'realtime', 'live', 'search-preview', 'search-api', 'robotics', 'computer-use',
        'deep-research', 'antigravity', 'gemma',
    ];

    /**
     * 공급자가 **실제로 주는** 모델 목록 (#80). 실패하면 [] — 부르는 쪽이 배포본 목록으로
     * 물러난다. 목록은 공급자가 알고 우리는 모른다: 플러그인이 모르는 새 모델도 여기 있다.
     *
     * @return array<string, string> id => 표시 이름
     */
    public static function models(string $provider, ?string $apiKey, ?string $baseUrl = null): array
    {
        if ($provider === 'openai-compatible') {
            return self::localModels($baseUrl, $apiKey);
        }

        if (blank($apiKey)) {
            return [];
        }

        try {
            [$url, $headers] = self::modelsEndpoint($provider, $apiKey, $baseUrl);
            // 한 페이지에 다 받는다 — 기본 페이지 크기가 작아 최신 모델이 잘릴 수 있다.
            $url .= (str_contains($url, '?') ? '&' : '?') . match ($provider) {
                'anthropic' => 'limit=100',
                'gemini' => 'pageSize=200',
                default => '',
            };

            // 폼 렌더 경로다 — 느린 공급자가 설정 화면을 붙잡고 있으면 안 된다.
            $response = (new Client(['timeout' => 6, 'connect_timeout' => 4, 'http_errors' => false]))
                ->get($url, ['headers' => $headers]);

            if ($response->getStatusCode() !== 200) {
                return [];
            }

            $body = json_decode((string) $response->getBody(), true) ?: [];

            return collect($body['data'] ?? $body['models'] ?? [])
                ->mapWithKeys(function (array $model) {
                    // Gemini 는 'models/gemini-3.6-flash' 처럼 접두사를 붙여 준다.
                    $id = str_replace('models/', '', (string) ($model['id'] ?? $model['name'] ?? ''));

                    return [$id => (string) ($model['display_name'] ?? $model['displayName'] ?? $id)];
                })
                ->filter(fn (string $label, string $id) => $id !== '' && self::isChatModel($id))
                // Gemini 에는 대화 생성을 아예 못 하는 모델도 섞여 있다 — 구조가 알려주는
                // 신호가 있으면 추측보다 그걸 쓴다.
                ->filter(fn (string $label, string $id) => self::supportsGeneration($body, $id))
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /** id 로 판단하는 대화 모델 여부 — NOT_CHAT 주석 참고. */
    private static function isChatModel(string $id): bool
    {
        $needle = strtolower($id);

        foreach (self::NOT_CHAT as $word) {
            if (str_contains($needle, $word)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 응답이 능력을 알려주면 그걸 믿는다 — Gemini 의 supportedGenerationMethods 가
     * 유일하다(Anthropic 은 애초에 대화 모델만, OpenAI 는 아무 신호도 주지 않는다).
     *
     * @param  array<string, mixed>  $body
     */
    private static function supportsGeneration(array $body, string $id): bool
    {
        $model = collect($body['models'] ?? [])
            ->first(fn (array $m) => str_replace('models/', '', (string) ($m['name'] ?? '')) === $id);

        if ($model === null) {
            return true;
        }

        return in_array('generateContent', (array) ($model['supportedGenerationMethods'] ?? []), true);
    }

    /**
     * 로컬(OpenAI 호환) 엔드포인트의 모델 목록 — 드롭다운 재료. 실패하면 [].
     *
     * @return array<string, string> id => id
     */
    public static function localModels(?string $baseUrl, ?string $apiKey): array
    {
        if (blank($baseUrl)) {
            return [];
        }

        try {
            [$url, $headers] = self::modelsEndpoint('openai-compatible', $apiKey, $baseUrl);

            // 폼 렌더 경로에서 불린다 — 죽은 엔드포인트가 화면을 오래 잡으면 안 된다.
            $response = (new Client(['timeout' => 3, 'connect_timeout' => 2, 'http_errors' => false]))
                ->get($url, ['headers' => $headers]);

            if ($response->getStatusCode() !== 200) {
                return [];
            }

            $ids = collect(json_decode((string) $response->getBody(), true)['data'] ?? [])
                ->pluck('id')
                ->filter(fn ($id) => is_string($id) && $id !== '')
                ->sort()
                ->values();

            return $ids->combine($ids)->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array{0: string, 1: array<string, string>} [URL, 헤더]
     */
    private static function modelsEndpoint(string $provider, ?string $apiKey, ?string $baseUrl): array
    {
        return match ($provider) {
            'openai' => [
                (filled($baseUrl) ? rtrim($baseUrl, '/') : 'https://api.openai.com/v1') . '/models',
                ['Authorization' => 'Bearer ' . $apiKey],
            ],
            'openai-compatible' => [
                rtrim((string) $baseUrl, '/') . '/models',
                array_filter(['Authorization' => filled($apiKey) ? 'Bearer ' . $apiKey : null]),
            ],
            'gemini' => [
                (filled($baseUrl) ? rtrim($baseUrl, '/') : 'https://generativelanguage.googleapis.com/v1beta') . '/models',
                ['x-goog-api-key' => (string) $apiKey],
            ],
            default => [
                'https://api.anthropic.com/v1/models',
                ['x-api-key' => (string) $apiKey, 'anthropic-version' => '2023-06-01'],
            ],
        };
    }
}
