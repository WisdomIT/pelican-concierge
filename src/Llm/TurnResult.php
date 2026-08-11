<?php

namespace WisdomIT\Concierge\Llm;

/**
 * 한 번의 모델 호출 결과, 중립 형식 (#3). 어댑터가 자기 와이어 형식을 여기로 눕힌다.
 */
final class TurnResult
{
    public function __construct(
        /** 이번 요청 전체의 누적 텍스트(이전 회차 포함) — 화면 스트리밍과 최종 답변이 이걸 쓴다. */
        public readonly string $text,
        /** 이번 턴에서 새로 생성된 텍스트만 — 대화 이력에 되돌려줄 때 쓴다. */
        public readonly string $turnText,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly StopKind $stopKind,
        /** 공급자가 준 원문 stop 이유 — 로그·진단용. 제어 흐름은 stopKind 만 쓴다. */
        public readonly ?string $rawStopReason,
        /** @var array<int, array{id: string, name: string, input: array<string, mixed>}> */
        public readonly array $toolUses,
        /** 서버 측 웹 검색 횟수 — 토큰과 별도로 과금되므로 따로 센다. */
        public readonly int $searchCount,
    ) {}
}
