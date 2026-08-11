<?php

namespace WisdomIT\Concierge\Llm;

/**
 * 턴이 멈춘 이유의 중립 표현 (#3). 공급자마다 이름이 다르다 —
 * Anthropic 은 tool_use/pause_turn/refusal, OpenAI 는 tool_calls/…, Gemini 는
 * finishReason — 루프는 이 enum 만 보고 공급자를 모른다.
 */
enum StopKind
{
    /** 말로 끝났다 — 최종 답변. */
    case EndTurn;

    /** 도구를 부르고 멈췄다 — 실행해서 결과를 돌려줘야 한다. */
    case ToolUse;

    /**
     * 공급자가 턴을 중간에 끊었다(Anthropic 의 pause_turn — 서버 측 도구가 길어질 때).
     * 여기서 끝내면 사용자는 잘린 문장을 본다 — 이어받아 한 바퀴 더 돈다.
     * 다른 공급자는 이 값을 내지 않는다.
     */
    case Paused;

    /** 안전 분류기의 거절. HTTP 200 에 본문이 비어 있을 수 있다 — stop 이유로만 안다. */
    case Refusal;
}
