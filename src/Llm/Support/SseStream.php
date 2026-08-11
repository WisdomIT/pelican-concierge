<?php

namespace WisdomIT\Concierge\Llm\Support;

use Generator;
use Psr\Http\Message\StreamInterface;

/**
 * Server-Sent Events 파서 (#3).
 *
 * OpenAI·호환 엔드포인트는 Anthropic SDK 같은 파서를 안 준다 — composer 의존을
 * 늘리는 대신 직접 판다(플러그인의 composer 의존은 다른 플러그인과의 버전 충돌
 * 표면이다). SSE 는 줄 단위 프로토콜이라 50줄이면 된다.
 */
final class SseStream
{
    /**
     * 스트림을 소비하며 이벤트를 하나씩 낸다.
     *
     * @return Generator<int, array{event: ?string, data: string}>
     */
    public static function events(StreamInterface $body): Generator
    {
        $buffer = '';
        $event = null;
        $data = [];

        while (!$body->eof()) {
            $buffer .= $body->read(8192);

            // 마지막 조각이 줄 중간에서 끊길 수 있다 — 완성된 줄만 꺼내 쓴다.
            while (($newline = strpos($buffer, "\n")) !== false) {
                $line = rtrim(substr($buffer, 0, $newline), "\r");
                $buffer = substr($buffer, $newline + 1);

                if ($line === '') {
                    // 빈 줄 = 이벤트 경계.
                    if ($data !== []) {
                        yield ['event' => $event, 'data' => implode("\n", $data)];
                    }

                    $event = null;
                    $data = [];

                    continue;
                }

                if (str_starts_with($line, 'event:')) {
                    $event = ltrim(substr($line, 6));
                } elseif (str_starts_with($line, 'data:')) {
                    $data[] = ltrim(substr($line, 5));
                }
                // 그 외(id:, retry:, 주석)는 쓰지 않는다.
            }
        }

        // 종료 빈 줄 없이 닫힌 마지막 이벤트도 버리지 않는다.
        if ($data !== []) {
            yield ['event' => $event, 'data' => implode("\n", $data)];
        }
    }
}
