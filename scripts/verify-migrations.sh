#!/usr/bin/env bash
#
# 마이그레이션 체인을 Pelican 이 지원하는 DB 전부에서 새로 돌린다 (#106).
#
# 왜 필요한가: SQLite 는 다른 셋이 거절하는 것을 대부분 조용히 받아준다. 외래키의 컬럼
# 타입이 맞는지 따지지 않는 것이 대표적인데, 그 하나 때문에 이 플러그인은 MySQL·MariaDB·
# PostgreSQL 어디에도 설치되지 않은 채로 배포돼 있었다. 읽어서는 안 잡히고, 돌려야 잡힌다.
#
# 무엇을 하는가: 엔진마다 빈 DB 를 세우고 → 패널 스키마를 올리고 → 플러그인 마이그레이션을
# 호스트와 **같은 경로**(PluginService::runPluginMigrations)로 돌린 뒤 → 결과를 확인한다.
#
# 필요한 것: docker, 그리고 실행 중인 패널 컨테이너 하나(코드와 vendor 를 빌려 쓴다).
#
#   bash scripts/verify-migrations.sh
#   PANEL=my-panel-container bash scripts/verify-migrations.sh
#
set -u

PANEL="${PANEL:-pelican-panel}"
PLUGIN="${PLUGIN:-concierge}"
PREFIX="cgverify"
FAILED=0

NET=$(docker inspect "$PANEL" --format '{{range $k,$v := .NetworkSettings.Networks}}{{$k}}{{end}}' 2>/dev/null)
if [ -z "$NET" ]; then
  echo "패널 컨테이너 '$PANEL' 을 찾을 수 없다. PANEL=<이름> 으로 알려줄 것." >&2
  exit 1
fi

cleanup() { docker rm -f "$PREFIX-mysql" "$PREFIX-mariadb" "$PREFIX-pgsql" >/dev/null 2>&1; }
trap cleanup EXIT

echo "DB 세 개를 띄운다 (네트워크: $NET) …"
docker run -d --rm --name "$PREFIX-mysql"   --network "$NET" -e MYSQL_ROOT_PASSWORD=x  -e MYSQL_DATABASE=panel   mysql:8      >/dev/null
docker run -d --rm --name "$PREFIX-mariadb" --network "$NET" -e MARIADB_ROOT_PASSWORD=x -e MARIADB_DATABASE=panel mariadb:11  >/dev/null
docker run -d --rm --name "$PREFIX-pgsql"   --network "$NET" -e POSTGRES_PASSWORD=x    -e POSTGRES_DB=panel      postgres:16  >/dev/null

for _ in $(seq 1 60); do
  docker exec "$PREFIX-mysql" mysqladmin ping -px --silent  >/dev/null 2>&1 &&
  docker exec "$PREFIX-mariadb" mariadb-admin ping -px --silent >/dev/null 2>&1 &&
  docker exec "$PREFIX-pgsql" pg_isready -U postgres >/dev/null 2>&1 && break
  sleep 2
done

run_engine() {
  local name="$1"; shift
  local env_args=("$@")

  echo "═══ $name ═══"

  # 1) 패널 스키마만. --path 를 주지 않으면 설치된 다른 플러그인의 마이그레이션까지
  #    섞여 들어오고, 그것들은 이름이 `001_…` 이라 패널의 `2016_…` 보다 먼저 정렬돼
  #    아직 없는 servers 를 참조하다 죽는다.
  if ! docker exec "${env_args[@]}" "$PANEL" php artisan migrate --force --path=database/migrations >/dev/null 2>&1; then
    echo "  ✗ 패널 마이그레이션 실패"; FAILED=1; return
  fi
  echo "  ✓ 패널 마이그레이션"

  # 2) 플러그인 마이그레이션 — 호스트가 쓰는 그 경로로.
  local out
  out=$(docker exec "${env_args[@]}" "$PANEL" php artisan tinker --execute='
    use Illuminate\Support\Facades\{DB, Schema};
    try {
        app(\App\Services\Helpers\PluginService::class)
            ->runPluginMigrations(\App\Models\Plugin::query()->where("id", "'"$PLUGIN"'")->first());
    } catch (\Throwable $e) {
        echo "  ✗ 플러그인 마이그레이션: " . substr($e->getMessage(), 0, 160) . PHP_EOL;
        return;
    }
    echo "  ✓ 플러그인 마이그레이션" . PHP_EOL;

    $want = ["settings","usages","tool_calls","conversations","install_checks","idle_watches","backup_watches","games","presets"];
    $missing = array_values(array_filter($want, fn ($t) => !Schema::hasTable("concierge_" . $t)));
    echo $missing
        ? "  ✗ 없는 테이블: " . implode(", ", $missing) . PHP_EOL
        : "  ✓ concierge_* 테이블 " . count($want) . "개" . PHP_EOL;

    // 옛 이름으로 끝나면 015·016 의 이름 변경이 도중에 멎었다는 뜻이다.
    $legacy = array_values(array_filter(["wisdom_agent_", "wisdom_ai_assistant_"], fn ($p) => Schema::hasTable($p . "backup_watches")));
    echo $legacy ? "  ✗ 옛 이름이 남음: " . implode(", ", $legacy) . PHP_EOL : "  ✓ 옛 이름 잔재 없음" . PHP_EOL;

    // 🔴 외래키가 실제로 붙었는지 본다. SQLite 에서는 타입이 틀려도 붙지만 나머지는 거절한다.
    $fks = collect(Schema::getForeignKeys("concierge_backup_watches"))->pluck("foreign_table")->sort()->values();
    echo $fks->count() === 2
        ? "  ✓ 외래키 → " . $fks->implode(", ") . PHP_EOL
        : "  ✗ 외래키가 " . $fks->count() . "개뿐 (" . $fks->implode(", ") . ")" . PHP_EOL;

    // 시드가 실제로 들어갔는지 — 마이그레이션이 통과해도 시드는 조용히 빌 수 있다.
    $presets = DB::table("concierge_presets")->count();
    $games = DB::table("concierge_games")->count();
    echo ($presets > 0 && $games > 0)
        ? "  ✓ 시드: 시작점 {$presets}개 · 카탈로그 {$games}개" . PHP_EOL
        : "  ✗ 시드 비었음: 시작점 {$presets}개 · 카탈로그 {$games}개" . PHP_EOL;
  ' 2>&1 | grep -E "^  [✓✗]")

  echo "$out"
  echo "$out" | grep -q "✗" && FAILED=1
}

run_engine "MySQL 8"       -e DB_CONNECTION=mysql   -e DB_HOST="$PREFIX-mysql"   -e DB_PORT=3306 -e DB_DATABASE=panel -e DB_USERNAME=root     -e DB_PASSWORD=x
run_engine "MariaDB 11"    -e DB_CONNECTION=mariadb -e DB_HOST="$PREFIX-mariadb" -e DB_PORT=3306 -e DB_DATABASE=panel -e DB_USERNAME=root     -e DB_PASSWORD=x
run_engine "PostgreSQL 16" -e DB_CONNECTION=pgsql   -e DB_HOST="$PREFIX-pgsql"   -e DB_PORT=5432 -e DB_DATABASE=panel -e DB_USERNAME=postgres -e DB_PASSWORD=x

# SQLite 는 **빈 파일**로 새로 만든다 — 운영 DB 를 쓰면 이미 기록된 마이그레이션을
# 건너뛰어 아무것도 검증하지 못한다. 새 설치를 보는 것이 목적이다.
docker exec "$PANEL" sh -c 'rm -f /tmp/cg-verify.sqlite && touch /tmp/cg-verify.sqlite'
run_engine "SQLite (새 파일)" -e DB_CONNECTION=sqlite -e DB_DATABASE=/tmp/cg-verify.sqlite
docker exec "$PANEL" rm -f /tmp/cg-verify.sqlite

echo
[ "$FAILED" -eq 0 ] && echo "✅ 네 엔진 모두 통과." || echo "❌ 실패한 엔진이 있다 (위 ✗ 참고)."
exit "$FAILED"
