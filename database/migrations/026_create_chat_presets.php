<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 대화의 시작점을 DB 로 (#93).
 *
 * 처음에는 코드에 박아 뒀는데, 시작점은 **운영자가 고칠 것**이다 — 어떤 질문으로 열어야
 * 좋은지는 그 패널의 사용자들이 무엇을 묻는지에 달렸고, 우리가 알 수 없다.
 * 배포 지식(#59)·카탈로그(#81)를 옮긴 것과 같은 이유다.
 *
 * 보일 조건이 셋이다 — 셋 다 통과해야 보인다:
 *  · visibility  누구에게      all / create(개설 가능) / admin
 *  · permission  추가 권한      예: `update egg` — 비면 검사하지 않는다
 *  · path        어느 화면에서  glob. 비면 **모든 화면**(글로벌)
 *
 * 🔴 path 를 붙이는 이유: 카탈로그 시작점이 서버 콘솔에서 뜨면 지금 하려는 일과 무관한
 *    제안이다. 시작점은 **지금 보고 있는 화면**에서 할 만한 일이어야 한다.
 *
 * ⚠ 편집 화면은 아직 없다(설정 페이지를 탭으로 재구성하며 함께 만든다). 지금은 구조만
 *   DB 로 옮겨 두고, 값은 이 마이그레이션이 심는다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('concierge_presets', function (Blueprint $table) {
            $table->id();
            // 화면 바깥에서 대화를 열 때 쓰는 식별자(cg-start 이벤트가 이 값을 넘긴다).
            $table->string('preset_key')->unique();
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('enabled')->default(true);

            // 사람이 읽는 것 — 기본값 + 언어별(#99 와 같은 규칙: 비면 기본값).
            $table->string('label');
            $table->json('label_translations')->nullable();
            $table->text('prompt');
            $table->json('prompt_translations')->nullable();

            // 누구에게 보일지.
            $table->string('visibility')->default('all');
            // 그 위에 요구할 권한. 문자열 그대로 `$user->can()` 에 넘긴다 — 패널 리소스
            // 권한(`update egg`)이든 이 플러그인 것(`viewList wisdomAgent`)이든 같다.
            $table->string('permission')->nullable();

            // 어느 화면에서 보일지. 비면 전 화면. 예: `*/concierge-games*`
            $table->string('path_pattern')->nullable();

            $table->timestamps();
        });

        $this->seed();
    }

    public function down(): void
    {
        Schema::dropIfExists('concierge_presets');
    }

    /**
     * 배포본 시작점. 문구는 lang 에서 가져온다 — 두 언어를 여기에 다시 적으면 lang 과
     * 갈라지고, 그때부터 어느 쪽이 맞는지 알 수 없게 된다.
     */
    private function seed(): void
    {
        $rows = [
            // 글로벌 — 서버를 돌보는 사람 모두에게, 어느 화면에서나.
            ['status', 'all', null, null],
            ['cannot_join', 'all', null, null],
            // 개설할 수 있는 사람에게만.
            ['games', 'create', null, null],
            // 관리자.
            ['health', 'admin', null, null],
            // 🔴 카탈로그 화면에서만. 다른 화면에서는 지금 하려는 일과 무관한 제안이다.
            ['catalog_new', 'admin', 'update egg', '*concierge-games*'],
        ];

        foreach ($rows as $sort => [$key, $visibility, $permission, $path]) {
            DB::table('concierge_presets')->insert([
                'preset_key' => $key,
                'sort' => $sort,
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

    private function line(string $key, string $locale): string
    {
        return (string) trans("concierge::strings.{$key}", [], $locale);
    }
};
