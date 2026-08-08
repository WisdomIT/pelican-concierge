<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `log_content` 스위치를 걷어낸다.
 *
 * **왜** — 이 스위치는 "관리자가 남의 대화를 읽을 수 있는가"로 만들었는데, 대화 이어보기가
 * 생기면서 "사용자가 **자기** 대화를 이어볼 수 있는가"까지 같이 결정하게 됐다. 목적이 둘로
 * 갈라진 스위치는 어느 쪽으로 놔도 한쪽이 망가진다.
 *
 * 운영 방침은 **관리자는 항상 내용을 볼 수 있어야 한다**로 정해졌다. 에이전트가 남의 서버에
 * 실제 명령을 내리는 물건이라 감사 기록이 선택 사항이 될 수 없다. 그러면 이 스위치는 유효한
 * 꺼짐 상태가 없으므로 남겨두면 오해만 만든다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wisdom_agent_settings', function (Blueprint $table) {
            $table->dropColumn('log_content');
        });
    }

    public function down(): void
    {
        Schema::table('wisdom_agent_settings', function (Blueprint $table) {
            $table->boolean('log_content')->default(true);
        });
    }
};
