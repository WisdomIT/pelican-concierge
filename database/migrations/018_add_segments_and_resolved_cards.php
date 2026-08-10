<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 확정된 카드의 보존과 대화 구간(segment) 분리 (#6).
 *
 * - `concierge_usages.resolved_cards` — 이 턴에서 결정된 확인 카드들. 카드가 보여준
 *   요약(이름·게임·자원·diff)이 곧 "무엇이 실행됐는가"의 기록인데, 지금은 한 줄
 *   이벤트로 뭉개져 새로고침 후엔 제목과 "실행됨"만 남는다.
 * - `concierge_usages.segment` — 이 턴이 속한 구간. 실행된 액션이 구간의 경계다.
 * - `concierge_conversations.active_segment` — 지금 열려 있는 구간 번호. 카드 승인
 *   시점에 오르고, **이후** 턴부터 새 구간에 기록된다(승인된 턴 자체는 옛 구간의 끝).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('concierge_usages', 'segment')) {
            Schema::table('concierge_usages', function (Blueprint $table) {
                $table->unsignedInteger('segment')->default(0);
                $table->json('resolved_cards')->nullable();
            });
        }

        if (!Schema::hasColumn('concierge_conversations', 'active_segment')) {
            Schema::table('concierge_conversations', function (Blueprint $table) {
                $table->unsignedInteger('active_segment')->default(0);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('concierge_usages', 'segment')) {
            Schema::table('concierge_usages', function (Blueprint $table) {
                $table->dropColumn(['segment', 'resolved_cards']);
            });
        }

        if (Schema::hasColumn('concierge_conversations', 'active_segment')) {
            Schema::table('concierge_conversations', function (Blueprint $table) {
                $table->dropColumn('active_segment');
            });
        }
    }
};
