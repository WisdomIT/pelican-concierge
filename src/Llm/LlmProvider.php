<?php

namespace WisdomIT\Concierge\Llm;

use Closure;

/**
 * LLM 공급자 어댑터의 계약 (#3).
 *
 * 도구 루프(ChatService)는 이 계약만 알고 공급자를 모른다. 어댑터의 일은 셋뿐이다:
 * 중립 형식을 자기 와이어 형식으로 바꾸고, 스트리밍을 소비하고, 결과를 중립으로 눕힌다.
 *
 * ## 중립 대화 형식
 *
 * 대화는 이 모양의 배열이고, **확인 카드의 재개 상태에 그대로 저장된다** — 그래서
 * 카드가 떠 있는 동안 공급자를 바꿔도 재개가 성립한다(형식 변환이 무상태라서).
 *
 *   ['role' => 'user'|'assistant', 'text' => string]                      — 일반 발화
 *   ['role' => 'assistant', 'text' => string, 'tool_uses' => [
 *       ['id' => string, 'name' => string, 'input' => array], …]]        — 도구를 부른 턴
 *   ['role' => 'user', 'tool_results' => [
 *       ['id' => string, 'content' => string, 'is_error' => bool], …]]   — 도구 결과 회신
 *
 * tool_uses 항목에는 어댑터가 자기만 아는 여분 키를 얹어도 된다(예: Gemini 의
 * 'thought_signature') — 다른 어댑터는 모르는 키를 무시한다.
 *
 * ## 중립 도구 정의
 *
 * `['name' => …, 'description' => …, 'input_schema' => JSON Schema]`.
 * Anthropic 와이어 형식과 같게 정해뒀다 — 도구상자 37종을 다시 쓰지 않기 위해서다.
 * 다른 공급자 어댑터가 자기 형식(OpenAI function, Gemini functionDeclarations)으로 바꾼다.
 */
interface LlmProvider
{
    public function capabilities(): Capabilities;

    /**
     * 한 번의 모델 호출을 스트리밍으로 소비한다.
     *
     * @param  array<int, array<string, mixed>>  $messages  중립 대화(위 형식)
     * @param  string  $system  시스템 프롬프트(공급자 무관, 영어)
     * @param  array<int, array<string, mixed>>  $tools  중립 도구 정의. **[] 이면 도구 없이** 부른다
     * @param  string  $accumulatedText  이전 회차까지의 누적 텍스트 — 회차 사이 발화를 한 줄 띄워 잇는다
     * @param  Closure(string): void  $onText  누적 텍스트로 호출된다(부분 문자열이 아니라 전체)
     * @param  Closure(): void  $onThinking  모델이 생각을 시작할 때(지원 공급자만)
     */
    public function runTurn(
        array $messages,
        string $system,
        array $tools,
        string $accumulatedText,
        Closure $onText,
        Closure $onThinking,
    ): TurnResult;
}
