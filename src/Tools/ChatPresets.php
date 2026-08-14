<?php

namespace WisdomIT\Concierge\Tools;

use App\Models\Egg;
use WisdomIT\Concierge\Catalog\GameCatalog;
use WisdomIT\Concierge\Models\ConciergeGame;

/**
 * 대화의 시작점 (#93).
 *
 * 모든 대화가 빈 상자에서 시작했다. 그래서 사람들은 매번 "어떤 게임 만들 수 있어?" 로 열고,
 * 에이전트는 **도구를 불러 카탈로그를 읽어** 답한다 — 사용자가 아무것도 치기 전에 우리가
 * 이미 아는 것을 알아내느라 한 턴을 쓴다.
 *
 * 🔴 **범위에 맞는 것만 보여준다.** 개설할 수 없는 사람에게 게임 목록을 내미는 것은 #48 이
 *    없앤 막다른 길이고, 프리셋으로 그게 되살아나면 안 된다. 목록은 RequesterScope 가 정한다.
 *
 * ⚠ 프리셋은 **제안이지 레일이 아니다.** 여기 없는 것을 물어도 그대로 동작해야 한다 — 이건
 *   입력창을 대신하는 게 아니라 첫 문장을 거들 뿐이다.
 */
final class ChatPresets
{
    /** 한 화면에 몇 개까지. 더 늘리면 시작점이 아니라 읽어야 할 메뉴가 된다. */
    private const LIMIT = 4;

    /**
     * 이 사람에게 보여줄 시작점.
     *
     * @return array<int, array{key: string, label: string, prompt: string}>
     */
    public static function for(RequesterScope $scope): array
    {
        $presets = [];

        // 관리자 — 시작하기 어려운 일부터. 카탈로그는 #91 이 도구를 열어 둔 자리다.
        if ($scope->has(ToolGroup::Admin)) {
            $presets = array_merge($presets, self::adminPresets($scope));
        }

        if ($scope->has(ToolGroup::Create)) {
            $presets[] = self::preset('games', self::gamesPrompt());
        }

        // 서버를 돌보는 사람 모두에게. 개설 여부와 무관하다.
        $presets[] = self::preset('status');
        $presets[] = self::preset('cannot_join');

        return array_slice($presets, 0, self::LIMIT);
    }

    /**
     * 키 하나를 프롬프트로. 화면 바깥(카탈로그 목록의 버튼 등)에서 대화를 열 때 쓴다.
     *
     * 🔴 **여기서도 범위를 다시 본다.** 버튼은 화면이 내주지만, 그 화면에 접근할 수 있다는
     *    것과 이 대화를 시작할 수 있다는 것은 별개다 — 도구가 두 겹으로 검사하는 것과 같은
     *    이유다(#46).
     */
    public static function promptFor(string $key, RequesterScope $scope): ?string
    {
        foreach (self::for($scope) as $preset) {
            if ($preset['key'] === $key) {
                return $preset['prompt'];
            }
        }

        return null;
    }

    /**
     * @return array<int, array{key: string, label: string, prompt: string}>
     */
    private static function adminPresets(RequesterScope $scope): array
    {
        $presets = [];

        // 카탈로그를 고칠 수 있는 사람에게만. 조회만 되는 관리자에게 "게임을 추가하자" 는
        // 막다른 길이다.
        if ($scope->can(\App\Enums\RolePermissionPrefixes::Update, \App\Enums\RolePermissionModels::Egg)) {
            $presets[] = self::preset('catalog_new', self::catalogPrompt());
        }

        $presets[] = self::preset('health');

        return $presets;
    }

    /**
     * 개설할 수 있는 게임을 **미리** 적어 준다 — 이 목록은 DB 한 번 읽으면 되는 것이고,
     * 그걸 알아내자고 모델 턴과 도구 호출을 쓸 이유가 없다.
     */
    private static function gamesPrompt(): string
    {
        $names = collect((new GameCatalog())->selfServiceGames())
            ->pluck('name')
            ->take(12)
            ->implode(', ');

        return trans('concierge::strings.preset_prompt_games', ['games' => $names]);
    }

    /**
     * 카탈로그 항목이 없는 egg 를 함께 적는다 — 빈 상자를 **결정거리**로 바꾸는 부분이다.
     */
    private static function catalogPrompt(): string
    {
        $without = array_values(array_diff(
            Egg::query()->pluck('name')->all(),
            ConciergeGame::query()->pluck('egg')->all(),
        ));

        return $without === []
            ? trans('concierge::strings.preset_prompt_catalog_all_covered')
            : trans('concierge::strings.preset_prompt_catalog_new', ['eggs' => implode(', ', array_slice($without, 0, 8))]);
    }

    /**
     * @return array{key: string, label: string, prompt: string}
     */
    private static function preset(string $key, ?string $prompt = null): array
    {
        return [
            'key' => $key,
            'label' => trans("concierge::strings.preset_label_{$key}"),
            // 프롬프트는 **사용자가 친 것처럼** 보내진다 — 그래서 문장이어야 하고, 번역된다.
            'prompt' => $prompt ?? trans("concierge::strings.preset_prompt_{$key}"),
        ];
    }
}
