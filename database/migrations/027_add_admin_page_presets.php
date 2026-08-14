<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 관리자 화면 두 곳의 시작점 (#93 후속).
 *
 * 026 이 심은 다섯은 대부분 전역이었다 — 어느 화면에서나 뜬다. 화면별 시작점은
 * 카탈로그 하나뿐이었는데, 관리자가 오래 머무는 화면은 그 밖에도 있다.
 * egg 목록과 사용자 목록이 그렇고, 둘 다 "여기서 하려던 일"이 분명한 화면이다.
 *
 * ⚠ egg 쪽은 **에이전트가 대신 해 줄 수 없다.** 지금 egg 도구는 읽기뿐이고
 *   (list_eggs · get_egg_details) 가져오기 도구가 없다 — 가져오기는 그 화면
 *   툴바의 Import 버튼이 한다. 그래서 문장을 "대신 해 줘"가 아니라 "지금 뭐가
 *   있고 어떻게 가져오는지 알려 줘"로 뒀다. 도구가 생기면 문장만 바꾸면 된다.
 *   할 수 없는 일을 시키는 시작점은 안내가 아니라 막다른 길이다(#48).
 *
 * 사용자 쪽은 create_panel_user 가 있어 끝까지 해 준다 — 만들기 전에 확인 카드가
 * 뜨고, 비밀번호는 아무도 정하지 않는다(당사자가 메일 링크로 고른다).
 */
return new class extends Migration
{
    /** [키, 노출, 권한, 경로] — 권한은 그 화면의 버튼과 같은 것을 쓴다. */
    private const ROWS = [
        ['egg_import', 'admin', 'create egg', '*admin/eggs*'],
        ['user_new', 'admin', 'create user', '*admin/users*'],
    ];

    public function up(): void
    {
        // 뒤에 붙인다 — 앞자리는 운영자가 정한 순서다. 경로가 붙은 것은 어차피
        // 그 화면에서 글로벌보다 앞선다(ChatPresets::for).
        $sort = (int) DB::table('concierge_presets')->max('sort') + 1;

        foreach (self::ROWS as [$key, $visibility, $permission, $path]) {
            // 운영자가 같은 키를 이미 만들어 뒀다면 건드리지 않는다.
            if (DB::table('concierge_presets')->where('preset_key', $key)->exists()) {
                continue;
            }

            DB::table('concierge_presets')->insert([
                'preset_key' => $key,
                'sort' => $sort++,
                'enabled' => true,
                'label' => $this->line("preset_label_{$key}", 'en'),
                'label_translations' => json_encode([
                    'ko' => $this->line("preset_label_{$key}", 'ko'),
                    'en' => $this->line("preset_label_{$key}", 'en'),
                ], JSON_UNESCAPED_UNICODE),
                'prompt' => $this->line("preset_prompt_{$key}", 'en'),
                'prompt_translations' => json_encode([
                    'ko' => $this->line("preset_prompt_{$key}", 'ko'),
                    'en' => $this->line("preset_prompt_{$key}", 'en'),
                ], JSON_UNESCAPED_UNICODE),
                'visibility' => $visibility,
                'permission' => $permission,
                'path_pattern' => $path,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('concierge_presets')
            ->whereIn('preset_key', array_column(self::ROWS, 0))
            ->delete();
    }

    private function line(string $key, string $locale): string
    {
        return (string) trans("concierge::strings.{$key}", [], $locale);
    }
};
