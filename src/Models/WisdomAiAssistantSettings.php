<?php

namespace WisdomIT\WisdomAiAssistant\Models;

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
 * @property int $daily_message_limit
 * @property bool $enabled
 * @property bool $idle_enabled
 * @property bool $search_enabled
 * @property int $search_max_uses
 * @property int $idle_minutes
 * @property bool $idle_stop_enabled
 * @property int $idle_grace_minutes
 */
class WisdomAiAssistantSettings extends Model
{
    protected $table = 'wisdom_ai_assistant_settings';

    protected $fillable = [
        'api_key',
        'model',
        'effort',
        'max_tokens',
        'daily_message_limit',
        'enabled',
        'idle_enabled',
        'search_enabled',
        'search_max_uses',
        'idle_minutes',
        'idle_stop_enabled',
        'idle_grace_minutes',
    ];

    private static ?self $cached = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'max_tokens' => 'integer',
            'daily_message_limit' => 'integer',
            'enabled' => 'boolean',
            'idle_enabled' => 'boolean',
            'search_enabled' => 'boolean',
            'search_max_uses' => 'integer',
            'idle_minutes' => 'integer',
            'idle_stop_enabled' => 'boolean',
            'idle_grace_minutes' => 'integer',
        ];
    }

    /**
     * 설정 행은 항상 하나다. 없으면 config 의 기본값으로 만든다.
     * 요청당 여러 번 불리므로(채팅 페이지 + 헤더 액션) 메모이즈한다.
     */
    public static function current(): self
    {
        return self::$cached ??= static::query()->first() ?? static::query()->create([
            'model' => config('wisdom-ai-assistant.model'),
            'effort' => config('wisdom-ai-assistant.effort'),
            'max_tokens' => config('wisdom-ai-assistant.max_tokens'),
            'daily_message_limit' => config('wisdom-ai-assistant.daily_message_limit'),
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
        return filled($this->apiKey());
    }

    public function isUsable(): bool
    {
        return $this->enabled && $this->isConfigured();
    }
}
