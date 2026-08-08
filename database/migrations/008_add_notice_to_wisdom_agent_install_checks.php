<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 판정 근거와 "사용자에게 알렸는가"를 더한다 (#7).
 *
 * 신호가 **용량에서 설치 로그로** 바뀌었다. 용량은 쓸 수 없다는 것이 실측으로 드러났다 —
 * wings 는 꺼져 있는 서버의 볼륨을 집계하지 않고(실제 276MB → 9바이트 보고), 재설치는
 * 볼륨을 비우지 않아 재시도해도 판정이 뒤집히지 않는다. 자세한 근거는 `InstallLogAuditor`.
 *
 * `observed_bytes`·`floor_mb` 는 남겨 둔다 — 서버가 **켜져 있을 때**는 유효한 값이라
 * `get_server_status` 의 보조 진단으로 쓴다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wisdom_agent_install_checks', function (Blueprint $table) {
            // 모델이 로그를 읽고 쓴 한 문장. 사용자에게 그대로 보여준다.
            $table->text('reason')->nullable()->after('status');

            // 알림은 **한 번만** 띄운다. 이 값이 비어 있는 실패 판정만 사이드바가 집어간다.
            $table->timestamp('notified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('wisdom_agent_install_checks', function (Blueprint $table) {
            $table->dropColumn(['reason', 'notified_at']);
        });
    }
};
