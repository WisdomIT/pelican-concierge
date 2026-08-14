<?php

namespace WisdomIT\Concierge\Llm;

/**
 * 공급자 호출이 실패한 **까닭의 갈래** (#89).
 *
 * ProviderError 는 이 갈래를 사용자 문구로 옮기고, ProviderChain 은 같은 갈래를 보고
 * "다음 항목으로 넘어갈 것인가, 얼마나 쉬게 할 것인가"를 정한다. 분류가 하나여야
 * 채팅에 뜨는 이유와 실제 동작이 갈리지 않는다.
 */
enum ProviderFailure: string
{
    /** 쿼터·크레딧 소진(429). #89 를 만든 바로 그 경우다. */
    case Quota = 'quota';

    /** 공급자 쪽 장애(5xx). */
    case Down = 'down';

    /** 닿지 않는다 — 연결 실패·타임아웃. 로컬 엔드포인트가 꺼져 있을 때가 흔하다. */
    case Unreachable = 'unreachable';

    /** 모델이 사라졌다(404). 다음 항목은 자기 모델을 쓰므로 넘어가면 살아난다. */
    case ModelGone = 'model_gone';

    /** 키가 거부됐다(401·403, 혹은 키 얘기를 하는 400). */
    case Auth = 'auth';

    /** 우리 요청이 잘못됐거나 알 수 없다. */
    case Request = 'request';

    /**
     * 다음 항목으로 넘어갈 일인가.
     *
     * 🔴 **Request 는 넘기지 않는다.** 도구 스키마나 대화 형식이 잘못된 것이라면 다음
     *    공급자도 똑같이 실패한다 — 두 곳에서 토큰만 태우고 사용자는 같은 오류를 본다.
     */
    public function shouldFailOver(): bool
    {
        return $this !== self::Request;
    }

    /**
     * 실패한 항목을 얼마나 쉬게 둘 것인가.
     *
     * ⚠ 쉬는 시간이 끝나면 **다시 시도한다** — 쿼터는 정시에 풀리고 장애는 끝난다.
     *   틀리면 다시 쉬게 되므로 스스로 바로잡힌다. 그래서 짧게 잡아도 위험하지 않고,
     *   대신 거절하는 공급자를 매 대화마다 두드리지는 않는다.
     *
     * 🔴 Auth 만 길다. 키가 거부되는 것은 날씨가 아니라 **설정 오류**라 사람이 고치기
     *    전까지 저절로 낫지 않는다 — 그동안 5분마다 두드릴 이유가 없다. 대신 관리자에게
     *    알림이 간다.
     */
    public function cooldownMinutes(): int
    {
        return match ($this) {
            self::Auth => 30,
            self::Quota => 15,
            self::ModelGone => 30,
            default => 5,
        };
    }

    /** 관리자에게 알릴 만한 일인가 — 설정을 고쳐야 낫는 것들. */
    public function needsAttention(): bool
    {
        return match ($this) {
            self::Auth, self::ModelGone => true,
            default => false,
        };
    }
}
