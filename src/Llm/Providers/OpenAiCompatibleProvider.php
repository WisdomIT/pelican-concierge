<?php

namespace WisdomIT\Concierge\Llm\Providers;

use Closure;
use GuzzleHttp\Client;
use WisdomIT\Concierge\Llm\Capabilities;
use WisdomIT\Concierge\Llm\LlmProvider;
use WisdomIT\Concierge\Llm\StopKind;
use WisdomIT\Concierge\Llm\Support\SseStream;
use WisdomIT\Concierge\Llm\TurnResult;
use WisdomIT\Concierge\Models\ConciergeSettings;

/**
 * OpenAI 호환(로컬) 어댑터 (#3) — Ollama · vLLM · llama.cpp 등.
 *
 * 로컬 추론 서버의 사실상 표준은 **Chat Completions** 라 그쪽을 쓴다(OpenAI 본가는
 * Responses API — 별도 어댑터). base URL 은 `/v1` 까지 포함해 설정한다
 * (예: http://localhost:11434/v1).
 *
 * 웹 검색·effort 는 없다 — capabilities 가 false 를 말하고 설정 화면이 그걸 숨긴다.
 */
class OpenAiCompatibleProvider implements LlmProvider
{
    private ?Client $client = null;

    public function __construct(protected readonly ConciergeSettings $settings) {}

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            supportsTools: true,
            supportsWebSearch: false,
            supportsEffort: false,
            needsBaseUrl: true,
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
        $payload = [
            'model' => $this->settings->model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ...$this->wireMessages($messages),
            ],
            'stream' => true,
            // 마지막 청크에 usage 를 실어 달라는 요청 — 안 주는 서버도 있다(0 으로 남는다).
            'stream_options' => ['include_usage' => true],
            'max_tokens' => $this->settings->max_tokens,
        ];

        if ($tools !== []) {
            $payload['tools'] = array_map(fn (array $tool) => [
                'type' => 'function',
                'function' => [
                    'name' => $tool['name'],
                    'description' => $tool['description'] ?? '',
                    'parameters' => $tool['input_schema'] ?? ['type' => 'object'],
                ],
            ], $tools);
        }

        $response = $this->client()->post('chat/completions', [
            'json' => $payload,
            'stream' => true,
        ]);

        $text = $accumulatedText;
        $turnText = '';
        $inputTokens = 0;
        $outputTokens = 0;
        $finishReason = null;
        $thinkingAnnounced = false;

        /** @var array<int, array{id: string, name: string, json: string}> $pendingTools */
        $pendingTools = [];

        foreach (SseStream::events($response->getBody()) as $event) {
            if ($event['data'] === '[DONE]') {
                break;
            }

            $chunk = json_decode($event['data'], true);

            if (!is_array($chunk)) {
                continue;
            }

            // usage 청크는 choices 가 빈 배열로 온다 — 값만 챙기고 넘어간다.
            if (isset($chunk['usage'])) {
                $inputTokens = (int) ($chunk['usage']['prompt_tokens'] ?? $inputTokens);
                $outputTokens = (int) ($chunk['usage']['completion_tokens'] ?? $outputTokens);
            }

            $choice = $chunk['choices'][0] ?? null;

            if ($choice === null) {
                continue;
            }

            $delta = $choice['delta'] ?? [];

            // 추론 모델(DeepSeek 계열 등)의 사고 채널 — 내용은 안 쓰고 "생각 중"만 알린다.
            if (!$thinkingAnnounced && trim((string) ($delta['reasoning_content'] ?? '')) !== '') {
                $thinkingAnnounced = true;
                $onThinking();
            }

            if (($delta['content'] ?? '') !== '' && $delta['content'] !== null) {
                // 도구 왕복 사이의 발화가 이어 붙으므로 한 줄 띄워 구분한다.
                if ($turnText === '' && $text !== '') {
                    $text .= "\n\n";
                }

                $turnText .= $delta['content'];
                $text .= $delta['content'];
                $onText($text);
            }

            foreach ($delta['tool_calls'] ?? [] as $call) {
                $index = (int) ($call['index'] ?? 0);

                // 첫 조각이 id·이름을 들고 오고, 인자는 조각조각 이어진다.
                $pendingTools[$index] ??= ['id' => '', 'name' => '', 'json' => ''];

                if (($call['id'] ?? '') !== '') {
                    $pendingTools[$index]['id'] = $call['id'];
                }

                if (($call['function']['name'] ?? '') !== '') {
                    $pendingTools[$index]['name'] = $call['function']['name'];
                }

                $pendingTools[$index]['json'] .= (string) ($call['function']['arguments'] ?? '');
            }

            if (($choice['finish_reason'] ?? null) !== null) {
                $finishReason = $choice['finish_reason'];
            }
        }

        $toolUses = $this->decodeToolUses($pendingTools);

        return new TurnResult(
            text: $text,
            turnText: $turnText,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            // ⚠ finish_reason 보다 도구 유무를 우선한다 — 일부 로컬 서버(구버전 Ollama 등)는
            //   tool_calls 를 내고도 finish_reason 을 stop 으로 찍는다.
            stopKind: $toolUses !== [] ? StopKind::ToolUse : StopKind::EndTurn,
            rawStopReason: $finishReason,
            toolUses: $toolUses,
            searchCount: 0,
        );
    }

    protected function client(): Client
    {
        return $this->client ??= new Client([
            'base_uri' => rtrim((string) $this->settings->base_url, '/') . '/',
            'headers' => array_filter([
                // 로컬 서버는 보통 키가 없어도 된다 — 있으면 보낸다.
                'Authorization' => filled($this->settings->apiKey()) ? 'Bearer ' . $this->settings->apiKey() : null,
                'Content-Type' => 'application/json',
            ]),
            // 도구 왕복 포함 한 턴이 길 수 있다 — FPM 의 set_time_limit(600)과 보조를 맞춘다.
            'timeout' => 570,
            'connect_timeout' => 10,
        ]);
    }

    /**
     * 중립 대화 → Chat Completions 형식.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<int, array<string, mixed>>
     */
    private function wireMessages(array $messages): array
    {
        $wire = [];

        foreach ($messages as $message) {
            if (isset($message['tool_results'])) {
                // Chat Completions 는 결과를 **결과당 한 메시지**(role: tool)로 받는다.
                foreach ($message['tool_results'] as $result) {
                    $wire[] = [
                        'role' => 'tool',
                        'tool_call_id' => $result['id'],
                        'content' => ($result['is_error'] ? 'ERROR: ' : '') . $result['content'],
                    ];
                }

                continue;
            }

            if (isset($message['tool_uses'])) {
                $wire[] = [
                    'role' => 'assistant',
                    'content' => trim((string) ($message['text'] ?? '')) !== '' ? $message['text'] : null,
                    'tool_calls' => array_map(fn (array $use) => [
                        'id' => $use['id'],
                        'type' => 'function',
                        'function' => [
                            'name' => $use['name'],
                            'arguments' => json_encode($use['input'] === [] ? (object) [] : $use['input'], JSON_UNESCAPED_UNICODE),
                        ],
                    ], $message['tool_uses']),
                ];

                continue;
            }

            $wire[] = ['role' => $message['role'], 'content' => $message['text']];
        }

        return $wire;
    }

    /**
     * @param  array<int, array{id: string, name: string, json: string}>  $pendingTools
     * @return array<int, array{id: string, name: string, input: array<string, mixed>}>
     */
    private function decodeToolUses(array $pendingTools): array
    {
        $uses = [];

        foreach ($pendingTools as $index => $tool) {
            $input = $tool['json'] === '' ? [] : json_decode($tool['json'], true);

            $uses[] = [
                // 일부 로컬 서버는 id 를 안 준다 — 결과 짝을 맞출 수 있게 만들어 준다.
                'id' => $tool['id'] !== '' ? $tool['id'] : 'call_' . $index . '_' . substr(md5($tool['name'] . $tool['json']), 0, 8),
                'name' => $tool['name'],
                'input' => is_array($input) ? $input : [],
            ];
        }

        return $uses;
    }
}
