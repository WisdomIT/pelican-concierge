<?php

namespace WisdomIT\Concierge\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use WisdomIT\Concierge\Tools\RequesterScope;
use WisdomIT\Concierge\Tools\ToolGroup;

/**
 * 대화의 시작점 한 개 (#93).
 *
 * 보일 조건이 셋이고 **셋 다 통과해야** 보인다 — visibility · permission · path.
 * 하나라도 어긋나면 그 사람에게는 없는 것이다: 시작점은 제안이므로, 누를 수 없는 것을
 * 보여주는 순간 안내가 아니라 방해가 된다.
 *
 * @property string $preset_key
 * @property bool $enabled
 * @property string $label
 * @property ?array<string, string> $label_translations
 * @property string $prompt
 * @property ?array<string, string> $prompt_translations
 * @property string $visibility
 * @property ?string $permission
 * @property ?string $path_pattern
 */
class ConciergePreset extends Model
{
    protected $table = 'concierge_presets';

    protected $fillable = [
        'preset_key', 'sort', 'enabled', 'label', 'label_translations',
        'prompt', 'prompt_translations', 'visibility', 'permission', 'path_pattern',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'label_translations' => 'array',
            'prompt_translations' => 'array',
        ];
    }

    public function localizedLabel(): string
    {
        return $this->pick($this->label_translations, $this->label);
    }

    public function localizedPrompt(): string
    {
        return $this->pick($this->prompt_translations, $this->prompt);
    }

    /**
     * 이 사람이 이 시작점을 쓸 수 있는가 — **권한** 조건.
     *
     * 🔴 경로와 나누어 둔다. 경로는 *적절함*(지금 화면에서 할 만한 일인가)이고 이쪽은
     *    *허용*(눌러도 되는가)이다. 화면 바깥에서 대화를 열 때는 경로를 물을 수 없지만
     *    권한은 반드시 다시 봐야 한다 — 두 조건을 한 함수에 묶어 두면 그때 함께 느슨해진다.
     */
    public function allowedFor(RequesterScope $scope): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $allowed = match ($this->visibility) {
            'create' => $scope->has(ToolGroup::Create),
            'admin' => $scope->has(ToolGroup::Admin),
            default => true,
        };

        if (!$allowed) {
            return false;
        }

        // 🔴 권한은 **문자열 그대로** 묻는다. 패널 리소스 권한(`update egg`)이든 이 플러그인이
        //    등록한 것(`viewList wisdomAgent`)이든 같은 방식이다 — 화면과 같은 권한을 쓰라는
        //    #97 의 규칙을 여기서도 지킬 수 있게.
        return !filled($this->permission) || $scope->canPermission((string) $this->permission);
    }

    /**
     * 이 화면에서 보일 것인가 — 경로 조건이 없으면 어디서나(글로벌), 있으면 glob 으로 맞춘다.
     *
     * @param  ?string  $path  지금 보고 있는 경로. 모르면(null) 경로 조건이 붙은 것은 숨긴다 —
     *                         모르면서 보여주는 것보다 안 보여주는 편이 낫다.
     */
    public function matchesPath(?string $path): bool
    {
        if (blank($this->path_pattern)) {
            return true;
        }

        return $path !== null && Str::is((string) $this->path_pattern, ltrim($path, '/'));
    }

    /** @param ?array<string, string> $translations */
    private function pick(?array $translations, string $fallback): string
    {
        $value = trim((string) ($translations[app()->getLocale()] ?? ''));

        return $value !== '' ? $value : $fallback;
    }
}
