<?php

namespace WisdomIT\Concierge\Tools;

use WisdomIT\Concierge\Models\ConciergePreset;

/**
 * 대화의 시작점 (#93).
 *
 * 모든 대화가 빈 상자에서 시작했다. 무엇을 물어도 되는지 모르는 채로 커서를 보고 있게 되고,
 * 그래서 첫 문장을 쓰는 데 시간이 든다. 시작점은 그 첫 문장을 거들 뿐이다 —
 * **누른 뒤에는 평범한 대화다.**
 *
 * 🔴 **범위와 화면에 맞는 것만 보여준다.** 개설할 수 없는 사람에게 게임 목록을 내미는 것은
 *    #48 이 없앤 막다른 길이고, 카탈로그를 고칠 수 없는 사람에게 "게임을 추가하자" 는
 *    같은 막다른 길이다. 여기에 하나 더: **지금 보고 있는 화면에서 할 만한 일**이어야 한다.
 *    서버 콘솔을 보는 중에 카탈로그 제안이 뜨면 그건 안내가 아니라 방해다.
 *    무엇을 보일지는 ConciergePreset 이 정한다(visibility · permission · path 셋 다 통과).
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
     * 이 사람에게, 이 화면에서 보여줄 시작점.
     *
     * @param  ?string  $path  지금 보고 있는 경로(예: `admin/concierge-games`).
     * @return array<int, array{key: string, label: string, prompt: string}>
     */
    public static function for(RequesterScope $scope, ?string $path = null): array
    {
        $presets = self::enabled()
            ->filter(fn (ConciergePreset $preset) => $preset->allowedFor($scope) && $preset->matchesPath($path))
            // 🔴 **이 화면 전용이 글로벌보다 앞선다.** 자리가 넷뿐이라 순서가 곧 노출 여부다 —
            //    카탈로그 화면까지 왔는데 "내 서버 상태 알려줘" 에 밀려 카탈로그 시작점이
            //    잘려 나가면(실측: 그렇게 잘렸다) 경로를 붙인 의미가 없다. 같은 급끼리는
            //    운영자가 정한 sort 를 지킨다(sortBy 는 안정 정렬이다).
            ->sortBy(fn (ConciergePreset $preset) => blank($preset->path_pattern) ? 1 : 0)
            ->take(self::LIMIT)
            ->map(fn (ConciergePreset $preset) => [
                'key' => $preset->preset_key,
                'label' => $preset->localizedLabel(),
                // 프롬프트는 **사용자가 친 것처럼** 보내진다 — 그래서 문장이어야 하고, 번역된다.
                'prompt' => $preset->localizedPrompt(),
            ]);

        return $presets->values()->all();
    }

    /**
     * 키 하나를 프롬프트로. 화면 바깥(카탈로그 목록의 버튼 등)에서 대화를 열 때 쓴다.
     *
     * 🔴 **여기서도 범위를 다시 본다.** 버튼은 화면이 내주지만, 그 화면에 접근할 수 있다는
     *    것과 이 대화를 시작할 수 있다는 것은 별개다 — 도구가 두 겹으로 검사하는 것과 같은
     *    이유다(#46).
     *
     * ⚠ 경로는 **묻지 않는다.** 버튼을 누른 화면과 그 요청이 도착했을 때의 경로가 같다고
     *   보장할 수 없고(사이드바는 페이지를 넘어 살아 있다), 경로는 권한이 아니라 **적절함**의
     *   조건이다. 막아야 하는 것은 권한이고, 그건 아래에서 그대로 본다.
     */
    public static function promptFor(string $key, RequesterScope $scope): ?string
    {
        $preset = ConciergePreset::query()->where('preset_key', $key)->first();

        return $preset !== null && $preset->allowedFor($scope) ? $preset->localizedPrompt() : null;
    }

    /**
     * 켜져 있는 시작점들. 정렬은 운영자가 정한 순서(sort) 그대로다.
     *
     * @return \Illuminate\Support\Collection<int, ConciergePreset>
     */
    private static function enabled(): \Illuminate\Support\Collection
    {
        return ConciergePreset::query()
            ->where('enabled', true)
            ->orderBy('sort')
            ->orderBy('id')
            ->get();
    }
}
