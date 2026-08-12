<?php

namespace WisdomIT\Concierge\Llm;

use WisdomIT\Concierge\Llm\Providers\AnthropicProvider;
use WisdomIT\Concierge\Llm\Providers\GeminiProvider;
use WisdomIT\Concierge\Llm\Providers\OpenAiCompatibleProvider;
use WisdomIT\Concierge\Llm\Providers\OpenAiProvider;
use WisdomIT\Concierge\Models\ConciergeSettings;

/**
 * 설정에 맞는 공급자 어댑터를 꺼낸다 (#3).
 *
 * 어댑터 클래스명을 아는 곳은 여기뿐이다. 모델·effort 선택지는 config
 * (`concierge.providers.<id>`)에 있다 — 새 모델이 나오면 코드 수정 없이 거기만 고친다.
 */
final class ProviderFactory
{
    public static function for(ConciergeSettings $settings): LlmProvider
    {
        return match ($settings->provider ?? 'anthropic') {
            'openai' => new OpenAiProvider($settings),
            'openai-compatible' => new OpenAiCompatibleProvider($settings),
            'gemini' => new GeminiProvider($settings),
            // 모르는 값(다운그레이드 등)은 기본 공급자로 — 죽는 것보다 낫다.
            default => new AnthropicProvider($settings),
        };
    }

    /**
     * 설정 화면용 — 어댑터를 만들지 않고 공급자의 능력을 본다.
     * (어댑터 생성에는 설정 인스턴스가 필요한데, 폼은 임의의 후보를 두고 판단한다.)
     *
     * ⚠ 각 어댑터의 capabilities() 와 **같은 값을 유지할 것** — 어댑터를 추가·수정하면
     *   여기도 같이 고친다. 값이 어긋나면 폼이 없는 기능을 보여주거나 있는 기능을 숨긴다.
     */
    public static function capabilitiesOf(string $id): Capabilities
    {
        return match ($id) {
            'openai' => new Capabilities(true, true, true, false),
            'openai-compatible' => new Capabilities(true, false, false, true),
            'gemini' => new Capabilities(true, true, false, false),
            default => new Capabilities(true, true, true, false),
        };
    }

    /**
     * 설정 화면의 공급자 선택지 — config 에 정의된 것만.
     *
     * @return array<string, string> id => label
     */
    public static function options(): array
    {
        return collect((array) config('concierge.providers', []))
            ->map(fn (array $provider) => (string) ($provider['label'] ?? ''))
            ->filter()
            ->all();
    }
}
