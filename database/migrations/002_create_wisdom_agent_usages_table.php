<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wisdom_agent_usages', function (Blueprint $table) {
            $table->increments('id');
            // users.id 는 Pelican 에서 unsignedInteger 다 — bigInteger 로 잡으면 FK 가 안 걸린다.
            $table->unsignedInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->string('model');
            $table->string('effort');
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            // ok | rate_limited | not_configured | disabled | error
            $table->string('status')->default('ok');
            $table->text('error')->nullable();
            // settings.log_content 가 꺼져 있으면 둘 다 null 로 남는다.
            $table->text('user_message')->nullable();
            $table->text('assistant_message')->nullable();
            $table->timestamps();

            // 일일 한도 검사가 (user_id, created_at) 로 매 메시지마다 조회한다.
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wisdom_agent_usages');
    }
};
