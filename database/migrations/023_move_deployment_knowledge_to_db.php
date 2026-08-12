<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 배포 지식을 DB 로 옮긴다 (#59).
 *
 * 종전에는 설치된 플러그인 안의 `resources/knowledge/agent.md` 를 읽었다. 저장소로
 * 배포하는 환경에서는 스크립트가 그 파일을 넣어 주니 동작했지만, 허브에서 받은
 * 사람에게는 쓸 수 없는 방식이었다:
 *  · 화면에서 만들 방법이 없다(서버 셸이 필요).
 *  · **플러그인을 업데이트하면 사라진다** — 갱신이 디렉터리를 통째로 지우고 다시 푼다.
 *
 * 이 플러그인은 API 키를 파일·env 가 아니라 DB 에 두는 이유를 이미 갖고 있다.
 * 배포 지식도 같은 자리에 둔다 — 업데이트를 견디고, 화면에서 고칠 수 있다.
 *
 * 기존 설치를 위해 **파일이 있으면 그 내용을 옮겨 담는다.** 옮긴 뒤 조립되는 프롬프트가
 * 그대로여야 하므로, 사람에게 하는 머리말은 잘라내고 지식 본문만 넣는다.
 */
return new class extends Migration
{
    /** 파일 앞부분의 유지보수 안내와 실제 지식을 가르는 표시. */
    private const MARKER = '## Knowledge about this deployment';

    public function up(): void
    {
        if (Schema::hasColumn('concierge_settings', 'deployment_knowledge')) {
            return;
        }

        Schema::table('concierge_settings', function (Blueprint $table) {
            $table->text('deployment_knowledge')->nullable();
        });

        $seed = $this->fromFile();

        if ($seed === null) {
            return;
        }

        DB::table('concierge_settings')->update(['deployment_knowledge' => $seed]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('concierge_settings', 'deployment_knowledge')) {
            Schema::table('concierge_settings', function (Blueprint $table) {
                $table->dropColumn('deployment_knowledge');
            });
        }
    }

    /** 옛 파일에서 지식 본문만 꺼낸다. 없으면 null — 새 설치는 빈 값으로 시작한다. */
    private function fromFile(): ?string
    {
        $path = plugin_path('concierge', 'resources', 'knowledge', 'agent.md');

        if (!is_file($path)) {
            return null;
        }

        $content = trim((string) file_get_contents($path));
        $position = strpos($content, self::MARKER);

        if ($position !== false) {
            // 표시 줄 자체는 프롬프트가 직접 붙인다 — 본문만 남긴다.
            $content = trim(substr($content, $position + strlen(self::MARKER)));
        }

        return $content === '' ? null : $content;
    }
};
