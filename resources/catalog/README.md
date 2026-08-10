# 게임 카탈로그

`games.yaml` — 사용자에게 보이는 게임 목록과 정책. 이슈 #16.

## 왜 필요한가

Pelican 의 egg 는 **기술 정의**다 — `SRCDS_APPID`, 검증 규칙, Docker 이미지.
셀프서비스 개설 플러그인(`User Creatable Servers`)은 그 변수를 **사용자에게 그대로 노출**한다.

실측 예 (Paper egg 가 사용자에게 묻는 것):

```
BUILD_NUMBER       기본 latest      ← 노이즈
MINECRAFT_VERSION  기본 latest      ← 유일하게 의미 있음
SERVER_JARFILE     기본 server.jar  ← 노이즈
```

서버 지식이 없는 사용자에게는 이게 벽이다. 카탈로그가 그 사이를 메운다 —
**egg 는 기술 정의, 카탈로그는 사람이 읽는 정의.**

## 설계 원칙

1. **egg 를 이름으로 참조한다.** id 는 서버 재구축 때 달라진다
2. **egg 규칙은 기준선, 카탈로그가 경계.** 넓히지 않고 좁히기만 한다
   (실측: Satisfactory 의 `MAX_PLAYERS` 는 egg 규칙이 `min:1` 뿐이라 `10000` 이 통과됐다)
3. **화이트리스트 역할은 지지 않는다.** "임포트한 egg = 허용 게임" 으로 확정됐다.
   카탈로그에 없는 egg 도 Pelican 기본 폼으로는 개설된다 — 카탈로그는 UX 계층이다
4. **자원 변환표는 게임별로 따로.** 공통표는 성립하지 않는다
   (실측: 마인크래프트 실사용 968MiB vs Satisfactory 배정 16384MiB)

## 스키마

| 키 | 의미 |
|---|---|
| `egg` | Pelican egg **이름** |
| `player_var` | 인원을 반영할 egg 변수. **없는 게임이 많다**(마인크래프트·발헤임·좀보이드) → `null` |
| `sizes[]` | 인원 프리셋 → `memory`/`disk`/`cpu`. 사용자에겐 `label` 만 보인다 |
| `ask[]` | 사용자에게 **물을** 변수. egg 의 `user_editable` 중 의미 있는 것만 |
| `defaults{}` | 묻지 않고 채울 변수 |
| `caps{}` | egg 규칙이 느슨할 때 **좁히는** 상한 |
| `ports` | `count` / `contiguous` / `protocol` / `derive[]`(다른 포트를 변수에 주입) |
| `post_install[]` | **설치 완료 후** 실행. 마인크래프트 EULA 등 |
| `java_from` | 실행할 Java 를 정할 때 **마인크래프트 버전이 들어 있는 변수** 이름 (마인크래프트 계열 전용) |
| `image` | 도커 이미지를 못박는다. `java_from` 이 있으면 그쪽이 우선 |
| `mods` | 지원 여부·종류·설치 경로 |
| `secrets[]` | 시크릿 변수 이름. **빈 배열은 "확인했고 없다"** 는 뜻이다(키 누락과 구분) |
| `verified` | 실측 기록 — 설치 용량과 기동 확인 문구 |
| `install_min_mb` | **설치 완료 판정 하한**(MB, `du -sm` 기준). 실측값의 약 70% |

### `post_install` 이 필요한 이유

마인크래프트는 EULA 미동의로 **첫 기동이 조용히 실패**한다. 종료 코드가 `0` 이라
실패로 보이지도 않고, 원인은 콘솔 로그 맨 끝 한 줄에만 있다. egg 변수가 아니라
**파일**이라 `environment` 로는 해결되지 않는다.

⚠️ **설치가 끝난 시점에도 `eula.txt` 는 아직 없다.** 그 파일을 만드는 것은 설치 스크립트가
아니라 **첫 기동**이다. 그래서 "있으면 바꾼다"만으로는 아무 일도 일어나지 않고, 첫 기동이
`eula=false` 를 써놓고 그대로 꺼진다. → `file_replace` 는 **파일이 없으면 `to` 내용으로 만든다**
(에이전트 플러그인의 `PostInstallRunner`). 그래야 첫 기동부터 성공한다 (#7 에서 실측).

### 🔴 Java 버전은 egg 기본값을 믿으면 안 된다

이미지를 지정하지 않으면 `ServerCreationService` 가 egg 의 **첫 번째** 이미지를 쓴다.
그 순서는 마인크래프트 버전과 아무 상관이 없고 egg 마다 제각각이다 (실측):

아래는 **egg 가 이미지를 나열한 순서**일 뿐이다. "그 로더가 그 Java 를 쓴다"는 뜻이 아니다 —
셋 다 실제로는 마인크래프트 버전이 요구하는 Java 를 써야 한다:

| egg | 목록의 첫 이미지 | 지정 안 했을 때 (실측) |
|---|---|---|
| Paper · Forge | `java_25` | MC 1.21 이 Java 25 로 떠서 Paper 내장 spark 가 JVM 을 죽였다 |
| Fabric | `java_8` | MC 1.21 이 Java 8 로 떠서 아예 못 떴다 |

필요한 Java 는 **로더가 아니라 마인크래프트 버전**이 정한다
([Minecraft Wiki](https://minecraft.wiki/w/Tutorial:Update_Java)):

| 마인크래프트 | Java |
|---|---|
| **26.1 이상** | **25** |
| 1.20.5 ~ 1.21.11 | 21 |
| 1.18 ~ 1.20.4 | 17 |
| 1.17 ~ 1.17.1 | 17 (위키는 16 이상 — 16 은 LTS 도 아니고 EOL 이라 17 로 올려 잡았다) |
| 1.16.5 이하 | 8 |

⚠️ **2026년부터 버전 체계가 `YY.N` 으로 바뀌었다.** 26.1(2026-03-24)이 1.22 를 대체했다.
숫자로만 비교하면 `26.1 > 1.20.5` 라서, 규칙 순서를 잘못 두면 26.x 서버가 조용히 Java 21 을
받고 못 뜬다. `JavaRuntime` 이 26.x 규칙을 맨 위에 두는 이유다.

그래서 표는 카탈로그에 세 번 적지 않고 플러그인의 `JavaRuntime` 한 곳에 두었고,
카탈로그는 `java_from` 으로 **어느 변수에 버전이 들어 있는지만** 알려준다.

### 🔴 버전 선택지를 낡은 채로 두면 친구가 접속을 못 한다

**egg 는 설치할 때 API 에서 실시간으로 받아온다** — Paper 는 `fill.papermc.io/v3`,
Fabric 은 `meta.fabricmc.net`, Forge 는 `promotions_slim.json`. 즉 **egg 갱신을 기다릴 필요가
없고**, 카탈로그에 최신 버전을 적으면 그대로 설치된다.

낡은 값만 두면 친구 런처(기본이 최신)로는 **"Outdated server"** 가 뜬다. 1.21(프로토콜 767)
서버에 1.21.2 이상 클라이언트로는 못 들어간다 — 실제로 겪었다.

⚠ 버전을 올릴 때는 `validate.py` 를 반드시 다시 돌릴 것. Forge 는 접두사 충돌이 있다(위 참고).

⚠️ **요구 버전보다 높은 Java 를 쓰지 않는다.** 최신이 안전할 것 같지만 위의 Paper + Java 25 가 반례다.

### 🔴 `install_min_mb` — 설치가 조용히 실패한다

**Pelican 은 중간에 끊긴 설치도 `installed` 로 표시한다.** 상태만 보면 정상이고, 서버는
켜지지 않는다. 볼륨 용량이 사실상 유일한 단서다.

| 게임 | 정상 | 실패했을 때 |
|---|---|---|
| Necesse | 574MB | 288MB |
| ARK | 22GB | 294MB |
| Forge | 176MB | **1MB** |

하한은 실측값의 약 70% 로 잡는다. 버전이 올라가며 조금 커지거나 작아지는 것은 통과시키고,
위 같은 중단은 잡아낸다. **실측하지 않은 게임에는 넣지 않는다** — 추측한 하한은 정상 설치를
실패로 만든다. 값이 없으면 검사를 건너뛴다.

⚠ 하한은 가장 작은 `sizes.disk` 보다 작아야 한다. 아니면 정상 설치도 영원히 미달이다
(validate.py 가 검사한다). 소비자는 #7 의 재설치 재시도다.

### 🔴 Forge 버전은 다른 버전의 접두사가 되면 안 된다

Forge egg 은 promotions 목록에서 버전을 찾을 때 **exact 가 아니라 `contains()`** 로 맞춘다.

```
MC_VERSION=1.21.1  →  1.21.1-recommended · 1.21.10-recommended · 1.21.11-recommended
                      키가 3개 → 여러 줄이 된 키로 조회 → null
                      "Downloading forge version null" → 텅 빈 볼륨이 installed 로 표시
```

`1.21` 은 7개, `1.21.1` 은 3개가 잡힌다. `1.21.11` · `1.20.6` 처럼 **유일하게 잡히는 값**만
쓸 수 있다. validate.py 가 promotions 를 직접 받아 확인한다.

⚠ 마인크래프트가 새 패치를 내면 멀쩡하던 값이 갑자기 접두사가 된다(1.21.1 이 그랬다).
버전을 올릴 때마다 validate.py 를 다시 돌릴 것.

## 검증

카탈로그가 참조하는 egg 이름·변수명이 실제와 어긋나면 **개설이 조용히 깨진다**
(없는 변수는 무시되고 egg 기본값이 쓰인다). egg 는 업스트림에서 갱신되므로
**egg 임포트·갱신 후에는 반드시** 돌린다.

```bash
cd resources/catalog
docker exec pelican-panel php artisan tinker --execute='
$out=[]; foreach (\App\Models\Egg::all() as $e) { $v=[];
  foreach ($e->variables as $x) $v[]=["env_variable"=>$x->env_variable,
    "user_editable"=>(bool)$x->user_editable,"default_value"=>$x->default_value,
    "rules"=>array_values((array)$x->rules)];
  $out[]=["name"=>$e->name,"variables"=>$v]; }
file_put_contents("/tmp/eggs.json", json_encode($out));'
docker cp pelican-panel:/tmp/eggs.json ./eggs.json
python3 ../../scripts/validate-catalog.py games.yaml eggs.json
```

검사 항목 — 없는 egg / 없는 변수 / `user_editable=false` 참조(경고) /
상한 없는 숫자 변수(경고) / 인원 프리셋이 egg 상한 초과 / 자원값 0 이하.

`eggs.json` 은 덤프 산출물이라 커밋하지 않는다.

## 포트 요구를 확인하는 법

egg 의 **실행 명령**(`startup_commands`)을 보면 어떤 포트 변수가 실제로 바인드되는지 알 수 있다.
다운로드 없이 확인 가능하다.

```bash
docker exec pelican-panel php artisan tinker --execute='
$e = \App\Models\Egg::where("name","Project Zomboid")->first();
$sc = $e->startup_commands; echo is_array($sc) ? reset($sc) : $sc;'
```

실측 결과:

| 게임 | 포트 변수 | 비고 |
|---|---|---|
| Project Zomboid | `SERVER_PORT`, `STEAM_PORT` | `-port` / `-udpport` 로 **둘 다 바인드**. 기본값 16262 는 할당 범위 밖이라 반드시 덮어써야 한다 |
| Satisfactory | `SERVER_PORT`, `RELIABLE_PORT` | Reliable Messaging 은 v1.1+ 필수 |
| Core Keeper · Valheim | `SERVER_PORT` | |
| Palworld | `SERVER_PORT`, `REST_API_PORT` | REST 쿼리 포트(#31). 변수 자체는 원본 egg 에 없어 `concierge:egg-metadata` 가 보장하고, 시작 스크립트(PalworldServerConfigParser)가 env → PalWorldSettings.ini 로 옮긴다 |
| Paper · Fabric | (없음) | Pelican 이 `server.properties` 에 주입 |
| 7 Days to Die | `${SERVER_PORT}` | `{{ }}` 가 아니라 **셸 확장**을 쓴다 — 정규식으로 찾을 때 주의 |

## 익명 설치 가능 여부 (실측)

| 게임 | appid | 결과 |
|---|---|---|
| Paper · Forge · Fabric | — | ✅ 스팀 미사용 (jar 직접 다운로드) |
| Valheim | 896660 | ✅ |
| Palworld | 2394010 | ✅ |
| 7 Days to Die | 294420 | ✅ |
| Core Keeper | 1963720 | ✅ |
| Satisfactory | 1690800 | ✅ |
| **Project Zomboid** | 380870 | ❌ 익명 불가 → **개설자 본인 계정을 ask 로 받아 설치** (STEAM_PASS 는 secrets 마스킹, Steam Guard 는 질문 note 로 안내) |

검사 방법:

```bash
docker run --rm --entrypoint /bin/bash ghcr.io/parkervcp/steamcmd:debian -c '
cd /tmp && curl -sSL -o s.tar.gz https://steamcdn-a.akamaihd.net/client/installer/steamcmd_linux.tar.gz
tar -xzf s.tar.gz
./steamcmd.sh +quit >/dev/null 2>&1          # ← 부트스트랩 먼저!
timeout 90 ./steamcmd.sh +force_install_dir /tmp/t +login anonymous +app_update <APPID> validate +quit'
```

⚠️ **`+quit` 로 부트스트랩을 먼저 끝내지 않으면 오탐이 난다.** steamcmd 는 첫 실행에서
자기 바이너리를 내려받는데, 그게 실패하면 멀쩡한 앱도 `Missing configuration` 을 낸다.
실제로 Valheim 이 이렇게 오탐돼 하마터면 카탈로그에서 뺄 뻔했다.
**한 번 실패했다고 단정하지 말고 부트스트랩 후 재검사할 것.**

## 기동 검증 결과 (전수 실측)

| 게임 | 설치 | 기동 | 걸린 문제 |
|---|---|---|---|
| Paper | ✅ | ✅ | **EULA** — `eula.txt` 를 고쳐야 첫 기동 성공 |
| Core Keeper | ✅ 942MB | ✅ | 없음 |
| Valheim | ✅ ~1.7GB | ✅ | 없음 |
| Palworld | ✅ ~2GB | ✅ | 없음 (`[S_API FAIL]` 경고는 정상) |
| 7 Days to Die | ✅ ~15GB | ✅ | **포트** — `SERVER_PORT` 와 `+2` 둘 다 바인드하는데 1개만 publish |
| Satisfactory | ✅ | ✅ | 없음 |
| **Project Zomboid** | ✅ 7.1GB\* | ✅ | **스팀 자격증명** + **JVM 힙 하드코딩** |

\* 자격증명(`STEAM_USER`/`STEAM_PASS`)을 egg 변수로 추가한 뒤 성공.

### 좀보이드 — 함정이 세 겹이었다

1. **익명 설치 불가** → egg 에 `STEAM_USER`/`STEAM_PASS`/`STEAM_AUTH` 변수 추가로 해결
2. **메모리 4096 에서 OOM** (`OOMKilled=true`) → 최소 8192
3. **8192 로 올려도 OOM** — `ProjectZomboid64.json` 에 **`-Xmx8g` 가 하드코딩**돼 있다.
   JVM 이 힙만으로 8GB 를 잡으니 네이티브 메모리까지 더해 컨테이너 제한을 반드시 넘는다.
   **컨테이너 메모리를 아무리 올려도 이 파일을 안 고치면 항상 OOM 이다.**
   → 배정 메모리의 약 62% 로 낮춘다 (8192 배정 → `-Xmx5g` 로 안정 기동 확인)

3번이 특히 중요하다 — 증상이 "메모리 부족" 이라 자연히 메모리를 올리게 되는데,
**올릴수록 JVM 도 같이 커지는 게 아니라 고정 8g 라서 해결되지 않는다.**
같은 패턴이 다른 Java 게임에도 있을 수 있다.

### 좀보이드 모드 구조 (실측)

```
경로: <서버>/.cache/Server/<SERVER_NAME>.ini      ← SERVER_NAME 이 파일명이 된다
  67  Mods=            모드 ID (문자열)
  210 WorkshopItems=   스팀 워크숍 ID (숫자)
  70  Map=             맵 모드는 여기도 수정 필요
```

`Mods` 와 `WorkshopItems` 는 **서로 다른 값이고 둘 다 채워야** 한다. 워크숍 아이템 하나가
여러 모드 ID 를 담을 수 있어 1:1 대응이 아니다. 마인크래프트처럼 "파일을 폴더에 넣으면 끝" 이
아니라서 Modrinth 같은 편의 플러그인도 없다 — **에이전트가 대신 채워주기 좋은 작업**이다.

## 2차 검증 — 추가 9종 (실측)

| 게임 | 설치 | 기동 | 비고 |
|---|---|---|---|
| Terraria | ✅ 98MB | ✅ | `Server started` |
| Factorio | ✅ 296MB | ✅ | 공개 목록 등록만 계정 토큰 필요 (직접 접속은 정상) |
| Necesse | ✅ 574MB | ✅ | 첫 설치가 불완전했고 **재설치로 해결** |
| The Forest | ✅ 1.9GB | ✅ | |
| Garry's Mod | ✅ 6.7GB | ✅ | `VAC secure mode is activated` |
| Rust | ✅ 5.8GB | ✅ | RCON 포트 28016 별도 바인드 |
| ARK: Survival Evolved | ✅ 22GB | ⚠️ | 아래 참고 |
| **Don't Starve Together** | ❌ | — | `SERVER_TOKEN` 필수 (Klei 계정 토큰) |
| **FiveM** | ❌ | — | `FIVEM_LICENSE` 필수 (Cfx.re 라이선스 키) |

### 🔴 `Missing configuration` 은 간헐적 오류다 — 확정 근거로 쓰지 말 것

이번 검증에서 **가장 비싸게 배운 것.** 같은 명령이 성공하기도 실패하기도 한다:

- ARK 376030 — 익명 검사 ✅ → Pelican 설치 ❌(`Missing configuration`) → 단독 완주 ✅ 22GB
  → **Pelican 재설치 ✅ 22GB**
- Valheim 896660 — 1차 검사 ❌ → 재검사 ✅

**대응: 실패하면 무조건 재시도한다.** 한 번 실패했다고 "익명 불가" 로 단정하면 멀쩡한 게임을 뺀다.
반대로 짧은 타임아웃에서 `progress:` 만 보고 성공으로 판정해도 안 된다(ARK 오탐).
**유일하게 믿을 수 있는 신호는 `Success! App '<id>' fully installed.` 한 줄이다.**

같은 이유로 **설치가 불완전한 채 Pelican 이 `installed` 로 표시**하는 경우가 있다(Necesse, ARK).
용량이 예상보다 작으면 재설치할 것.

### ARK — 포트 4개가 필요하다

기동 후 컨테이너가 바인드한 포트:

```
27015 (QUERY_PORT)  27020 (RCON_PORT)  <할당포트> (SERVER_PORT)  <할당포트+1> (SERVER_PORT+1)
publish 된 것: SERVER_PORT 하나
```

QUERY/RCON 기본값이 할당 범위 밖이라(운영자가 UCS 에 설정한 범위) 그대로는 외부에서 안 붙는다.
게임 서버 자체는 올라오지만(메모리 5.8GB 에서 안정, 포트 바인드 완료) egg 의 RCON 래퍼가
`127.0.0.1:27020` 연결에 실패해 계속 대기한다 — 관리자 비밀번호 미설정이 원인으로 보인다.
**ARK 를 카탈로그에 넣으려면 포트 4개 배정 + RCON 설정이 선행되어야 한다.**

### 필수 변수가 외부 자격증명인 게임

`available: false` 로 두고 이유를 남긴다. 셀프서비스로 열 수 없다.

| 게임 | 필요한 것 |
|---|---|
| Project Zomboid | 게임을 소유한 스팀 계정 (`STEAM_USER`/`STEAM_PASS`) |
| Don't Starve Together | Klei 계정의 클러스터 토큰 (`SERVER_TOKEN`) |
| FiveM | Cfx.re 라이선스 키 (`FIVEM_LICENSE`) |

확인 방법 — 기본값 없는 필수 변수를 찾는다:

```bash
docker exec pelican-panel php artisan tinker --execute='
$e=\App\Models\Egg::find(11);
foreach ($e->variables as $v)
  if (in_array("required",(array)$v->rules) && $v->default_value==="") echo $v->env_variable."\n";'
```

## 🔴 콘솔에 시크릿이 평문으로 흐른다

**Pelican 콘솔은 시작 명령을 그대로 출력한다.** 따라서 시작 명령에 치환되는 변수는 전부 노출된다:

```
... +set sv_licenseKey cfxk_xxxxxxxxxx +set steam_webApiKey none ...
```

설치 스크립트는 `set -x` 가 없어 **설치 로그에는 안 나온다** — 노출은 시작 명령에 한정된다.
(좀보이드 `STEAM_PASS` 는 설치에만 쓰이므로 콘솔에 뜨지 않는다)

영향 두 가지:

1. **관리자가 대신 만들어 넘긴 서버**(좀보이드·FiveM)에서 친구가 콘솔을 열면 관리자가 넣은 값이 보인다
2. **에이전트가 콘솔 로그를 읽으면 시크릿이 모델 컨텍스트로 들어간다** (#13)

→ 카탈로그의 `secrets: []` 가 게임별 노출 대상을 선언한다. 에이전트는 로그를 모델에 넘기기 전에
**값 기준으로** 마스킹한다(변수명 패턴만 보면 놓친다). `validate.py` 가 누락을 경고한다.

## ARK 기동 확인은 로그가 아니라 RCON 으로

ARK egg 는 RCON 이 붙을 때까지 `waiting for rcon connection...` 을 반복 출력한다.
**이건 로딩 중 정상 출력**이고 첫 기동에 10분 이상 걸린다. 컨테이너 stdout 만 보면
영영 안 켜진 것처럼 보인다. 판단은 RCON 을 직접 찔러서 한다:

```bash
docker exec <uuid> rcon -t rcon -a 127.0.0.1:${RCON_PORT} -p <ARK_ADMIN_PASSWORD> ListPlayers
#  "No Players Connected" 가 오면 완전히 기동된 것이다
```

같은 이유로 **게임 자체 로그 파일 경로를 추측하지 말 것.** ARK 는 `ShooterGame/Saved/Logs/` 에
로그를 남기지 않았다(실측). 상태 확인은 RCON·포트·메모리 안정 여부로 한다.

## 게임을 추가하기 전에 확인할 것

**egg 가 있다고 설치가 되는 것은 아니다.** 좀보이드에서 실측으로 확인했다 —
egg 는 정상이고 임포트도 되지만, steamcmd 가 익명 로그인으로 게임을 받지 못한다:

```
content_log : Failed installing AppID 380870 (Missing configuration)
appinfo_log : Requested 67 app access tokens, 0 received, 67 denied
```

게임을 소유한 스팀 계정이 필요한데 **egg 에 `STEAM_USER`/`STEAM_PASS` 변수가 없다**
(설치 스크립트는 참조하지만 빈 값으로 확장된다). 이런 게임은 `available: false` 로 두고
이유를 남긴다 — 에이전트가 "왜 이 게임은 없어?" 에 답할 수 있어야 한다.

**새 게임을 카탈로그에 넣기 전에 실제로 한 번 개설해볼 것.** 최소 확인 항목:

1. 설치가 완료되는가 (`steamapps` 에 게임 파일이 생기는가)
2. 서버가 기동되는가 (마인크래프트 EULA 처럼 첫 기동이 막히는 게 있다)
3. 필요한 포트 수 — 컨테이너의 실제 리스닝 포트로 확인

## 알려진 구조적 제약

**플러그인은 할당을 1개만 배정한다** (`allocation_limit` 기본 0, 실측 확인).
포트가 2개 이상 필요한 게임(좀보이드, Satisfactory)은 개설 대행 함수(#7)가
두 번째 할당을 직접 붙여야 한다. 안 그러면 기본값 포트로 남아 publish 되지 않는다.

## 미완

- 좀보이드 워크숍 모드 지정 방식 조사
- 7 Days to Die 포트 개수 — 실행 명령은 `SERVER_PORT` 하나뿐이지만 통상 +1/+2 를 UDP 로
  함께 쓴다고 알려져 있다. `TELNET_PORT`(8081) 외부 할당 필요 여부도 미확인.
  실제 개설 후 컨테이너 리스닝 포트로 확정할 것
- Satisfactory `caps.MAX_PLAYERS: 16` 은 임의값 — 실사용 부하를 보고 조정
