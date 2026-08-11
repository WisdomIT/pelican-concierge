<?php

namespace WisdomIT\Concierge\Llm\Providers;

use Anthropic\Client;
use Anthropic\Messages\InputJSONDelta;
use Anthropic\Messages\RawContentBlockDeltaEvent;
use Anthropic\Messages\RawContentBlockStartEvent;
use Anthropic\Messages\RawMessageDeltaEvent;
use Anthropic\Messages\RawMessageStartEvent;
use Anthropic\Messages\TextDelta;
use Closure;
use WisdomIT\Concierge\Llm\Capabilities;
use WisdomIT\Concierge\Llm\LlmProvider;
use WisdomIT\Concierge\Llm\StopKind;
use WisdomIT\Concierge\Llm\TurnResult;
use WisdomIT\Concierge\Models\ConciergeSettings;

/**
 * Anthropic (Claude) 어댑터 (#3) — 기본 공급자, 기존 동작 그대로.
 *
 * AnthropicChatService 에서 와이어 층만 떼어 온 것이다: SDK 호출, SSE 이벤트 파싱,
 * 서버 측 웹 검색, thinking 알림, 중립 ↔ Anthropic 형식 변환. 루프·상태·프롬프트는
 * ChatService 에 남았다.
 */
final class AnthropicProvider implements LlmProvider
{
    private ?Client $client = null;

    public function __construct(private readonly ConciergeSettings $settings) {}

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            supportsTools: true,
            supportsWebSearch: true, // 서버 측 web_search — API 가 추론 도중 직접 돌린다
            supportsEffort: true,
            needsBaseUrl: false,
        );
    }

    public function runTurn(
        array $messages,
        string $system,
        array $tools,
        string $accumulatedText,
        Closure $onText,
        Closure $onThinking,
    ): TurnResult {
        $stream = $this->client()->messages->createStream(
            maxTokens: $this->settings->max_tokens,
            messages: $this->wireMessages($messages),
            model: $this->settings->model,
            outputConfig: ['effort' => $this->settings->effort],
            system: $system,
            // Opus 5 는 thinking 이 기본으로 켜져 있다. display 는 기본값(omitted)을 쓰고,
            // thinking 블록이 열리는 시점만 "생각 중" 표시에 쓴다.
            thinking: ['type' => 'adaptive'],
            tools: $tools === [] ? null : $this->wireTools($tools),
        );

        $text = $accumulatedText;
        $turnText = '';
        $inputTokens = 0;
        $outputTokens = 0;
        $stopReason = null;
        $thinkingAnnounced = false;
        $searchCount = 0;

        /** @var array<int, array{id: string, name: string, json: string}> $pendingTools */
        $pendingTools = [];

        foreach ($stream as $event) {
            if ($event instanceof RawMessageStartEvent) {
                $inputTokens = $event->message->usage->inputTokens;

                continue;
            }

            if ($event instanceof RawContentBlockStartEvent) {
                $block = $event->contentBlock;

                // ⚠ **서버 측 도구는 우리가 실행하지 않는다.** 웹 검색을 켜면 응답에
                //   server_tool_use / web_search_tool_result 블록이 섞여 온다. 이것을
                //   tool_use 로 착각해 큐에 넣으면 "없는 도구"로 실패한다.
                //   (thinking 블록에서 겪은 것과 같은 종류의 함정)
                if (($block->type ?? null) === 'server_tool_use') {
                    $searchCount++;

                    continue;
                }

                if (($block->type ?? null) === 'web_search_tool_result') {
                    continue;
                }

                if (($block->type ?? null) === 'tool_use') {
                    // 입력 JSON 은 이어지는 델타로 조각조각 온다 → index 로 모은다.
                    $pendingTools[$event->index] = ['id' => $block->id, 'name' => $block->name, 'json' => ''];

                    continue;
                }

                if (!$thinkingAnnounced && ($block->type ?? null) === 'thinking') {
                    $thinkingAnnounced = true;
                    $onThinking();
                }

                continue;
            }

            if ($event instanceof RawContentBlockDeltaEvent) {
                if ($event->delta instanceof TextDelta) {
                    // 도구 왕복 사이의 발화가 이어 붙으므로 한 줄 띄워 구분한다.
                    if ($turnText === '' && $text !== '') {
                        $text .= "\n\n";
                    }

                    $turnText .= $event->delta->text;
                    $text .= $event->delta->text;
                    $onText($text);
                } elseif ($event->delta instanceof InputJSONDelta && isset($pendingTools[$event->index])) {
                    $pendingTools[$event->index]['json'] .= $event->delta->partialJSON;
                }

                continue;
            }

            if ($event instanceof RawMessageDeltaEvent) {
                $stopReason = $event->delta->stopReason;
                $outputTokens = $event->usage->outputTokens;
            }
        }

        return new TurnResult(
            text: $text,
            turnText: $turnText,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            stopKind: $this->stopKind($stopReason),
            rawStopReason: $stopReason,
            toolUses: $this->decodeToolUses($pendingTools),
            searchCount: $searchCount,
        );
    }

    private function client(): Client
    {
        return $this->client ??= new Client(apiKey: $this->settings->apiKey());
    }

    private function stopKind(?string $stopReason): StopKind
    {
        return match ($stopReason) {
            'tool_use' => StopKind::ToolUse,
            // ⚠ 웹 검색이 길어지면 API 가 턴을 **중간에 끊고** pause_turn 을 준다(#43).
            'pause_turn' => StopKind::Paused,
            // 안전 분류기가 거절하면 HTTP 는 200 이고 본문이 비어 있다 — stop 이유가 유일한 신호.
            'refusal' => StopKind::Refusal,
            default => StopKind::EndTurn,
        };
    }

    /**
     * 중립 대화 → Anthropic 와이어 형식.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<int, array<string, mixed>>
     */
    private function wireMessages(array $messages): array
    {
        $wire = [];

        foreach ($messages as $message) {
            if (isset($message['tool_results'])) {
                // tool_result 는 그 턴의 tool_use **전부**에 대해 한 번에 보내야 한다.
                $wire[] = ['role' => 'user', 'content' => array_map(fn (array $result) => [
                    'type' => 'tool_result',
                    'toolUseID' => $result['id'],
                    'content' => $result['content'],
                    'isError' => $result['is_error'],
                ], $message['tool_results'])];

                continue;
            }

            if (isset($message['tool_uses'])) {
                $content = [];

                if (trim((string) ($message['text'] ?? '')) !== '') {
                    $content[] = ['type' => 'text', 'text' => $message['text']];
                }

                foreach ($message['tool_uses'] as $use) {
                    $content[] = [
                        'type' => 'tool_use',
                        'id' => $use['id'],
                        'name' => $use['name'],
                        'input' => $use['input'] === [] ? (object) [] : $use['input'],
                    ];
                }

                $wire[] = ['role' => 'assistant', 'content' => $content];

                continue;
            }

            $wire[] = ['role' => $message['role'], 'content' => $message['text']];
        }

        return $wire;
    }

    /**
     * 중립 도구 정의는 Anthropic 형식과 같다(계약 참고) — 서버 측 웹 검색만 여기서 붙인다.
     *
     * ⚠ 웹 검색은 우리가 실행하지 않는다 — Anthropic 서버가 추론 도중 직접 돌리고 결과를
     *   모델에게 먹인다. 그래서 도구상자가 아니라 어댑터의 소관이다.
     *
     * @param  array<int, array<string, mixed>>  $tools
     * @return array<int, array<string, mixed>>
     */
    private function wireTools(array $tools): array
    {
        if ($this->settings->search_enabled) {
            $tools[] = [
                'type' => 'web_search_20250305',
                'name' => 'web_search',
                // 검색은 토큰과 별도로 과금된다 — 한 턴에 무한정 돌지 않게 상한을 건다.
                'max_uses' => max(1, $this->settings->search_max_uses),
            ];
        }

        return $tools;
    }

    /**
     * @param  array<int, array{id: string, name: string, json: string}>  $pendingTools
     * @return array<int, array{id: string, name: string, input: array<string, mixed>}>
     */
    private function decodeToolUses(array $pendingTools): array
    {
        $uses = [];

        foreach ($pendingTools as $tool) {
            // 인자 없는 도구는 델타가 아예 안 오거나 "{}" 로 온다.
            $input = $tool['json'] === '' ? [] : json_decode($tool['json'], true);

            $uses[] = [
                'id' => $tool['id'],
                'name' => $tool['name'],
                'input' => is_array($input) ? $input : [],
            ];
        }

        return $uses;
    }
}
