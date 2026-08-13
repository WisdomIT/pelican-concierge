<?php

namespace WisdomIT\Concierge\Tools;

/**
 * 도구 1회 실행 결과. 모델에 돌려줄 텍스트와, 로그에 남길 메타데이터를 함께 담는다.
 */
final readonly class ToolCallResult
{
    /** @param array<string, mixed> $input */
    public function __construct(
        public string $name,
        public array $input,
        public string $output,
        public ?int $serverId = null,
        public bool $isError = false,
    ) {}

    /** @param array<string, mixed> $input */
    public static function error(string $name, array $input, string $message, ?int $serverId = null): self
    {
        // is_error 로 돌려주면 모델이 스스로 고쳐서 다시 시도한다.
        // 예외로 대화를 끊는 것보다 훨씬 낫다.
        return new self($name, $input, $message, $serverId, true);
    }

    /**
     * 사용자가 확인 카드에서 거부했다.
     *
     * is_error 가 **아니다** — 오류가 아니라 결정이다. 오류로 주면 모델이
     * "다시 시도해 볼게요" 하며 같은 카드를 또 띄운다.
     *
     * @param array<string, mixed> $input
     */
    public static function denied(string $name, array $input, ?int $serverId = null): self
    {
        return new self(
            $name,
            $input,
            // 모델이 읽는 글이라 영어다 — 사용자에게는 모델이 그 사람의 언어로 옮긴다(#79).
            'The user cancelled this action. Nothing was executed. Do not ask again — offer other help.',
            $serverId,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'input' => $this->input,
            'output' => $this->output,
            'server_id' => $this->serverId,
            'is_error' => $this->isError,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['name'],
            $data['input'] ?? [],
            $data['output'] ?? '',
            $data['server_id'] ?? null,
            $data['is_error'] ?? false,
        );
    }
}
