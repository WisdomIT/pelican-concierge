<?php

namespace WisdomIT\Concierge\Models;

use App\Models\Egg;
use Illuminate\Database\Eloquent\Model;

/**
 * 카탈로그의 게임 한 종 (#81).
 *
 * 종전에는 플러그인 안의 `games.yaml` 이었다 — 운영자 데이터인데 화면에서 못 고치고
 * 플러그인 업데이트가 지웠다. 이제 DB 에 있고 관리 화면에서 고친다.
 *
 * ⚠ 소비자(개설·유휴 판정·마스킹·모드 설치)는 전부 GameCatalog 를 거치고, 그들이 아는
 *   형태는 **YAML 시절의 배열**이다. 그래서 이 모델의 일은 행을 그 배열로 되돌리는 것이다
 *   (toCatalogArray) — 저장 형태가 바뀌었다고 소비자를 전부 고칠 이유는 없다.
 *
 * @property string $game_id
 * @property string $name
 * @property ?array<string, string> $name_translations
 * @property ?string $summary
 * @property ?array<string, string> $summary_translations
 * @property string $egg
 * @property bool $available
 * @property ?string $unavailable_reason
 * @property ?array<int, array<string, mixed>> $sizes
 * @property ?array<int, array<string, mixed>> $ask
 * @property ?array<string, mixed> $advanced
 */
class ConciergeGame extends Model
{
    protected $table = 'concierge_games';

    protected $fillable = [
        'game_id', 'sort', 'name', 'name_translations', 'summary', 'summary_translations',
        'egg', 'available', 'unavailable_reason', 'sizes', 'ask', 'advanced',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'name_translations' => 'array',
            'summary_translations' => 'array',
            'sizes' => 'array',
            'ask' => 'array',
            'advanced' => 'array',
            'available' => 'boolean',
        ];
    }

    /**
     * 지금 로케일의 이름. 번역이 없으면 기본 이름을 쓴다 — 단일 언어 패널 운영자에게
     * 번역 작성을 강요하지 않기 위한 규칙이다(#81).
     */
    public function localizedName(): string
    {
        return $this->pick($this->name_translations, $this->name);
    }

    public function localizedSummary(): ?string
    {
        $summary = $this->pick($this->summary_translations, (string) $this->summary);

        return $summary === '' ? null : $summary;
    }

    /**
     * 소비자가 아는 형태(YAML 시절 배열)로 되돌린다. 기술 항목(advanced)은 최상위로
     * 펴 넣는다 — 원래 그 자리에 있었고, 소비자는 그 위치를 안다.
     *
     * @return array<string, mixed>
     */
    public function toCatalogArray(): array
    {
        return array_merge((array) $this->advanced, array_filter([
            'id' => $this->game_id,
            'name' => $this->localizedName(),
            'summary' => $this->localizedSummary(),
            'egg' => $this->egg,
            'available' => $this->available,
            'unavailable_reason' => $this->unavailable_reason,
            'sizes' => $this->sizes ?? [],
            'ask' => $this->ask ?? [],
        ], fn ($value) => $value !== null));
    }

    /** 이 게임이 가리키는 egg 가 이 패널에 실제로 있는가 — 화면에서 먼저 알려주기 위한 것. */
    public function eggExists(): bool
    {
        return Egg::query()->where('name', $this->egg)->exists();
    }

    /**
     * @param  ?array<string, string>  $translations
     */
    private function pick(?array $translations, string $fallback): string
    {
        $locale = app()->getLocale();
        $value = trim((string) ($translations[$locale] ?? ''));

        return $value !== '' ? $value : $fallback;
    }
}
