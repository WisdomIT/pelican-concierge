<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** 기존 기록을 한 대화로 묶을 때 쓰는 간격. 이보다 벌어지면 다른 대화로 본다. */
    private const BACKFILL_GAP_MINUTES = 10;

    public function up(): void
    {
        Schema::table('wisdom_agent_usages', function (Blueprint $table) {
            // ULID 를 쓴다 — **사전순 정렬이 곧 시간순**이라 대화를 최신부터 보여줄 때
            // 별도 정렬 컬럼이 필요 없다. (UUID v4 였다면 불가능하다)
            $table->string('conversation_id', 26)->nullable()->after('user_id');
            $table->index('conversation_id');
        });

        $this->backfill();
    }

    /**
     * 이 컬럼이 생기기 전 기록에는 대화 구분이 없다. 같은 사용자의 연속 기록을
     * 시간 간격으로 끊어 묶는다 — **추정이므로 여기(1회성)에서만 한다.**
     * 이후 기록은 채팅 화면이 실제 대화 id 를 넣는다.
     */
    private function backfill(): void
    {
        $rows = DB::table('wisdom_agent_usages')
            ->whereNull('conversation_id')
            ->orderBy('user_id')
            ->orderBy('created_at')
            ->get(['id', 'user_id', 'created_at']);

        $currentUser = null;
        $previousAt = null;
        $conversationId = null;

        foreach ($rows as $row) {
            $createdAt = Carbon::parse($row->created_at);

            $isNewConversation = $row->user_id !== $currentUser
                || $previousAt === null
                || $previousAt->diffInMinutes($createdAt) > self::BACKFILL_GAP_MINUTES;

            if ($isNewConversation) {
                // 대화 시작 시각으로 만들면 백필한 id 도 시간순으로 정렬된다.
                $conversationId = (string) Str::ulid($createdAt);
            }

            DB::table('wisdom_agent_usages')
                ->where('id', $row->id)
                ->update(['conversation_id' => $conversationId]);

            $currentUser = $row->user_id;
            $previousAt = $createdAt;
        }
    }

    public function down(): void
    {
        Schema::table('wisdom_agent_usages', function (Blueprint $table) {
            $table->dropIndex(['conversation_id']);
            $table->dropColumn('conversation_id');
        });
    }
};
