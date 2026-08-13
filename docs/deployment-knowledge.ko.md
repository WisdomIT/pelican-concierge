# 이 서버 환경 정보

*Admin → 고급 → AI Agent 설정 → 이 서버 환경 정보*

에이전트는 서버의 상태·로그·파일·포트를 읽을 수 있습니다. 알 수 없는 것은 **당신의** 배포가
어떻게 짜여 있는가입니다 — 플레이어가 실제로 치는 주소, 라우터가 열어 둔 포트, 왜 어떤
호스트명은 되고 어떤 것은 안 되는지.

그 사실이 없으면 세상에서 가장 흔한 문의 —

> *"서버는 켜져 있다는데 아무도 못 들어와요."*

— 에 답할 수 없습니다. 로그는 멀쩡합니다. 문제가 컨테이너 **밖**에 있기 때문입니다.
에이전트는 로그를 읽고, 아무것도 못 찾고, 그렇다고 말할 뿐입니다. 이 사실들이 있으면 한 번에
원인을 짚습니다.

이 칸이 그 지식입니다. 자유 형식 글이고, **매 메시지와 함께 전송됩니다.**

---

## 여기 들어갈 것

**당신의 배포에서는 참이지만 서버 안에서는 보이지 않는** 사실들입니다.

- **플레이어가 접속하는 주소** — 형태까지. `play.example.com:27501` 이라는 사실과 "패널은
  panel.example.com 이다"는 서로 다른 정보입니다
- **밖에서 닿는 포트** — 보통 포워딩한 범위입니다. 그 범위 밖의 포트를 받은 서버는 아무리
  멀쩡해도 접속이 안 됩니다
- **특별한 포트** — 한 게임에 예약됐거나 다른 것이 쓰고 있는 포트
- **DNS 구성** — 아무도 짐작 못 할 방식으로 접속을 깨뜨릴 수 있을 때. 웹은 통과시키지만
  게임 트래픽은 막는 프록시 레코드, 지운 이름을 조용히 받아 가는 와일드카드 같은 것
- **사용자가 부딪힐 제한** — 1인당 할당량, 백업 개수 상한처럼 관리자만 풀 수 있는 것
- **놀라게 하는 자동 동작** — 야간 백업, 유휴 서버를 끄는 감시

## 여기 들어가면 안 되는 것

- **모든 종류의 자격증명.** 비밀번호·API 키·토큰. 이 글은 매 메시지마다 모델에게 갑니다.
  비밀로 남아야 하는 것은 무엇도 여기 있으면 안 됩니다
- **도구가 이미 알려주는 것** — 서버의 포트·상태·자원 사용량. 에이전트가 실시간으로 읽고,
  적어 두면 낡기만 합니다
- **개별 서버 이야기.** 이 칸은 배포에 관한 것이지 서버 하나에 관한 것이 아닙니다. 서버를
  만들거나 지울 때 바뀌는 내용은 근처에도 두지 마세요
- **긴 산문.** 매번 전송되므로 길이는 계속 나가는 비용입니다(아래 참고)

---

## 예시

패널은 HTTPS 로 열려 있고, 게임 트래픽은 한 포트 범위만 포워딩돼 있으며, 포트 하나는 예전
서버용으로 예약된 배포입니다:

```markdown
### Connection addresses

- Players connect to `play.example.com:<port>` — the port differs per server; read it with
  get_server_status
- Panel (web): `panel.example.com`
- SFTP (file transfer): `node.example.com` port 2022

### Network setup (router, DNS)

- Ports forwarded on the router: **27500-27599 (TCP and UDP)**, 25565 (reserved), 2022 (SFTP)
- A port **outside that range can never be reached from outside**, however healthy the
  server is
- `panel.*` goes through the CDN proxy; `play.*` and `node.*` are **DNS-only**.
  Game traffic (raw TCP/UDP) **cannot pass through the proxy** — DNS-only is correct for them
- Host firewall rules are bypassed by Docker's published ports, so they are never the cause
  of a connection problem

### "It's running but I can't connect" — diagnosis order

When the status is running but nobody can join, the cause is almost always **outside the
container**. Reading logs will not find it.

1. get_server_status — confirm the power state, and note the port
2. **Is the port inside 27500-27599?** If not, that is the cause, and only an admin can fix it
3. **Is the address right?** It must be `play.example.com:<port>`
4. **Does the game version match?** Minecraft shows "Outdated server/client" on a mismatch
5. **In-game password or whitelist** — the config file can be read
6. If all of that is fine it may be DNS, which the user cannot fix; point them at the admin

### Traits of this deployment

- Server resources are assigned within a per-user limit; only an admin can raise one
- Idle servers are watched — when nobody joins, the assistant speaks first and may stop the
  server
- Backups are capped at 2 per server
```

예시가 하는 일을 보세요. 네트워크를 설명하려고 쓴 문장이 하나도 없습니다. **모든 줄이 에이전트가
누군가에게 할 말을 바꾸기 때문에** 거기 있습니다.

---

## 쓸 때의 요령

**산문이 아니라 사실을 쓰세요.** 에이전트가 그대로 인용할 수 있는 짧은 줄이어야 합니다.
"27500-27599 포트가 포워딩돼 있다"는 쓸 수 있고, "우리 네트워크는 평범한 편이다"는 못 씁니다.

**실제로 받는 질문의 진단 순서를 쓰세요.** 위 예시의 절반이 그런 순서입니다. 당신의 사용자가
주로 다른 걸 묻는다면 그 순서를 쓰면 됩니다.

**관리자만 고칠 수 있는 것을 밝히세요.** 에이전트가 플레이어를 헛돌게 만드는 것을 막고,
"도와드릴 수 없습니다"와 "이건 관리자가 해야 합니다"의 차이를 만듭니다.

**고장처럼 보이지만 정상인 것을 적으세요.** 10분 걸리는 첫 기동, 기다리는 동안 반복되는 로그
같은 것들은 그 자체로 문의를 만듭니다.

**최신으로 유지하세요.** 매 메시지에 실리고 에이전트는 이 내용을 믿습니다. 낡은 한 줄은 없는
것보다 나쁩니다 — 에이전트가 그걸 확신을 담아 말하게 됩니다.

---

## 영어로 쓰면 싼 이유

이 칸은 매 메시지와 함께 전송되므로 길이가 **계속 나가는 비용**입니다. 길수록 모든 대화의 모든
차례에 청구됩니다.

같은 내용이 **영어로 쓰면 한국어보다 토큰이 약 40% 적게 듭니다.** 토크나이저가 비라틴 문자를
쪼개는 방식 때문입니다. 이 플러그인의 시스템 프롬프트와 모든 도구 설명이 영어인 이유이기도
합니다.

**답변 언어와는 무관합니다.** 답변은 각 사용자의 패널 프로필을 따릅니다 — 영어로 적어 둬도
한국어 사용자는 한국어로 답을 받습니다. 정확하게 유지할 수 있는 언어로 쓰되, 영어가 편하다면
같은 결정의 더 싼 쪽입니다.

---

## 어디에 저장되나

다른 설정과 함께 DB 에 저장됩니다. 플러그인 업데이트에도 살아남고, 그게 파일에서 옮겨 온
이유입니다. 릴리스 빌드에는 절대 포함되지 않으므로 당신의 네트워크 정보가 어디로도 실려 나가지
않습니다.
