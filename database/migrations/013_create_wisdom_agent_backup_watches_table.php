<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 백업·복원 완료 감시 (#36).
 *
 * ⚠ 도구가 "끝나면 알려드립니다"라고 약속한다. 약속을 지킬 수단이 없으면 그런 말을 하게
 *   두면 안 된다 — 설치 검증(#7)과 같은 이유로 상태를 DB 에 남긴다. 브라우저를 닫아도
 *   다음에 열었을 때 알림이 간다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wisdom_agent_backup_watches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained('servers')->cascadeOnDelete();
            // 백업 uuid. 복원 감시는 특정 백업이 아니라 서버 상태를 보므로 비어 있을 수 있다.
            $table->string('backup_uuid')->nullable();
            $table->string('kind')->default('backup');   // backup | restore
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->index(['notified_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wisdom_agent_backup_watches');
    }
};
