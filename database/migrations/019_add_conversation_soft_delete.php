<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 대화 soft delete (#8).
 *
 * 사용자는 목록에서 지우고(정리·어깨너머 프라이버시), 관리자는 기록을 계속 본다 —
 * 이 에이전트는 API 예산을 쓰고 서버를 만들고 지우는 물건이라 감사 기록이 선택일 수 없다.
 * 그래서 usages·tool_calls 로는 **절대 연쇄하지 않는다**(사용량 집계가 소급 왜곡된다).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('concierge_conversations', 'deleted_at')) {
            Schema::table('concierge_conversations', function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        if (!Schema::hasColumn('concierge_settings', 'allow_conversation_delete')) {
            Schema::table('concierge_settings', function (Blueprint $table) {
                // 기본 꺼짐 — 지울 수 있게 할지는 운영자의 결정이다(#8).
                $table->boolean('allow_conversation_delete')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('concierge_conversations', 'deleted_at')) {
            Schema::table('concierge_conversations', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }

        if (Schema::hasColumn('concierge_settings', 'allow_conversation_delete')) {
            Schema::table('concierge_settings', function (Blueprint $table) {
                $table->dropColumn('allow_conversation_delete');
            });
        }
    }
};
