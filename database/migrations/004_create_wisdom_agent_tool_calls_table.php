<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wisdom_agent_tool_calls', function (Blueprint $table) {
            $table->increments('id');

            // 이 도구를 부른 메시지. 메시지가 지워지면 이력도 같이 지운다.
            $table->unsignedInteger('usage_id');
            $table->foreign('usage_id')->references('id')->on('wisdom_agent_usages')->cascadeOnDelete();

            // 대화 단위 조회를 usages 조인 없이 하기 위해 중복 저장한다.
            $table->string('conversation_id', 26)->nullable();

            $table->string('tool_name');

            // 어떤 서버를 건드렸는지. 서버가 지워져도 "무엇을 했는지"는 남겨야 하므로 nullOnDelete.
            $table->unsignedInteger('server_id')->nullable();
            $table->foreign('server_id')->references('id')->on('servers')->nullOnDelete();

            // settings.log_content 가 꺼져 있으면 둘 다 null 로 남는다(도구 이름과 서버는 남는다).
            $table->text('input')->nullable();
            $table->text('result')->nullable();

            $table->boolean('is_error')->default(false);
            $table->timestamps();

            $table->index(['conversation_id']);
            $table->index(['server_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wisdom_agent_tool_calls');
    }
};
