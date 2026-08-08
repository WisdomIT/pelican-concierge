<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * 플러그인 이름이 `wisdom-agent` → `wisdom-ai-assistant` 로 바뀌면서 테이블도 맞춘다.
 *
 * ⚠ 앞선 마이그레이션(001~014)은 **그대로 둔다.** 이미 돌아간 설치의 기록과 어긋나면
 *   Laravel 이 다시 돌리려 들기 때문이다. 새 설치는 옛 이름으로 만든 뒤 이 단계에서
 *   한 번에 옮겨 간다 — 결과는 같다.
 *
 * ⚠ 이름이 이미 새것인 경우(새 설치가 아니라 재실행)를 대비해 양쪽 존재를 확인한다.
 *   `Schema::rename` 은 대상이 이미 있으면 실패한다.
 */
return new class extends Migration
{
    /** @var array<int, string> */
    private array $tables = [
        'settings',
        'usages',
        'tool_calls',
        'conversations',
        'install_checks',
        'idle_watches',
        'backup_watches',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            $from = 'wisdom_agent_' . $table;
            $to = 'wisdom_ai_assistant_' . $table;

            if (Schema::hasTable($from) && !Schema::hasTable($to)) {
                Schema::rename($from, $to);
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            $from = 'wisdom_ai_assistant_' . $table;
            $to = 'wisdom_agent_' . $table;

            if (Schema::hasTable($from) && !Schema::hasTable($to)) {
                Schema::rename($from, $to);
            }
        }
    }
};
