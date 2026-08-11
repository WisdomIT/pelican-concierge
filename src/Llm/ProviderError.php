<?php

namespace WisdomIT\Concierge\Llm;

use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ConnectException;
use Throwable;

/**
 * 공급자 호출 실패를 **사용자가 읽을 이유**로 바꾼다 (#3).
 *
 * 원문(API 오류 문자열)은 로그와 사용 기록에만 남는다 — 채팅에는 "왜 안 되는지,
 * 누가 무엇을 하면 되는지"만 보여준다. 특히 쿼터(429)는 키가 멀쩡해도 나온다:
 * 연결 확인은 통과했는데 채팅만 안 되는 경우의 대부분이 이거다
 * (예: Gemini 의 Pro 프리뷰는 유료 결제가 연결된 키에만 쿼터가 있다).
 */
final class ProviderError
{
    /**
     * @return ?string null = 아는 유형이 아니다 — 일반 오류 문구를 쓸 것.
     */
    public static function userMessage(Throwable $exception): ?string
    {
        $status = self::statusOf($exception);

        return match (true) {
            $status === 429 => trans('concierge::strings.provider_quota'),
            $status === 404 => trans('concierge::strings.provider_model_gone'),
            in_array($status, [400, 401, 403], true) && self::looksLikeAuth($exception)
                => trans('concierge::strings.provider_auth'),
            $status !== null && $status >= 500 => trans('concierge::strings.provider_down'),
            $exception instanceof ConnectException,
            $exception instanceof APIConnectionException => trans('concierge::strings.provider_unreachable'),
            default => null,
        };
    }

    private static function statusOf(Throwable $exception): ?int
    {
        return match (true) {
            // Anthropic SDK — status 를 공개 필드로 들고 있다.
            $exception instanceof APIStatusException => $exception->status,
            // Guzzle 직접 사용 어댑터(OpenAI · 호환 · Gemini).
            $exception instanceof BadResponseException => $exception->getResponse()->getStatusCode(),
            default => null,
        };
    }

    /**
     * 400 은 인증 실패일 수도(Gemini 의 API_KEY_INVALID), 우리 요청 잘못일 수도 있다.
     * 본문에 키 얘기가 있을 때만 인증 문제로 말한다 — 401/403 은 항상 인증이다.
     */
    private static function looksLikeAuth(Throwable $exception): bool
    {
        if (self::statusOf($exception) !== 400) {
            return true;
        }

        return (bool) preg_match('/api[ _-]?key|credential|unauthenticated/i', $exception->getMessage());
    }
}
