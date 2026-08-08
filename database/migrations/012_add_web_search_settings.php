<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 웹 검색 설정 (#43).
 *
 * 검색은 **토큰과 별도로 과금**되므로 관리자가 끌 수 있어야 하고, 턴당 상한도 필요하다.
 * 기본은 꺼짐 — 켤지 말지는 비용을 보는 사람이 정한다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wisdom_agent_settings', function (Blueprint $table) {
            $table->boolean('search_enabled')->default(false);
            $table->unsignedInteger('search_max_uses')->default(3);
        });

        // 검색 횟수는 토큰과 성격이 다른 비용이라 따로 센다(사용량 화면이 이걸 더한다).
        Schema::table('wisdom_agent_usages', function (Blueprint $table) {
            $table->unsignedInteger('search_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('wisdom_agent_settings', function (Blueprint $table) {
            $table->dropColumn(['search_enabled', 'search_max_uses']);
        });

        Schema::table('wisdom_agent_usages', function (Blueprint $table) {
            $table->dropColumn('search_count');
        });
    }
};
