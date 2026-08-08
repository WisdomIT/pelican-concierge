<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 유휴 서버 자동 정지 설정 (#18).
 *
 * 시간과 "정지까지 할지"를 관리자 화면에서 조절한다 — 값을 코드에 박으면 운영 중에
 * 바꿀 수가 없고, 친구 수·노드 여유에 따라 적정값이 달라진다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wisdom_agent_settings', function (Blueprint $table) {
            // 감시 자체. 꺼두면 표본도 수집하지 않는다.
            //  ⚠ 기본은 **꺼짐**이다. 남의 서버를 마음대로 끄는 기능이라 켜는 것은 명시적 결정이어야 한다.
            $table->boolean('idle_enabled')->default(false);

            // 이만큼 연속으로 아무도 없으면 유휴로 본다.
            $table->unsignedInteger('idle_minutes')->default(30);

            // 유휴 알림 뒤 이만큼 더 지나면 정지한다. 끄면 알리기만 하고 끄지 않는다.
            $table->boolean('idle_stop_enabled')->default(false);
            $table->unsignedInteger('idle_grace_minutes')->default(30);
        });
    }

    public function down(): void
    {
        Schema::table('wisdom_agent_settings', function (Blueprint $table) {
            $table->dropColumn(['idle_enabled', 'idle_minutes', 'idle_stop_enabled', 'idle_grace_minutes']);
        });
    }
};
