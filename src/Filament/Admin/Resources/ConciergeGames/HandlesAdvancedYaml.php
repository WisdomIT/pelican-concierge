<?php

namespace WisdomIT\Concierge\Filament\Admin\Resources\ConciergeGames;

use Symfony\Component\Yaml\Yaml;

/**
 * 고급 항목 칸(YAML)을 저장 형태로 되돌린다 (#81).
 *
 * 폼은 사람이 정하는 것만 칸으로 펴고, 기술 항목(post_install·ports·secrets·mods·
 * defaults 등)은 YAML 한 칸으로 받는다 — 종류마다 필요한 칸이 달라 반복 필드로 펴면
 * 오히려 어렵다.
 *
 * ⚠ 폼이 아는 칸과 겹치는 키는 **버린다.** YAML 쪽에 `name:` 이 남아 있으면 저장할 때마다
 *   폼의 이름과 다투게 되고, 어느 쪽이 이겼는지는 화면에 드러나지 않는다.
 */
trait HandlesAdvancedYaml
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function foldAdvancedYaml(array $data): array
    {
        $yaml = (string) ($data['advanced_yaml'] ?? '');
        unset($data['advanced_yaml']);

        if (blank($yaml)) {
            $data['advanced'] = [];

            return $data;
        }

        // 폼 검증이 이미 통과시킨 값이다 — 여기까지 왔으면 파싱은 성공한다.
        $parsed = Yaml::parse($yaml);

        $data['advanced'] = array_diff_key(
            is_array($parsed) ? $parsed : [],
            array_flip(['id', 'name', 'summary', 'egg', 'available', 'unavailable_reason', 'sizes', 'ask']),
        );

        return $data;
    }
}
