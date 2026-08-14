<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * egg 가져오기 시작점이 이제 **가져와 달라고 말한다** (#105).
 *
 * 027 이 심었을 때 이 문장은 "새 egg 를 가져오려면 뭘 해야 하는지 알려줘"였다. 에이전트가
 * egg 를 읽을 수만 있고 들여올 수는 없어서, 할 수 없는 일을 시키지 않으려고 안내까지만
 * 부탁하게 둔 것이다(#48 — 못 하는 것을 권하는 시작점은 막다른 길이다).
 *
 * 이제 도구가 있다(list_importable_eggs · import_egg). 문장을 바꾼다.
 *
 * ⚠ **운영자가 고쳐 둔 문장은 건드리지 않는다.** 시작점은 운영자 데이터고, 편집 화면도
 *   있다(#103). 우리가 심은 그대로일 때만 바꾼다 — 그래서 옛 문장을 여기에 글자 그대로
 *   적어 둔다. lang 에서 읽어 오면 이 마이그레이션이 돌 때의 lang 은 이미 새 문장이라
 *   무엇과도 맞지 않는다.
 */
return new class extends Migration
{
    private const KEY = 'egg_import';

    /** 027 이 심은 그대로의 문장. 이것과 같을 때만 바꾼다. */
    private const OLD = [
        'en' => 'I want to add a game this panel does not have yet. Show me which eggs are imported, then tell me what I need to do to bring in a new one.',
        'ko' => '이 패널에 없는 게임을 추가하고 싶어. 지금 어떤 egg 가 들어와 있는지 보여주고, 새 egg 를 가져오려면 뭘 해야 하는지 알려줘.',
    ];

    private const NEW = [
        'en' => 'I want to add a game this panel does not have yet. Show me which eggs are imported, then find the one I want in the official index and bring it in.',
        'ko' => '이 패널에 없는 게임을 추가하고 싶어. 지금 어떤 egg 가 들어와 있는지 보여주고, 없는 게임은 공식 목록에서 찾아서 가져와줘.',
    ];

    public function up(): void
    {
        $this->rewrite(self::OLD, self::NEW);
    }

    public function down(): void
    {
        $this->rewrite(self::NEW, self::OLD);
    }

    /**
     * @param  array<string, string>  $from
     * @param  array<string, string>  $to
     */
    private function rewrite(array $from, array $to): void
    {
        $row = DB::table('concierge_presets')->where('preset_key', self::KEY)->first();

        if ($row === null) {
            return; // 운영자가 지웠다면 되살릴 일이 아니다.
        }

        $translations = json_decode((string) $row->prompt_translations, true);
        $translations = is_array($translations) ? $translations : [];

        // 기본값과 언어별 값을 따로 본다 — 한쪽만 고쳐 둔 경우 그쪽만 남긴다.
        $update = [];

        if ((string) $row->prompt === $from['en']) {
            $update['prompt'] = $to['en'];
        }

        $changed = false;

        foreach ($to as $locale => $text) {
            if (($translations[$locale] ?? null) === $from[$locale]) {
                $translations[$locale] = $text;
                $changed = true;
            }
        }

        if ($changed) {
            $update['prompt_translations'] = json_encode($translations, JSON_UNESCAPED_UNICODE);
        }

        if ($update !== []) {
            DB::table('concierge_presets')->where('preset_key', self::KEY)
                ->update($update + ['updated_at' => now()]);
        }
    }
};
