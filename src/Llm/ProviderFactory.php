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
            ->map(fn (array $provider, string $id) => self::label($id))
            ->filter()
            ->all();
    }

    /**
     * 공급자 이름. 대개 고유명사("Anthropic (Claude)")라 config 값을 그대로 쓰고,
     * 설명문인 항목(로컬 엔드포인트)만 번역이 채운다(#79).
     */
    public static function label(string $provider): string
    {
        return (string) (config("concierge.providers.{$provider}.label")
            ?? trans("concierge::strings.provider_{$provider}"));
    }

    /** 대화 기록 화면의 짧은 배지. 라벨과 같은 규칙. */
    public static function badge(string $provider): string
    {
        return (string) (config("concierge.providers.{$provider}.badge")
            ?? trans("concierge::strings.provider_badge_{$provider}"));
    }

    /**
     * 모델 선택지. 이름은 고유명사라 config 가, "(권장)" 표시는 번역이 만든다(#79).
     *
     * @return array<string, string> id => 표시 문구
     */
    public static function modelOptions(string $provider): array
    {
        $recommended = config("concierge.providers.{$provider}.default_model");

        return collect((array) config("concierge.providers.{$provider}.models", []))
            ->map(fn (string $name, string $id) => $id === $recommended
                ? $name . ' ' . trans('concierge::strings.option_recommended')
                : $name)
            ->all();
    }

    /**
     * effort 선택지. 설명문이라 전부 번역이 만든다 — config 는 지원 목록과 순서만 정한다.
     *
     * @return array<string, string> id => 표시 문구
     */
    public static function effortOptions(string $provider): array
    {
        $recommended = config("concierge.providers.{$provider}.default_effort");

        return collect((array) config("concierge.providers.{$provider}.efforts", []))
            ->mapWithKeys(fn (string $id) => [$id => trans("concierge::strings.effort_{$id}")
                . ($id === $recommended ? ' ' . trans('concierge::strings.option_recommended') : '')])
            ->all();
    }
}
