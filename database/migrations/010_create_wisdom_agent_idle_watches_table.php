<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 서버별 유휴 추적 상태 (#18). 서버당 한 행.
 *
 * **왜 DB 인가** — 판정이 "연속 N분"이라 표본 사이에 상태가 남아야 한다. 캐시에 두면
 * `cache:clear` 한 번에 타이머가 리셋되어 영영 유휴에 도달하지 않는다(확인 카드에서 겪었다).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wisdom_agent_idle_watches', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('server_id')->unique();
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();

            /**
             * 직전에 본 rx 누적값. wings 가 주는 것은 **누적 카운터**라 증가분으로만 의미가 있다.
             *  ⚠ 재시작하면 0 부터 다시 센다 → 값이 줄면 활동이 아니라 리셋으로 봐야 한다.
             */
            $table->unsignedBigInteger('last_rx')->nullable();

            /** 아무도 없기 시작한 시각. 활동이 보이면 null 로 되돌린다. */
            $table->timestamp('idle_since')->nullable();

            /** 사용자에게 알린 시각. 유예 시간은 이 시점부터 센다. */
            $table->timestamp('notified_at')->nullable();

            /** 사용자가 "더 켜두기"를 누른 시각. 이때부터 다시 처음처럼 센다. */
            $table->timestamp('snoozed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wisdom_agent_idle_watches');
    }
};
