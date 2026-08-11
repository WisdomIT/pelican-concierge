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
