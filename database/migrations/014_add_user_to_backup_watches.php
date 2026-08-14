<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 백업 완료 알림을 **시킨 사람에게** 보낸다 (#48).
 *
 * 처음에는 서버 소유자에게만 갔다 — 친구가 백업을 눌렀는데 정작 결과는 주인이 받았다.
 * 백업은 사용자가 직접 시작한 작업이므로 받을 사람이 분명하다.
 *
 * 🔴 user_id 는 unsignedInteger 다 — 013 과 같은 이유(#106). 패널의 users.id 는
 *    INT UNSIGNED 이고, foreignId() 가 내는 BIGINT UNSIGNED 는 SQLite 밖에서 거절된다.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 013 이 껍데기를 치우고 새로 만들었으니 이 칸은 없는 것이 정상이다.
        // 그래도 확인한다 — 중간에 멎은 설치를 다시 밟을 때 두 번 붙이지 않도록.
        if (Schema::hasColumn('wisdom_agent_backup_watches', 'user_id')) {
            return;
        }

        Schema::table('wisdom_agent_backup_watches', function (Blueprint $table) {
            $table->unsignedInteger('user_id')->nullable();

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('wisdom_agent_backup_watches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
