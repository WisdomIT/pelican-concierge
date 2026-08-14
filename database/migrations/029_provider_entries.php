<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * 공급자를 **순서 있는 목록**으로 (#89).
 *
 * 지금까지는 한 번에 하나였다. 그게 답을 멈추면 — 크레딧이 떨어지거나, 쿼터에 걸리거나,
 * 공급자가 죽으면 — 누군가 설정 화면을 열어 바꿀 때까지 어시스턴트는 그냥 고장이었다.
 * 아무도 듣지 못하고, 첫 신호는 사용자의 "답을 안 해요"였다.
 *
 * provider_settings 는 공급자 id 를 키로 하는 map 이라 **공급자당 하나**만 담을 수 있고
 * 순서가 없다 — "이걸 쓰고, 안 되면 저걸"을 표현할 수 없는 모양이다. 목록으로 바꾼다.
 *
 * ⚠ provider_settings 는 지우지 않는다. 설정 화면이 공급자를 오갈 때 쓰는 스냅샷이고,
 *   되돌리기(down)의 근거이기도 하다. 목록이 사실이고 그쪽은 그대로 둔다.
 *
 * 🔴 목록에는 **키가 들어간다.** 컬럼째 암호화한다 — provider_settings 와 같은 규칙(#3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('concierge_settings', function (Blueprint $table) {
            $table->text('provider_entries')->nullable()->after('provider_settings');
        });

        Schema::table('concierge_usages', function (Blueprint $table) {
            // 어느 항목으로 청구됐는가 (#89). 같은 공급자를 둘 두면 provider·model 만으로는
            // 구분되지 않는다 — 장애 조치 뒤 비용 내역이 조용히 거짓말하지 않도록 남긴다.
            // 항목이 지워져도 읽히도록 **id 가 아니라 이름**을 적는다.
            $table->string('provider_entry')->nullable()->after('provider');
        });

        $this->seedFromCurrentSettings();
    }

    public function down(): void
    {
        Schema::table('concierge_settings', fn (Blueprint $table) => $table->dropColumn('provider_entries'));
        Schema::table('concierge_usages', fn (Blueprint $table) => $table->dropColumn('provider_entry'));
    }

    /**
     * 지금 설정을 목록의 첫 항목으로 옮기고, 다른 공급자의 스냅샷 중 **쓸 수 있는 것**을
     * 뒤에 붙인다. 운영자가 이미 키를 넣어 둔 공급자는 그대로 대비책이 된다 — 설정 화면을
     * 열어 다시 입력하게 하지 않는다.
     */
    private function seedFromCurrentSettings(): void
    {
        $row = DB::table('concierge_settings')->first();

        if ($row === null) {
            return; // 아직 설정 행이 없다 — 첫 저장 때 만들어진다.
        }

        $active = $row->provider ?: 'anthropic';
        $entries = [[
            'id' => (string) Str::ulid(),
            'label' => $this->label($active),
            'provider' => $active,
            'api_key' => $this->decrypt($row->api_key),
            'base_url' => $row->base_url,
            'model' => (string) $row->model,
            'effort' => $row->effort,
            'max_tokens' => (int) $row->max_tokens,
        ]];

        foreach ($this->snapshots($row) as $provider => $snapshot) {
            if ($provider === $active) {
                continue; // 활성 값이 이미 첫 항목이다.
            }

            $key = $snapshot['api_key'] ?? null;
            $base = $snapshot['base_url'] ?? null;

            // 쓸 수 없는 스냅샷은 대비책이 못 된다 — 로컬은 주소만 있으면 되고 나머지는 키가 있어야 한다.
            $usable = $provider === 'openai-compatible' ? filled($base) : filled($key);

            if (!$usable) {
                continue;
            }

            $entries[] = [
                'id' => (string) Str::ulid(),
                'label' => $this->label($provider),
                'provider' => $provider,
                'api_key' => $key,
                'base_url' => $base,
                'model' => (string) ($snapshot['model'] ?? config("concierge.providers.{$provider}.default_model", '')),
                'effort' => $snapshot['effort'] ?? config("concierge.providers.{$provider}.default_effort"),
                // 응답 상한은 공급자별 스냅샷에 없었다 — 지금 값을 물려준다.
                'max_tokens' => (int) $row->max_tokens,
            ];
        }

        DB::table('concierge_settings')->where('id', $row->id)
            ->update(['provider_entries' => Crypt::encryptString(json_encode($entries, JSON_UNESCAPED_UNICODE))]);
    }

    /** @return array<string, array<string, mixed>> */
    private function snapshots(object $row): array
    {
        $raw = $this->decrypt($row->provider_settings);

        if ($raw === null) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 암호화된 칸을 연다. APP_KEY 가 바뀐 패널에서는 열리지 않는데, 그때는 값이 없는
     * 것으로 본다 — 마이그레이션이 거기서 죽으면 플러그인 전체가 설치되지 않는다.
     */
    private function decrypt(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function label(string $provider): string
    {
        return (string) (config("concierge.providers.{$provider}.label")
            ?? trans("concierge::strings.provider_{$provider}"));
    }
};
