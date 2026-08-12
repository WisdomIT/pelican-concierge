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
 * OpenAI (ChatGPT) 어댑터 (#3) — **Responses API**.
 *
 * Chat Completions 가 아니라 Responses 를 쓰는 이유: 네이티브 웹 검색(web_search
 * 서버 측 도구)과 reasoning effort 가 여기에 있다 — Anthropic 어댑터와 기능 등가를
 * 맞추는 유일한 표면이다. (로컬 호환 엔드포인트는 Chat Completions — 별도 어댑터.)
 */
final class OpenAiProvider implements LlmProvider
{
    private const BASE_URL = 'https://api.openai.com/v1/';

    private ?Client $client = null;

    public function __construct(private readonly ConciergeSettings $settings) {}

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            supportsTools: true,
            supportsWebSearch: true, // Responses 의 서버 측 web_search 도구
            supportsEffort: true,    // reasoning.effort (minimal/low/medium/high)
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
        $payload = [
            'model' => $this->settings->model,
            'instructions' => $system,
            'input' => $this->wireInput($messages),
            'stream' => true,
            'max_output_tokens' => $this->settings->max_tokens,
        ];

        if (filled($this->settings->effort)) {
            $payload['reasoning'] = ['effort' => $this->settings->effort];
        }

        if ($tools !== []) {
            $payload['tools'] = $this->wireTools($tools);
        }

        $response = $this->client()->post('responses', [
            'json' => $payload,
            'stream' => true,
        ]);

        $text = $accumulatedText;
        $turnText = '';
        $inputTokens = 0;
        $outputTokens = 0;
        $status = null;
        $thinkingAnnounced = false;
        $searchCount = 0;

        /** @var array<string, array{call_id: string, name: string, json: string}> $pendingTools item_id 로 모은다 */
        $pendingTools = [];

        foreach (SseStream::events($response->getBody()) as $event) {
            $data = json_decode($event['data'], true);

            if (!is_array($data)) {
                continue;
            }

            switch ($event['event'] ?? ($data['type'] ?? '')) {
                case 'response.output_text.delta':
                    // 도구 왕복 사이의 발화가 이어 붙으므로 한 줄 띄워 구분한다.
                    if ($turnText === '' && $text !== '') {
                        $text .= "\n\n";
                    }

                    $turnText .= (string) $data['delta'];
                    $text .= (string) $data['delta'];
                    $onText($text);
                    break;

                case 'response.output_item.added':
                    $item = $data['item'] ?? [];

                    if (($item['type'] ?? '') === 'function_call') {
                        // 인자는 이어지는 델타로 조각조각 온다 → item id 로 모은다.
                        $pendingTools[(string) $item['id']] = [
                            'call_id' => (string) ($item['call_id'] ?? $item['id']),
                            'name' => (string) ($item['name'] ?? ''),
                            'json' => (string) ($item['arguments'] ?? ''),
                        ];
                    } elseif (($item['type'] ?? '') === 'web_search_call') {
                        // 서버 측 도구 — 우리가 실행하지 않는다. 과금 집계만 한다.
                        $searchCount++;
                    } elseif (!$thinkingAnnounced && ($item['type'] ?? '') === 'reasoning') {
                        $thinkingAnnounced = true;
                        $onThinking();
                    }
                    break;

                case 'response.function_call_arguments.delta':
                    $itemId = (string) ($data['item_id'] ?? '');

                    if (isset($pendingTools[$itemId])) {
                        $pendingTools[$itemId]['json'] .= (string) ($data['delta'] ?? '');
                    }
                    break;

                case 'response.completed':
                case 'response.incomplete':
                case 'response.failed':
                    $status = (string) ($data['response']['status'] ?? '');
                    $usage = $data['response']['usage'] ?? [];
                    $inputTokens = (int) ($usage['input_tokens'] ?? 0);
                    $outputTokens = (int) ($usage['output_tokens'] ?? 0);
                    break;
            }
        }

        $toolUses = $this->decodeToolUses($pendingTools);

        return new TurnResult(
            text: $text,
            turnText: $turnText,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            stopKind: $toolUses !== [] ? StopKind::ToolUse : StopKind::EndTurn,
            rawStopReason: $status,
            toolUses: $toolUses,
            searchCount: $searchCount,
        );
    }

    private function client(): Client
    {
        return $this->client ??= new Client([
            // base_url 설정은 보통 비어 있다(폼에도 안 보인다) — 프록시·게이트웨이 경유가
            // 필요한 설치를 위한 탈출구다.
            'base_uri' => filled($this->settings->base_url)
                ? rtrim((string) $this->settings->base_url, '/') . '/'
                : self::BASE_URL,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->settings->apiKey(),
                'Content-Type' => 'application/json',
            ],
            'timeout' => 570,
            'connect_timeout' => 10,
        ]);
    }

    /**
     * 중립 대화 → Responses 의 input 항목들.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<int, array<string, mixed>>
     */
    private function wireInput(array $messages): array
    {
        $input = [];

        foreach ($messages as $message) {
            if (isset($message['tool_results'])) {
                foreach ($message['tool_results'] as $result) {
                    $input[] = [
                        'type' => 'function_call_output',
                        'call_id' => $result['id'],
                        'output' => ($result['is_error'] ? 'ERROR: ' : '') . $result['content'],
                    ];
                }

                continue;
            }

            if (isset($message['tool_uses'])) {
                if (trim((string) ($message['text'] ?? '')) !== '') {
                    $input[] = ['role' => 'assistant', 'content' => $message['text']];
                }

                foreach ($message['tool_uses'] as $use) {
                    $input[] = [
                        'type' => 'function_call',
                        'call_id' => $use['id'],
                        'name' => $use['name'],
                        'arguments' => json_encode($use['input'] === [] ? (object) [] : $use['input'], JSON_UNESCAPED_UNICODE),
                    ];
                }

                continue;
            }

            $input[] = ['role' => $message['role'], 'content' => $message['text']];
        }

        return $input;
    }

    /**
     * 중립 도구 정의 → Responses 의 평평한 function 형식(+ 서버 측 웹 검색).
     *
     * @param  array<int, array<string, mixed>>  $tools
     * @return array<int, array<string, mixed>>
     */
    private function wireTools(array $tools): array
    {
        $wire = array_map(fn (array $tool) => [
            'type' => 'function',
            'name' => $tool['name'],
            'description' => $tool['description'] ?? '',
            // ⚠ 스키마 키는 inputSchema(camelCase) — #43 참고. snake_case 로 읽으면
            //   파라미터 없는 도구가 되고, 모델이 설명문으로 인자를 추측하게 된다.
            'parameters' => $tool['inputSchema'] ?? ['type' => 'object'],
        ], $tools);

        if ($this->settings->search_enabled) {
            // 검색 횟수 상한 파라미터가 없다 — 과금 집계(searchCount)로만 지켜본다.
            $wire[] = ['type' => 'web_search'];
        }

        return $wire;
    }

    /**
     * @param  array<string, array{call_id: string, name: string, json: string}>  $pendingTools
     * @return array<int, array{id: string, name: string, input: array<string, mixed>}>
     */
    private function decodeToolUses(array $pendingTools): array
    {
        $uses = [];

        foreach ($pendingTools as $tool) {
            $input = $tool['json'] === '' ? [] : json_decode($tool['json'], true);

            $uses[] = [
                'id' => $tool['call_id'],
                'name' => $tool['name'],
                'input' => is_array($input) ? $input : [],
            ];
        }

        return $uses;
    }
}
