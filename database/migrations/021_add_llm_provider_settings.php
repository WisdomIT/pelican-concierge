<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LLM 공급자 선택 (#3).
 *
 * - `concierge_settings.provider` — 활성 공급자. 기존 컬럼(api_key·model·effort)은
 *   **활성 공급자의 값**으로 그대로 쓴다 — 기존 코드·설치 무변경.
 * - `concierge_settings.base_url` — OpenAI 호환(로컬) 엔드포인트의 주소.
 * - `concierge_settings.provider_settings` — 공급자별 {api_key, base_url, model, effort}
 *   스냅샷(암호화 JSON). 전환할 때 이전 공급자의 값을 여기 넣고 새 공급자의 값을
 *   꺼낸다 — **전환해도 키·모델 선택을 잃지 않는다**(이슈의 done-when).
 * - `concierge_usages.provider` — 사용량 귀속. 행마다 model·effort 는 이미 저장 중이었다.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('concierge_settings', 'provider')) {
            Schema::table('concierge_settings', function (Blueprint $table) {
                $table->string('provider')->default('anthropic');
                $table->string('base_url')->nullable();
                $table->text('provider_settings')->nullable();
            });
        }

        if (!Schema::hasColumn('concierge_usages', 'provider')) {
            Schema::table('concierge_usages', function (Blueprint $table) {
                // 과거 행은 전부 Anthropic 시절의 것이다 — 기본값이 곧 사실이다.
                $table->string('provider')->default('anthropic');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('concierge_settings', 'provider')) {
            Schema::table('concierge_settings', function (Blueprint $table) {
                $table->dropColumn(['provider', 'base_url', 'provider_settings']);
            });
        }

        if (Schema::hasColumn('concierge_usages', 'provider')) {
            Schema::table('concierge_usages', function (Blueprint $table) {
                $table->dropColumn('provider');
            });
        }
    }
};
