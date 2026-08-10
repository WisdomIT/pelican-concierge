<?php

namespace WisdomIT\Concierge\Services;

use WisdomIT\Concierge\Tools\ToolCallResult;

/**
 * 한 번의 사용자 발화에 대한 결과. 도구 왕복이 여러 번 있었어도 여기로 합쳐 나온다.
 *
 * `card` 가 있으면 **아직 끝나지 않은 것**이다 — 사용자 확인을 기다리는 중이고,
 * `state` 를 보관했다가 결정이 오면 그대로 이어서 돌리면 된다.
 */
final readonly class ChatResult
{
    /**
     * @param array<int, ToolCallResult>  $toolCalls
     * @param ?array<string, mixed>       $card   확인 카드 사양 (없으면 완료)
     * @param array<string, mixed>        $state  카드가 있을 때 재개에 필요한 전체 상태
     */
    public function __construct(
        public string $text,
        public int $inputTokens,
        public int $outputTokens,
        public ?string $stopReason,
        public array $toolCalls = [],
        public ?array $card = null,
        public array $state = [],
        /** 이 응답에서 돈 웹 검색 횟수. 토큰과 별도로 과금되므로 따로 센다(#43). */
        public int $searchCount = 0,
    ) {}

    public function needsConfirmation(): bool
    {
        return $this->card !== null;
    }

    /**
     * 안전 분류기가 요청을 거절한 경우. HTTP 는 200 이고 본문이 비어 있거나 잘려 있으므로
     * 텍스트 유무로 판단하면 안 된다 — stop_reason 을 봐야 한다.
     */
    public function isRefusal(): bool
    {
        return $this->stopReason === 'refusal';
    }
}
