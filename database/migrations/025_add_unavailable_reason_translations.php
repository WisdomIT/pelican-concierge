<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 개설 불가 사유의 언어별 문구 (#99).
 *
 * 이름·설명은 #81 에서 번역을 받았는데 이 문장은 빠져 있었다. 하필 **왜 이 게임을 만들 수
 * 없는지 설명하는 문장**이라, 모르는 언어로 나가면 설명이 아니게 된다.
 *
 * 크기·질문 라벨은 목록 안에 있어 컬럼이 필요 없다 — 항목마다 `<필드>_translations` 를
 * 함께 넣는다(ConciergeGame::localizeItems).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('concierge_games', function (Blueprint $table) {
            $table->json('unavailable_reason_translations')->nullable()->after('unavailable_reason');
        });
    }

    public function down(): void
    {
        Schema::table('concierge_games', function (Blueprint $table) {
            $table->dropColumn('unavailable_reason_translations');
        });
    }
};
