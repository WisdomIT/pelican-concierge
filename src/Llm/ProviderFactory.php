<?php

namespace WisdomIT\Concierge\Llm;

use WisdomIT\Concierge\Llm\Providers\AnthropicProvider;
use WisdomIT\Concierge\Models\ConciergeSettings;

/**
 * 설정에 맞는 공급자 어댑터를 꺼낸다 (#3).
 *
 * 지금은 Anthropic 뿐이다 — `settings.provider` 컬럼과 나머지 어댑터(OpenAI·Gemini·
 * OpenAI 호환)는 다음 단계에서 붙는다. 컬럼이 없을 때 Eloquent 는 null 을 주므로
 * 기존 설치는 자동으로 기본값(anthropic)에 떨어진다.
 */
final class ProviderFactory
{
    public static function for(ConciergeSettings $settings): LlmProvider
    {
        return match ($settings->provider ?? 'anthropic') {
            default => new AnthropicProvider($settings),
        };
    }
}
