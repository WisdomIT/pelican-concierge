<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 할당량이 넘어가는 시계를 설정으로 (#82).
 *
 * "하루 50건"의 하루가 어느 자정인지는 여태 컨테이너의 `TZ` 환경변수가 정했다.
 * 그 기준 자체는 옳다 — 할당량과 사용량 집계는 **서버 시간**을 따라야 관리자가 서버
 * 로그와 대조할 수 있고, 사람마다 달라지면 "누가 몇 건 썼나"가 관측자에 따라 달라진다
 * (#79 가 예약·타임스탬프를 사용자 프로필로 보낸 것과 일부러 갈라 둔 지점이다).
 *
 * 문제는 두 가지였다:
 *  · 바꾸려면 **컨테이너 환경변수**를 고쳐야 했다 — 화면에서 닿지 않는다
 *  · 설정 화면이 **어느 시계인지 말하지 않았다**. "하루 50건"만 적혀 있으니
 *    한국 운영자가 UTC 자정에 초기화되는 것을 모른 채 쓰게 된다
 *
 * ⚠ 비어 있으면 지금 그대로다(`TZ` → `app.timezone`). 손대지 않은 설치의 동작이
 *   바뀌지 않아야 하므로 기본값을 심지 않는다 — 심으면 그 순간 경계가 옮겨간다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('concierge_settings', function (Blueprint $table) {
            $table->string('quota_timezone')->nullable()->after('usage_limits');
        });
    }

    public function down(): void
    {
        Schema::table('concierge_settings', fn (Blueprint $table) => $table->dropColumn('quota_timezone'));
    }
};
