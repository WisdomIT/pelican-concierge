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
 * Google (Gemini) 어댑터 (#3) — `streamGenerateContent?alt=sse`.
 *
 * Gemini 특유의 사정 둘:
 *  · functionCall 에 id 가 없다 — 결과 짝은 **함수 이름**으로 맞춘다. 중립 형식의
 *    id 는 우리가 만들어 붙이고, 결과를 되보낼 때 id → 이름으로 되찾는다.
 *  · Gemini 3 는 함수 호출에 thoughtSignature 를 실어 보내고, 다음 요청에서
 *    그대로 돌려받기를 기대한다 — tool_use 항목에 얹어 저장했다가 되돌려 준다.
 *    (다른 어댑터는 이 여분 키를 모르고, 무시한다.)
 *
 * 웹 검색(google_search)은 끈다: generateContent 는 서버 측 검색 도구와
 * functionDeclarations 의 **동시 사용을 거부**하는데, 이 에이전트는 항상 패널
 * 도구를 싣고 다닌다. effort 도 끈다 — thinking 어휘가 모델 세대마다 달라서
 * (3: thinkingLevel, 2.5: thinkingBudget) 기본 동작(dynamic)에 맡기는 쪽이 안전하다.
 */
final class GeminiProvider implements LlmProvider
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/';

    private ?Client $client = null;

    public function __construct(private readonly ConciergeSettings $settings) {}

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            supportsTools: true,
            supportsWebSearch: false,
            supportsEffort: false,
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
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents' => $this->wireContents($messages),
            'generationConfig' => ['maxOutputTokens' => $this->settings->max_tokens],
        ];

        if ($tools !== []) {
            $payload['tools'] = [[
                'functionDeclarations' => array_map(fn (array $tool) => [
                    'name' => $tool['name'],
                    'description' => $tool['description'] ?? '',
                    'parameters' => $tool['input_schema'] ?? ['type' => 'object'],
                ], $tools),
            ]];
        }

        $response = $this->client()->post(
            'models/' . rawurlencode((string) $this->settings->model) . ':streamGenerateContent?alt=sse',
            ['json' => $payload, 'stream' => true],
        );

        $text = $accumulatedText;
        $turnText = '';
        $inputTokens = 0;
        $outputTokens = 0;
        $finishReason = null;
        $thinkingAnnounced = false;

        /** @var array<int, array{name: string, input: array<string, mixed>, signature: string}> $pendingTools */
        $pendingTools = [];

        foreach (SseStream::events($response->getBody()) as $event) {
            $chunk = json_decode($event['data'], true);

            if (!is_array($chunk)) {
                continue;
            }

            // usage 는 청크마다 누적값이 실려 온다 — 마지막 값이 곧 총합이다.
            if (isset($chunk['usageMetadata'])) {
                $usage = $chunk['usageMetadata'];
                $inputTokens = (int) ($usage['promptTokenCount'] ?? $inputTokens);
                // 사고 토큰은 candidates 에 안 들어간다 — 과금 집계에는 합쳐야 맞다.
                $outputTokens = (int) ($usage['candidatesTokenCount'] ?? 0)
                    + (int) ($usage['thoughtsTokenCount'] ?? 0);
            }

            $candidate = $chunk['candidates'][0] ?? null;

            if ($candidate === null) {
                continue;
            }

            foreach ($candidate['content']['parts'] ?? [] as $part) {
                // 사고 요약 파트 — 내용은 안 쓰고 "생각 중"만 알린다.
                if ($part['thought'] ?? false) {
                    if (!$thinkingAnnounced) {
                        $thinkingAnnounced = true;
                        $onThinking();
                    }

                    continue;
                }

                if (isset($part['functionCall'])) {
                    $pendingTools[] = [
                        'name' => (string) ($part['functionCall']['name'] ?? ''),
                        'input' => is_array($part['functionCall']['args'] ?? null) ? $part['functionCall']['args'] : [],
                        'signature' => (string) ($part['thoughtSignature'] ?? ''),
                    ];

                    continue;
                }

                if (($part['text'] ?? '') !== '') {
                    // 도구 왕복 사이의 발화가 이어 붙으므로 한 줄 띄워 구분한다.
                    if ($turnText === '' && $text !== '') {
                        $text .= "\n\n";
                    }

                    $turnText .= (string) $part['text'];
                    $text .= (string) $part['text'];
                    $onText($text);
                }
            }

            if (($candidate['finishReason'] ?? '') !== '') {
                $finishReason = (string) $candidate['finishReason'];
            }
        }

        $toolUses = $this->decodeToolUses($pendingTools);

        return new TurnResult(
            text: $text,
            turnText: $turnText,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            // finishReason 은 함수 호출이어도 STOP 이다 — 도구 유무가 진실이다.
            stopKind: $toolUses !== [] ? StopKind::ToolUse : StopKind::EndTurn,
            rawStopReason: $finishReason,
            toolUses: $toolUses,
            searchCount: 0,
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
                'x-goog-api-key' => (string) $this->settings->apiKey(),
                'Content-Type' => 'application/json',
            ],
            // 도구 왕복 포함 한 턴이 길 수 있다 — FPM 의 set_time_limit(600)과 보조를 맞춘다.
            'timeout' => 570,
            'connect_timeout' => 10,
        ]);
    }

    /**
     * 중립 대화 → Gemini contents.
     *
     * 결과(functionResponse)는 이름으로 짝을 맞춰야 해서, 지나온 tool_uses 의
     * id → 이름·서명을 기억해 두었다가 결과를 만나면 되찾는다.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<int, array<string, mixed>>
     */
    private function wireContents(array $messages): array
    {
        $contents = [];
        /** @var array<string, string> $names id → 함수 이름 */
        $names = [];

        foreach ($messages as $message) {
            if (isset($message['tool_results'])) {
                $contents[] = [
                    'role' => 'user',
                    'parts' => array_map(fn (array $result) => [
                        'functionResponse' => [
                            'name' => $names[$result['id']] ?? 'unknown',
                            'response' => $result['is_error']
                                ? ['error' => $result['content']]
                                : ['result' => $result['content']],
                        ],
                    ], $message['tool_results']),
                ];

                continue;
            }

            if (isset($message['tool_uses'])) {
                $parts = [];

                if (trim((string) ($message['text'] ?? '')) !== '') {
                    $parts[] = ['text' => $message['text']];
                }

                foreach ($message['tool_uses'] as $use) {
                    $names[$use['id']] = $use['name'];

                    $part = [
                        'functionCall' => [
                            'name' => $use['name'],
                            'args' => $use['input'] === [] ? (object) [] : $use['input'],
                        ],
                    ];

                    // Gemini 3 의 사고 서명 — 받은 그대로 돌려줘야 한다.
                    if (($use['thought_signature'] ?? '') !== '') {
                        $part['thoughtSignature'] = $use['thought_signature'];
                    }

                    $parts[] = $part;
                }

                $contents[] = ['role' => 'model', 'parts' => $parts];

                continue;
            }

            $contents[] = [
                'role' => $message['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $message['text']]],
            ];
        }

        return $contents;
    }

    /**
     * @param  array<int, array{name: string, input: array<string, mixed>, signature: string}>  $pendingTools
     * @return array<int, array<string, mixed>>
     */
    private function decodeToolUses(array $pendingTools): array
    {
        $uses = [];

        foreach ($pendingTools as $index => $tool) {
            $use = [
                // Gemini 는 호출 id 가 없다 — 결과 짝을 맞출 수 있게 만들어 준다.
                'id' => 'call_' . $index . '_' . substr(md5($tool['name'] . json_encode($tool['input'])), 0, 8),
                'name' => $tool['name'],
                'input' => $tool['input'],
            ];

            if ($tool['signature'] !== '') {
                $use['thought_signature'] = $tool['signature'];
            }

            $uses[] = $use;
        }

        return $uses;
    }
}
