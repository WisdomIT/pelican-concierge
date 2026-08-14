<?php

namespace WisdomIT\Concierge\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;

/**
 * 플러그인 설정 한 줄. 관리자 화면에서만 쓴다.
 *
 *  왜 .env 가 아니라 DB 인가:
 *   Pelican 의 설정 저장(EnvironmentWriterTrait)은 /pelican-data/.env 에 쓰는데,
 *   compose.yaml 의 environment 로 같은 키가 주입돼 있으면 Dotenv 가 기존 환경변수를
 *   덮어쓰지 않아 **UI 에서 바꾼 값이 조용히 무시된다.** DB 에 두면 그 함정이 없고,
 *   /srv/data/pelican 의 SQLite 는 restic 백업 대상이라 값도 보존된다.
 *
 * @property int $id
 * @property ?string $api_key
 * @property string $model
 * @property string $effort
 * @property int $max_tokens
 * @property ?array<int, array<string, mixed>> $usage_limits
 * @property bool $idle_enabled
 * @property bool $search_enabled
 * @property int $search_max_uses
 * @property int $idle_minutes
 * @property bool $idle_stop_enabled
 * @property int $idle_grace_minutes
 * @property bool $allow_conversation_delete
 * @property ?string $sidebar_color
 * @property ?string $deployment_knowledge
 * @property string $provider
 * @property ?string $base_url
 * @property ?array<string, array<string, mixed>> $provider_settings
 */
class ConciergeSettings extends Model
{
    protected $table = 'concierge_settings';

    protected $fillable = [
        'api_key',
        'model',
        'effort',
        'max_tokens',
        'usage_limits',
        'idle_enabled',
        'search_enabled',
        'search_max_uses',
        'idle_minutes',
        'idle_stop_enabled',
        'idle_grace_minutes',
        'allow_conversation_delete',
        'sidebar_color',
        'deployment_knowledge',
        'provider',
        'base_url',
        'provider_settings',
        'provider_entries',
    ];

    private static ?self $cached = null;

    /**
     * 이 사본이 어느 항목의 것인가 (#89) — forEntry() 가 채운다.
     *
     * ⚠ **컬럼이 아니라 평범한 프로퍼티다.** Eloquent 는 선언된 프로퍼티를 속성으로 보지
     *   않으므로 저장에 끼어들지 않는다. 사용 기록이 "무엇으로 청구됐는가"를 이 값으로 적는다.
     */
    public ?string $entryLabel = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'max_tokens' => 'integer',
            // 3축 한도 규칙 목록(#4) — 형태 검증은 UsageLimiter::rules 가 한다.
            'usage_limits' => 'array',
            'idle_enabled' => 'boolean',
            'search_enabled' => 'boolean',
            'search_max_uses' => 'integer',
            'idle_minutes' => 'integer',
            'idle_stop_enabled' => 'boolean',
            'idle_grace_minutes' => 'integer',
            'allow_conversation_delete' => 'boolean',
            // 공급자별 키 스냅샷이 들어간다 — 통째로 암호화한다(#3).
            'provider_settings' => 'encrypted:array',
            // 순서 있는 공급자 목록(#89). 키가 들어가므로 같은 규칙으로 암호화한다.
            'provider_entries' => 'encrypted:array',
        ];
    }

    /**
     * 설정된 공급자들 — **순서가 곧 우선순위다** (#89). 첫 항목이 주 공급자고 나머지가
     * 차례로 대비책이다.
     *
     * 목록이 비어 있으면(마이그레이션 직후 저장 전, 혹은 운영자가 전부 지운 경우) 활성
     * 칸으로 항목 하나를 만들어 돌려준다 — 목록이 사실이 되기 전에도 대화는 되어야 한다.
     *
     * @return array<int, array<string, mixed>>
     */
    public function entries(): array
    {
        try {
            $entries = $this->provider_entries ?? [];
        } catch (DecryptException) {
            // APP_KEY 가 바뀌었다 — apiKey() 와 같은 태도로 "없음" 취급하고 활성 칸으로 간다.
            $entries = [];
        }

        $entries = array_values(array_filter(
            is_array($entries) ? $entries : [],
            fn ($entry) => is_array($entry) && filled($entry['provider'] ?? null),
        ));

        return $entries !== [] ? $entries : [$this->activeAsEntry()];
    }

    /** 활성 칸을 항목 하나로 — 목록이 없을 때의 뒷받침이자, 마이그레이션이 옮기는 값과 같은 모양. */
    public function activeAsEntry(): array
    {
        $provider = $this->provider ?? 'anthropic';

        return [
            'id' => 'active',
            'label' => (string) (config("concierge.providers.{$provider}.label")
                ?? trans("concierge::strings.provider_{$provider}")),
            'provider' => $provider,
            'api_key' => $this->apiKey(),
            'base_url' => $this->base_url,
            'model' => (string) $this->model,
            'effort' => $this->effort,
            'max_tokens' => (int) $this->max_tokens,
        ];
    }

    /**
     * 이 항목으로 말하는 설정 — 활성 칸만 갈아 끼운 **저장하지 않는 사본**이다 (#89).
     *
     * 🔴 어댑터들은 ConciergeSettings 를 받아 model·effort·max_tokens·base_url·apiKey() 를
     *    읽는다. 항목마다 어댑터를 고치는 대신 설정 쪽을 갈아 끼우면 어댑터는 손대지 않아도
     *    된다 — 그리고 웹 검색처럼 **패널 전체에 걸린 값**은 그대로 따라온다.
     *
     * ⚠ 절대 save() 하지 말 것. 장애 조치는 운영자의 설정을 바꾸는 일이 아니다 —
     *   지금 이 대화가 어디로 나가는지를 정할 뿐이다.
     *
     * @param  array<string, mixed>  $entry
     */
    public function forEntry(array $entry): self
    {
        $clone = clone $this;

        $clone->provider = (string) $entry['provider'];
        $clone->api_key = $entry['api_key'] ?? null;
        $clone->base_url = $entry['base_url'] ?? null;
        $clone->model = (string) ($entry['model'] ?? '');
        $clone->effort = (string) ($entry['effort'] ?? '');
        $clone->max_tokens = (int) ($entry['max_tokens'] ?? $this->max_tokens);
        $clone->entryLabel = \WisdomIT\Concierge\Llm\ProviderChain::labelOf($entry);

        return $clone;
    }

    /**
     * 설정 행은 항상 하나다. 없으면 config 의 기본값으로 만든다.
     * 요청당 여러 번 불리므로(채팅 페이지 + 헤더 액션) 메모이즈한다.
     */
    public static function current(): self
    {
        return self::$cached ??= static::query()->first() ?? static::query()->create([
            'model' => config('concierge.model'),
            'effort' => config('concierge.effort'),
            'max_tokens' => config('concierge.max_tokens'),
            'usage_limits' => config('concierge.usage_limits'),
        ]);
    }

    public static function forgetCached(): void
    {
        self::$cached = null;
    }

    /**
     * APP_KEY 가 바뀌면 복호화가 예외를 던진다. 그 경우 "미설정"으로 취급해
     * 채팅 화면이 500 대신 안내 문구를 띄우게 한다 — 관리자가 키를 다시 넣으면 복구된다.
     */
    public function apiKey(): ?string
    {
        try {
            return $this->api_key;
        } catch (DecryptException) {
            return null;
        }
    }

    public function isConfigured(): bool
    {
        // 로컬 OpenAI 호환 엔드포인트는 키가 없는 게 보통이다 — 주소와 모델이 있으면 된다(#3).
        if (($this->provider ?? 'anthropic') === 'openai-compatible') {
            return filled($this->base_url) && filled($this->model);
        }

        return filled($this->apiKey());
    }

    /**
     * 켜기/끄기는 플러그인 자체의 활성화가 담당한다(#2) — 여기서는 키 유무만 본다.
     * 플러그인이 꺼져 있으면 이 코드 자체가 부팅되지 않는다.
     */
    public function isUsable(): bool
    {
        return $this->isConfigured();
    }

    /**
     * 그 공급자의 키가 저장돼 있는가 (#3) — 활성 공급자는 활성 컬럼을, 나머지는
     * 스냅샷을 본다. 설정 화면의 "키 저장됨" 표시가 이걸 쓴다: 활성 키 하나만 보면
     * Claude 키가 있을 때 OpenAI·로컬에도 "저장됨"이 떠서 오해를 부른다.
     */
    public function hasApiKeyFor(string $provider): bool
    {
        if ($provider === ($this->provider ?? 'anthropic')) {
            return filled($this->apiKey());
        }

        try {
            return filled(($this->provider_settings ?? [])[$provider]['api_key'] ?? null);
        } catch (DecryptException) {
            // APP_KEY 가 바뀐 경우 — apiKey() 와 같은 태도로 "없음" 취급한다.
            return false;
        }
    }

    /** 그 공급자의 저장된 키 값 — 키 검증 버튼이 폼에 새 키가 없을 때 쓴다(#3). 서버 밖으로 내보내지 말 것. */
    public function apiKeyValueFor(string $provider): ?string
    {
        if ($provider === ($this->provider ?? 'anthropic')) {
            return $this->apiKey();
        }

        try {
            return ($this->provider_settings ?? [])[$provider]['api_key'] ?? null;
        } catch (DecryptException) {
            return null;
        }
    }

    /**
     * 활성 값(키·주소·모델·effort)을 **현재 공급자**의 스냅샷으로 저장한다 (#3).
     * 전환 직전마다 불린다 — 그래서 공급자를 오가도 각자의 키·모델 선택이 남는다.
     */
    public function stashProviderSnapshot(): void
    {
        $snapshots = $this->provider_settings ?? [];

        $snapshots[$this->provider ?? 'anthropic'] = [
            'api_key' => $this->apiKey(),
            'base_url' => $this->base_url,
            'model' => $this->model,
            'effort' => $this->effort,
        ];

        $this->provider_settings = $snapshots;
    }

    /**
     * 공급자를 바꾸고, 그 공급자의 스냅샷(없으면 config 기본값)을 활성 값으로 적재한다.
     * 저장은 호출자 몫이다 — 폼의 다른 필드와 함께 한 번에 save 된다.
     */
    public function switchProvider(string $id): void
    {
        $this->stashProviderSnapshot();

        $snapshot = ($this->provider_settings ?? [])[$id] ?? [];
        $defaults = (array) config("concierge.providers.{$id}", []);

        $this->provider = $id;
        $this->api_key = $snapshot['api_key'] ?? null;
        $this->base_url = $snapshot['base_url'] ?? null;
        $this->model = (string) ($snapshot['model'] ?? $defaults['default_model'] ?? '');
        $this->effort = (string) ($snapshot['effort'] ?? $defaults['default_effort'] ?? 'medium');
    }
}
