<?php

namespace WisdomIT\Concierge\Tools;

/**
 * 대화의 시작점 (#93).
 *
 * 모든 대화가 빈 상자에서 시작했다. 무엇을 물어도 되는지 모르는 채로 커서를 보고 있게 되고,
 * 그래서 첫 문장을 쓰는 데 시간이 든다. 시작점은 그 첫 문장을 거들 뿐이다 —
 * **누른 뒤에는 평범한 대화다.**
 *
 * 🔴 **범위에 맞는 것만 보여준다.** 개설할 수 없는 사람에게 게임 목록을 내미는 것은 #48 이
 *    없앤 막다른 길이고, 프리셋으로 그게 되살아나면 안 된다. 목록은 RequesterScope 가 정한다.
 *
 * ⚠ 프리셋은 **제안이지 레일이 아니다.** 여기 없는 것을 물어도 그대로 동작해야 한다 — 이건
 *   입력창을 대신하는 게 아니라 첫 문장을 거들 뿐이다.
 *
 * 🔴 **답을 미리 넣지 않는다.** 한때 개설 가능한 게임 목록이나 항목 없는 egg 를 프롬프트에
 *    박아 넣어 도구 호출 한 번을 아꼈다. 토큰은 아꼈지만 대화가 이상해진다 — 사용자가
 *    "어떤 게임 만들 수 있어?" 라고 물었는데 그 질문 안에 이미 답이 적혀 있는 꼴이고,
 *    에이전트는 자기가 확인하지도 않은 사실을 되읽게 된다. **사용자는 묻고 에이전트가
 *    도구로 확인해 답한다** — 이 형태가 몇 백 토큰보다 중요하다.
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
            $presets[] = self::preset('games');
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
            $presets[] = self::preset('catalog_new');
        }

        $presets[] = self::preset('health');

        return $presets;
    }

    /**
     * @return array{key: string, label: string, prompt: string}
     */
    private static function preset(string $key): array
    {
        return [
            'key' => $key,
            'label' => trans("concierge::strings.preset_label_{$key}"),
            // 프롬프트는 **사용자가 친 것처럼** 보내진다 — 그래서 문장이어야 하고, 번역된다.
            'prompt' => trans("concierge::strings.preset_prompt_{$key}"),
        ];
    }
}
