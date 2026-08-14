<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 백업·복원 완료 감시 (#36).
 *
 * ⚠ 도구가 "끝나면 알려드립니다"라고 약속한다. 약속을 지킬 수단이 없으면 그런 말을 하게
 *   두면 안 된다 — 설치 검증(#7)과 같은 이유로 상태를 DB 에 남긴다. 브라우저를 닫아도
 *   다음에 열었을 때 알림이 간다.
 *
 * 🔴 **server_id 는 unsignedInteger 다. foreignId() 를 쓰면 안 된다** (#106).
 *    패널의 servers·users 는 `increments('id')` 로 만들어져 INT UNSIGNED 인데
 *    foreignId() 는 BIGINT UNSIGNED 를 낸다. SQLite 는 외래키의 타입을 따지지 않아
 *    아무 일도 없지만, MySQL·MariaDB 는 errno 3780 으로 거절하고 PostgreSQL 도
 *    타입이 다르면 받지 않는다. 이 플러그인이 SQLite 밖에서 설치되지 않았던 이유다.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 🔴 실패한 앞선 시도가 남긴 껍데기를 치운다 (#106).
        //
        // MySQL 은 DDL 에 트랜잭션이 없다. 예전 이 마이그레이션은 CREATE 는 통과하고
        // 뒤따르는 외래키 ALTER 에서 죽었는데, 그러면 **테이블은 남고 기록은 남지
        // 않는다** — 다음 설치는 같은 자리에서 "이미 있다"로 막히고, 되돌리기도
        // 기록이 없어 손대지 못한다. 그 상태의 패널이 스스로 낫도록 여기서 치운다.
        //
        // ⚠ 지우는 것이 안전한 이유: 이 코드가 도는 것은 곧 이 마이그레이션이 기록되지
        //   않았다는 뜻이고, 기록되지 않았다면 그 설치는 끝까지 간 적이 없다. 플러그인이
        //   한 번도 뜨지 못했으니 이 테이블에 담긴 것도 없다.
        Schema::dropIfExists('wisdom_agent_backup_watches');

        Schema::create('wisdom_agent_backup_watches', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('server_id');
            // 백업 uuid. 복원 감시는 특정 백업이 아니라 서버 상태를 보므로 비어 있을 수 있다.
            $table->string('backup_uuid')->nullable();
            $table->string('kind')->default('backup');   // backup | restore
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->index(['notified_at']);
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wisdom_agent_backup_watches');
    }
};
