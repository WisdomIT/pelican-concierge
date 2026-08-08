<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * 대화 자체의 레코드. 지금까지 `conversation_id` 는 부모 행이 없는 맨 문자열이었다.
 *
 * **왜 필요한가** — 사이드바에 대화 목록을 띄우려면 메시지에서 얻을 수 없는 것이 둘 있다:
 *  - **제목**: 매번 `MIN(created_at)` 인 행의 `user_message` 를 파내는 건 목록마다 서브쿼리다
 *  - **마지막 활동 시각**: ULID 는 *시작* 순서다. 이어서 말한 옛 대화가 목록 아래에 남는다
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wisdom_agent_conversations', function (Blueprint $table) {
            // ULID 를 그대로 PK 로 쓴다 — 기존 usages.conversation_id 와 같은 값이라
            // 백필도 조인도 변환 없이 된다.
            $table->char('id', 26)->primary();

            // users.id 는 Pelican 에서 unsignedInteger 다 — bigInteger 로 잡으면 FK 가 안 걸린다.
            $table->unsignedInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            // 첫 발화에서 잘라 만든다. 비어 있으면 화면이 "새 대화"로 표시한다.
            $table->string('title')->nullable();

            // 목록 정렬용. updated_at 을 쓰지 않는 이유는 제목 변경 같은 것에도 흔들리기 때문이다.
            $table->timestamp('last_message_at')->nullable();

            // 확인 카드가 떠 있는 채로 새로고침하면 컴포넌트 프로퍼티가 날아간다. 카드를 다시
            // 그리는 데 필요한 최소한만 둔다.
            //  ⚠ **재개 상태(state)는 여기 두지 않는다.** 도구 결과(서버 파일 내용·콘솔 수천 자)가
            //    통째로 들어가고, 무엇보다 그건 **읽은 시점의 스냅샷**이다. 오래된 스냅샷으로
            //    파일 수정을 승인하면 그 사이의 변경을 덮어쓴다. 상태는 캐시에 짧게 두고
            //    만료되면 카드도 만료시키는 것이 맞다.
            $table->string('pending_token', 26)->nullable();
            $table->text('pending_card')->nullable();

            $table->timestamps();

            // 사이드바의 기본 질의: 내 대화를 최근 활동순으로.
            $table->index(['user_id', 'last_message_at']);
        });

        $this->backfill();

        // ⚠ `usages.conversation_id` 에 FK 를 걸지 않는다. SQLite 는 컬럼 제약을 추가할 때
        //   테이블을 통째로 재생성하는데, 그 경로에서 기존 인덱스·데이터를 잃을 위험이
        //   이득보다 크다. 고아 행은 아래 백필과 앱 코드가 함께 막는다.
    }

    /**
     * 이 테이블이 생기기 전 기록에도 `conversation_id` 는 이미 있다(003 에서 백필).
     * 그 id 들을 실제 대화 행으로 승격시킨다 — 안 하면 기존 사용자의 목록이 텅 빈다.
     */
    private function backfill(): void
    {
        $groups = DB::table('wisdom_agent_usages')
            ->whereNotNull('conversation_id')
            ->groupBy('conversation_id', 'user_id')
            ->get([
                'conversation_id',
                'user_id',
                DB::raw('MIN(created_at) as started_at'),
                DB::raw('MAX(created_at) as last_message_at'),
            ]);

        foreach ($groups as $group) {
            DB::table('wisdom_agent_conversations')->insert([
                'id' => $group->conversation_id,
                'user_id' => $group->user_id,
                'title' => $this->titleFor($group->conversation_id),
                'last_message_at' => $group->last_message_at,
                'created_at' => $group->started_at,
                'updated_at' => $group->last_message_at,
            ]);
        }
    }

    /**
     * 그 대화의 **첫 사용자 발화**를 제목으로 쓴다.
     * 내용 저장이 꺼져 있던 시기의 기록은 본문이 null 이라 제목도 null 이 된다.
     */
    private function titleFor(string $conversationId): ?string
    {
        $first = DB::table('wisdom_agent_usages')
            ->where('conversation_id', $conversationId)
            ->whereNotNull('user_message')
            ->orderBy('created_at')
            ->value('user_message');

        return $first === null ? null : Str::limit(trim($first), 60);
    }

    public function down(): void
    {
        Schema::dropIfExists('wisdom_agent_conversations');
    }
};
