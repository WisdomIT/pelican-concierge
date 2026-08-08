<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 설치가 실제로 끝났는지 확인한 기록 (#7).
 *
 * **왜 기록을 남기나** — 두 가지 때문이다.
 *  1. **자동 재설치 횟수를 세야 한다.** 안 세면 하한이 잘못 잡혔을 때 무한히 다시 깐다.
 *     캐시에 두면 `deploy.sh` 의 `cache:clear` 로 날아가므로 DB 여야 한다.
 *  2. 에이전트가 "설치가 덜 됐다"를 근거와 함께 설명할 수 있어야 한다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wisdom_agent_install_checks', function (Blueprint $table) {
            $table->increments('id');

            // servers.id 는 unsignedInteger 다. 서버가 지워지면 기록도 함께 지운다.
            $table->unsignedInteger('server_id')->unique();
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();

            // ok | short | retried | gave_up | unknown
            $table->string('status');

            /** 자동 재설치를 시도한 횟수. 무한 재설치를 막는 유일한 장치다. */
            $table->unsignedTinyInteger('attempts')->default(0);

            // 판정 근거. 나중에 하한이 맞았는지 되짚을 때 필요하다.
            $table->unsignedBigInteger('observed_bytes')->nullable();
            $table->unsignedInteger('floor_mb')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wisdom_agent_install_checks');
    }
};
