<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 사용 한도의 3축 구성 (#4) — 기준(메시지·토큰) × 범위(사용자·패널) × 주기(시·일·주·월).
 *
 * `daily_message_limit` 하나였던 한도가 규칙 목록(`usage_limits`, JSON)이 된다.
 * 기존 값은 동등한 규칙(사용자별 · 일 · 메시지)으로 옮긴다 — 업그레이드해도
 * 동작이 그대로다(이슈의 done-when). 0(무제한)이었다면 빈 목록이 된다.
 *
 * `concierge_usages.created_at` 인덱스: 패널 전체 한도가 user_id 없이 기간으로만
 * 조회한다 — 기존 (user_id, created_at) 인덱스는 이 조회를 돕지 못한다.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('concierge_settings', 'usage_limits')) {
            Schema::table('concierge_settings', function (Blueprint $table) {
                $table->text('usage_limits')->nullable();
            });

            foreach (DB::table('concierge_settings')->get() as $row) {
                $limit = (int) ($row->daily_message_limit ?? 0);

                DB::table('concierge_settings')->where('id', $row->id)->update([
                    'usage_limits' => json_encode($limit > 0
                        ? [['metric' => 'messages', 'scope' => 'user', 'period' => 'day', 'amount' => $limit]]
                        : []),
                ]);
            }

            Schema::table('concierge_settings', function (Blueprint $table) {
                $table->dropColumn('daily_message_limit');
            });
        }

        Schema::table('concierge_usages', function (Blueprint $table) {
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('concierge_usages', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });

        if (Schema::hasColumn('concierge_settings', 'usage_limits')) {
            Schema::table('concierge_settings', function (Blueprint $table) {
                $table->unsignedInteger('daily_message_limit')->default(50);
            });

            // 되돌릴 때 살릴 수 있는 것은 첫 "사용자별 · 일 · 메시지" 규칙뿐이다.
            foreach (DB::table('concierge_settings')->get() as $row) {
                $rules = json_decode((string) $row->usage_limits, true) ?: [];
                $daily = collect($rules)->first(fn ($r) => ($r['metric'] ?? '') === 'messages'
                    && ($r['scope'] ?? '') === 'user'
                    && ($r['period'] ?? '') === 'day');

                DB::table('concierge_settings')->where('id', $row->id)->update([
                    'daily_message_limit' => (int) ($daily['amount'] ?? 0),
                ]);
            }

            Schema::table('concierge_settings', function (Blueprint $table) {
                $table->dropColumn('usage_limits');
            });
        }
    }
};
