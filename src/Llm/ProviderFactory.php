<?php

namespace WisdomIT\Concierge\Llm;

use Illuminate\Support\Facades\Cache;
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
     * 모델 선택지 (#80). **공급자가 실제로 주는 목록**을 쓴다 — 그래야 플러그인보다 새로
     * 나온 모델도 고를 수 있고, 공급자가 내린 모델이 목록에 남지 않는다.
     * (실제로 겪었다: gemini-3-pro-preview 가 회수되자 모든 대화가 404 로 죽었다.)
     *
     * 조회가 안 되면(키 없음·엔드포인트 불통·요청 실패) 배포본 목록으로 물러난다 —
     * 설정 화면이 비어 있는 상태로 끝나는 일은 없어야 한다.
     *
     * 이름은 공급자가 주는 표시 이름(없으면 id)을, "(권장)" 표시는 번역이 만든다(#79).
     *
     * @return array<string, string> id => 표시 문구
     */
    public static function modelOptions(string $provider, ?string $apiKey = null, ?string $baseUrl = null): array
    {
        $shipped = (array) config("concierge.providers.{$provider}.models", []);
        $fetched = self::fetchModels($provider, $apiKey, $baseUrl);
        $recommended = (string) config("concierge.providers.{$provider}.default_model");

        // 조회 성공이면 공급자 목록이 사실이다. 우리 권장 모델이 거기 없다면 정말로 사라진
        // 것이므로 되살리지 않는다 — 없는 id 를 고르게 두면 404 가 대화에서 터진다.
        $models = $fetched !== [] ? $fetched : $shipped;

        return collect($models)
            ->map(fn (string $name, string $id) => $id === $recommended
                ? $name . ' ' . trans('concierge::strings.option_recommended')
                : $name)
            ->all();
    }

    /**
     * 저장 시 검증에 쓸 id 목록. 화면이 보여준 것과 같은 근거여야 한다 —
     * 배포본 목록으로만 검사하면 **방금 고른 새 모델이 저장 때 되돌려진다**.
     *
     * @return array<int, string>
     */
    public static function modelIds(string $provider, ?string $apiKey = null, ?string $baseUrl = null): array
    {
        return array_keys(self::modelOptions($provider, $apiKey, $baseUrl));
    }

    /**
     * 공급자 조회 결과. 설정 폼은 상호작용마다 다시 그려지므로 **매번 부르면 안 된다** —
     * 짧게 캐시한다. 키·주소가 바뀌면 캐시 키가 달라져 자동으로 새로 받는다.
     *
     * @return array<string, string>
     */
    private static function fetchModels(string $provider, ?string $apiKey, ?string $baseUrl): array
    {
        $key = $apiKey ?? ConciergeSettings::current()->apiKeyValueFor($provider);

        // ⚠ base_url 은 **로컬 엔드포인트 전용** 칸이다. 그대로 넘기면 호스팅 공급자에게도
        //   그 주소를 쓴다 — 실측에서 Anthropic 만 목록이 오고 OpenAI·Gemini 는 조용히
        //   빈 목록이 됐다(로컬 주소로 /models 를 찌르고 있었다).
        $base = $provider === 'openai-compatible'
            ? ($baseUrl ?? ConciergeSettings::current()->base_url)
            : null;

        if (blank($key) && $provider !== 'openai-compatible') {
            return [];
        }

        return Cache::remember(
            self::modelsCacheKey($provider, $key, $base),
            now()->addMinutes(10),
            fn () => ProviderProbe::models($provider, $key, $base),
        );
    }

    /** 캐시된 목록을 버린다 — "연결 확인" 직후처럼 지금 상태로 다시 봐야 할 때. */
    public static function forgetModels(string $provider, ?string $apiKey = null, ?string $baseUrl = null): void
    {
        $key = $apiKey ?: ConciergeSettings::current()->apiKeyValueFor($provider);
        // fetchModels 와 **같은 규칙**으로 키를 만들어야 실제로 지워진다.
        $base = $provider === 'openai-compatible'
            ? ($baseUrl ?: ConciergeSettings::current()->base_url)
            : null;

        Cache::forget(self::modelsCacheKey($provider, $key, $base));
    }

    private static function modelsCacheKey(string $provider, ?string $apiKey, ?string $baseUrl): string
    {
        return 'concierge:models:' . $provider . ':' . md5((string) $apiKey . '|' . (string) $baseUrl);
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
