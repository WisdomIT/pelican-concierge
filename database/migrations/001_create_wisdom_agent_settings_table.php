<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wisdom_agent_settings', function (Blueprint $table) {
            $table->increments('id');
            // 암호화해서 저장한다(Model 의 'encrypted' 캐스트) → 원문보다 길어지므로 text.
            //  ⚠ 복호화 키는 APP_KEY 다. APP_KEY 를 잃으면 이 값은 복구할 수 없다(재입력하면 됨).
            $table->text('api_key')->nullable();
            $table->string('model')->default('claude-opus-5');
            $table->string('effort')->default('medium');
            $table->unsignedInteger('max_tokens')->default(8192);
            // 0 은 무제한.
            $table->unsignedInteger('daily_message_limit')->default(50);
            // 대화 내용을 로그에 남길지. 끄면 사용량(횟수·토큰)만 기록한다.
            $table->boolean('log_content')->default(true);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wisdom_agent_settings');
    }
};
