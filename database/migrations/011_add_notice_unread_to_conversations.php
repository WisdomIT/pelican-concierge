<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 대화별 미읽음 알림 표시 (#29).
 *
 * 선제 알림이 **현재 열린 대화가 아니라 그 서버를 다루던 대화**로 가게 되면서,
 * "다른 대화에 새 알림이 있다"를 목록에서 보여줄 방법이 필요하다.
 * 런처의 점 하나로는 어느 대화인지 알 수 없다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wisdom_agent_conversations', function (Blueprint $table) {
            // 알림이 도착했는데 아직 안 연 대화. 열면 지운다.
            $table->timestamp('notice_unread_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('wisdom_agent_conversations', function (Blueprint $table) {
            $table->dropColumn('notice_unread_at');
        });
    }
};
