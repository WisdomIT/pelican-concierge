<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Yaml\Yaml;

/**
 * 게임 카탈로그를 DB 로 (#81).
 *
 * 카탈로그는 **운영자의 데이터**다 — 패널마다 임포트한 egg 가 다르니 목록도 달라야 한다.
 * 그런데 고치려면 설치된 플러그인 안의 YAML 을 손으로 편집해야 했고, 무엇보다
 * **플러그인 업데이트가 그 파일을 지운다**(updatePlugin → cleanDownload → deleteDirectory).
 * 튜닝한 카탈로그가 릴리스 한 번에 사라지고, 에이전트가 만들 수 없는 게임을 제안할 때에야
 * 알게 된다. 배포 지식(#59)을 옮긴 것과 같은 이유다.
 *
 * 배포본 YAML 은 남는다 — **신규 설치의 씨앗이자 기존 설치의 이관 원본**이다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('concierge_games', function (Blueprint $table) {
            $table->id();
            // 카탈로그 안에서의 식별자. 도구 입력(create_server 의 game)이 이 값을 쓴다.
            $table->string('game_id')->unique();
            $table->unsignedInteger('sort')->default(0);

            // 사람이 읽는 것 — 기본 이름은 필수, 로케일별 이름은 선택이다(#79·#81).
            // 단일 언어 패널 운영자에게 번역 작성을 강요하지 않는다: 비면 기본값을 쓴다.
            $table->string('name');
            $table->json('name_translations')->nullable();
            $table->text('summary')->nullable();
            $table->json('summary_translations')->nullable();

            // 🔴 egg 는 **이름으로** 참조한다 — id 는 패널을 재구축하면 달라진다.
            $table->string('egg');

            $table->boolean('available')->default(true);
            $table->string('unavailable_reason')->nullable();

            // 크기·질문은 게임 안의 목록이라 JSON 이다. 화면에서는 반복 필드로 편집한다.
            $table->json('sizes')->nullable();
            $table->json('ask')->nullable();

            // 기술 항목 — 화면에서는 YAML 한 칸으로 편집한다(#81 결정).
            // 종류마다 형태가 달라(file_replace vs json_vmarg) 폼으로 펴면 오히려 어렵다.
            $table->json('advanced')->nullable();

            $table->timestamps();
        });

        $this->seedFromShippedCatalog();
    }

    public function down(): void
    {
        Schema::dropIfExists('concierge_games');
    }

    /**
     * 배포본 YAML 을 그대로 옮긴다. 파일이 없으면 빈 카탈로그로 시작한다 — 화면에서
     * 만들면 되고, 죽는 것보다 낫다.
     */
    private function seedFromShippedCatalog(): void
    {
        $path = plugin_path('concierge', 'resources', 'catalog', 'games.yaml');

        if (!is_file($path)) {
            return;
        }

        $games = Yaml::parseFile($path)['games'] ?? [];

        if (!is_array($games)) {
            return;
        }

        // 폼이 다루는 칸과 그 밖의 것을 가른다 — 나머지는 통째로 advanced 에 담아
        // 어느 하나도 잃지 않는다. 새 키가 생겨도 자동으로 따라온다.
        $columns = [
            'id', 'name', 'summary', 'egg', 'available', 'unavailable_reason', 'sizes', 'ask',
            'name_translations', 'summary_translations',
        ];

        foreach (array_values($games) as $sort => $game) {
            DB::table('concierge_games')->insert([
                'game_id' => (string) ($game['id'] ?? ''),
                'sort' => $sort,
                'name' => (string) ($game['name'] ?? ''),
                'name_translations' => isset($game['name_translations'])
                    ? json_encode($game['name_translations'], JSON_UNESCAPED_UNICODE)
                    : null,
                'summary' => $game['summary'] ?? null,
                'summary_translations' => isset($game['summary_translations'])
                    ? json_encode($game['summary_translations'], JSON_UNESCAPED_UNICODE)
                    : null,
                'egg' => (string) ($game['egg'] ?? ''),
                'available' => (bool) ($game['available'] ?? true),
                'unavailable_reason' => $game['unavailable_reason'] ?? null,
                'sizes' => json_encode($game['sizes'] ?? [], JSON_UNESCAPED_UNICODE),
                'ask' => json_encode($game['ask'] ?? [], JSON_UNESCAPED_UNICODE),
                'advanced' => json_encode(
                    array_diff_key($game, array_flip($columns)),
                    JSON_UNESCAPED_UNICODE,
                ),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
