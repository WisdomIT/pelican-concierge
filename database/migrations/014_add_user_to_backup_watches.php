<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 백업 완료 알림을 **시킨 사람에게** 보낸다 (#48).
 *
 * 처음에는 서버 소유자에게만 갔다 — 친구가 백업을 눌렀는데 정작 결과는 주인이 받았다.
 * 백업은 사용자가 직접 시작한 작업이므로 받을 사람이 분명하다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wisdom_agent_backup_watches', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('wisdom_agent_backup_watches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
