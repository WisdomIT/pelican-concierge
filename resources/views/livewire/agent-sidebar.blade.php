{{--
    ⚠ 1) Tailwind 유틸리티 클래스를 쓰면 안 된다.
      이 파일은 Pelican 의 Tailwind 빌드 대상(content 경로)이 **아니라서**, 컴파일된 CSS 에
      이미 들어 있지 않은 클래스는 그냥 무시된다. 실제로 text-white·rounded-lg 는 있지만
      bg-primary-600·empty:hidden 은 없어서 "흰 글자에 배경 없음" / "빈 말풍선이 안 사라짐"이 났다.
      → 자체 클래스 + Filament 가 런타임에 정의하는 CSS 변수를 쓴다.
        (--primary-600, --gray-* 는 패널이 <style> 로 주입한다 → 테마를 바꿔도 따라간다)

    ⚠ 2) <style> 은 반드시 루트 <div> **안에** 둔다.
      Livewire 컴포넌트는 루트 요소가 하나여야 한다. 밖에 두면 Livewire 가 <style> 을 루트로
      잡아(wire:id 가 style 태그에 붙는다) 실제 화면이 두 번째 루트가 되고, 갱신 때 통째로
      버려진다 — 메시지를 보내면 새로고침한 것처럼 화면이 비워지고 아무 것도 기록되지 않는다.
      **오류 로그가 남지 않아** 원인을 찾기 어렵다.
      확인: 렌더된 HTML 에서 wire:snapshot 을 단 태그가 div 여야 한다.

    ⚠ 3) 이 파일은 **모든 페이지**에 렌더된다(BODY_END 렌더 훅).
      선택자를 넓게 잡으면 패널 전체가 망가진다. 전부 `.wa-` 로 시작하는 자체 클래스를 쓰고,
      바깥으로 나가는 규칙은 본문을 밀어내는 `html.cg-open body` 하나뿐이다.
--}}
{{--
    ⚠ **열림 여부는 서버가 아니라 브라우저에 둔다.**
      Livewire 프로퍼티로 두면 `wire:navigate` 이동은 `@persist` 덕에 버티지만,
      **전체 리로드에서는 컴포넌트가 새로 마운트되어 기본값으로 돌아간다.** 서버 콘솔 페이지가
      바로 그 경우다(SPA 예외) — 콘솔을 떠나는 순간 사이드바가 닫혔다. localStorage 에 두면
      두 경우 모두 유지되고, 토글할 때마다 서버를 왕복하지도 않는다.

    ⚠ `cg-open` 클래스는 **이동할 때마다 다시 붙여야 한다.**
      본문을 미는 규칙은 `<html>` 의 클래스로 걸리는데, `wire:navigate` 는 이동한 페이지의
      `<html>` 속성으로 갈아치우므로 그 클래스가 날아간다. 그런데 사이드바는 `@persist` 로
      살아남아 **다시 초기화되지 않으므로** `x-effect` 는 다시 돌지 않는다(`open` 이 바뀐 게
      아니다). → `livewire:navigated` 에서 직접 다시 붙인다.
      (첫 그림에서의 깜빡임은 `sidebar-mount.blade.php` 의 인라인 스크립트가 막는다)
--}}
<div
    class="cg-root"
    x-data="{
        history: false,
        open: localStorage.getItem('cg-open') === '1',
        apply() { document.documentElement.classList.toggle('cg-open', this.open) },
    }"
    x-effect="localStorage.setItem('cg-open', open ? '1' : '0'); apply();
              document.documentElement.classList.toggle('cg-unread', ! open && $wire.unread);
              if (open && $wire.unread) $wire.markRead()"
    x-on:livewire:navigated.document="apply()"
    {{-- 트리거(#7)는 패널 크롬에 있어 이 컴포넌트 밖이다 — window 이벤트로 받는다. --}}
    x-on:concierge-toggle.window="open = ! open"
    {{-- 에이전트가 먼저 말을 거는 통로. 설치는 몇 분 걸리므로 사용자가 물을 때까지 기다리면
         늦는다. 평소 30초면 충분하고, **진행 중인 서버가 있을 때만** 5초로 당긴다 —
         켜지는 걸 지켜보는 중에 30초는 멈춘 것처럼 보인다. --}}
    wire:poll.{{ $this->watching ? '5s' : '30s' }}="checkNotices"
>
    <style>
    /* Alpine 이 붙기 전 한 프레임 동안 펼쳐진 목록이 보이는 것을 막는다.
       Tailwind 빌드에 기대지 않고 직접 선언한다(파일 머리말 1번 참고). */
    [x-cloak] { display: none !important; }

    /* ── 사이드바 껍데기 ──

       ⚠ 폭 변수는 **`:root`(= html)에 둬야 한다.** CSS 커스텀 속성은 아래로만 상속된다.
         `.cg-root` 에 두면 그 자손만 보게 되는데, 본문을 미는 규칙의 대상인 `body` 는
         `.cg-root` 의 **조상**이라 값을 못 본다 → `var(--cg-w)` 가 무효가 되어 padding 이
         0 으로 떨어지고, 사이드바가 항상 본문을 덮는 것처럼 보인다. 실제로 그렇게 틀렸다. */
    :root { --cg-w: 24rem; }

    /* 펼치면 본문을 밀어낸다. 덮어버리면 콘솔 로그를 보면서 물어볼 수가 없는데,
       그게 이 사이드바를 만든 이유다. Filament 의 topbar 는 sticky(고정 아님)라 함께 밀린다. */
    html.cg-open body {
        padding-right: var(--cg-w);
        transition: padding-right .2s ease;
    }
    /* 좁은 화면에서는 밀 자리가 없다 → 덮는다. */
    @media (max-width: 1023px) { html.cg-open body { padding-right: 0; } }

    /* 드래그로 폭 조절 중(#9) — 여닫이용 padding transition 이 켜져 있으면 페이지가
       손잡이보다 늦게 따라와 출렁인다. 조절하는 동안만 끈다. 텍스트 선택도 막는다. */
    html.cg-resizing body { transition: none; }
    html.cg-resizing { user-select: none; }


    .cg-panel {
        position: fixed; inset: 0 0 0 auto; z-index: 30;
        display: flex; flex-direction: column;
        width: var(--cg-w); max-width: 100vw;
        padding: 1rem;
        /* 순백(사용자 요청) — gray-50 은 살짝 회색빛이라 패널 좌측 내비게이션(테마가
           #fff 로 칠함)과 미묘하게 어긋났다. 다크 모드는 아래 오버라이드 그대로. */
        background: #fff;
        border-left: 1px solid var(--gray-200, #e5e7eb);
    }
    :where(.dark) .cg-panel {
        background: var(--gray-950, #030712);
        border-color: var(--gray-800, #1f2937);
    }

    /* 폭 조절 손잡이(#9) — 패널 왼쪽 가장자리 전체. VS Code 채팅 독과 같은 조작감.
       경계선을 기준으로 절반은 본문 쪽, 절반은 패널 쪽에 걸친다(사용자 요청) —
       선 위에서 잡는 느낌이 나고, 패널 안쪽 콘텐츠도 덜 가린다. */
    .cg-resize {
        position: absolute; inset: 0 auto 0 -.25rem;
        width: .5rem;
        cursor: col-resize;
        /* 포인터 드래그가 스크롤 제스처로 새면 안 된다. */
        touch-action: none;
    }
    .cg-resize:hover,
    .cg-resize:focus-visible,
    .cg-resize.is-dragging {
        background: color-mix(in oklab, var(--primary-600) 35%, transparent);
    }
    .cg-resize:focus-visible { outline: none; }
    /* 1024px 아래는 오버레이 모드(위 media) — 밀어낼 본문이 없으니 조절 자체를 없앤다. */
    @media (max-width: 1023px) { .cg-resize { display: none; } }

    /* 대화 로그만 늘어나고 머리말·입력은 제자리에 있어야 한다. */
    .cg-chat { display: flex; flex-direction: column; gap: 1rem; min-height: 0; flex: 1 1 auto; }
    {{-- column-reverse 가 스크롤 좌표계를 뒤집는다: **scrollTop 0 = 바닥**. 첫 렌더든
         @persist 재부착이든 bfcache 복원이든, 브라우저가 스크롤을 리셋하면 그 0 이
         곧 최근 대화다 — "바닥으로 맞출 순간"을 쫓을 필요가 없어진다(#84).
         바닥(0)에 있는 동안은 내용이 늘어도 브라우저가 알아서 붙잡아 두고, 위로
         올렸다면(음수) 그 자리를 보존한다 — 둘 다 네이티브 동작이다. --}}
    .cg-scroll { flex: 1 1 auto; min-height: 0; overflow-y: auto; display: flex; flex-direction: column-reverse; }
    .cg-scroll > .cg-log { flex-shrink: 0; }

    .cg-log { display: flex; flex-direction: column; gap: .75rem; }

    .cg-bubble {
        max-width: 100%;
        border-radius: .75rem;
        padding: .7rem 1rem;
        font-size: .875rem;
        line-height: 1.65;
        overflow-wrap: anywhere;
    }
    /* 스트리밍 목적지는 항상 DOM 에 있어야 해서, 비어 있을 때만 감춘다.
       :empty 는 공백 문자도 내용으로 치므로 아래 div 안에 줄바꿈을 넣지 말 것. */
    .cg-bubble:empty { display: none; }

    .cg-user {
        align-self: flex-end;
        background: var(--primary-600);
        /* 커스텀 색(#10)이 밝으면 흰 글자가 안 읽힌다 — 서버가 밝기로 골라 준다. */
        color: var(--cg-on-primary, #fff);
        white-space: pre-wrap;
    }
    .cg-agent {
        align-self: flex-start;
        background: var(--gray-100, #f3f4f6);
        color: var(--gray-900, #111827);
    }
    :where(.dark) .cg-agent {
        background: var(--gray-800, #1f2937);
        color: var(--gray-100, #f3f4f6);
    }

    .cg-hint { font-size: .875rem; color: var(--gray-500, #6b7280); }

    /* ── 화면 이동 버튼 ── */
    .cg-links { display: flex; flex-wrap: wrap; gap: .4rem; align-self: flex-start; }
    .cg-link {
        display: inline-block;
        max-width: 100%;
        padding: .35rem .7rem;
        border-radius: .5rem;
        border: 1px solid var(--primary-600);
        color: var(--primary-600);
        font-size: .8125rem; font-weight: 600;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .cg-link:hover { background: color-mix(in oklab, var(--primary-600) 12%, transparent); }

    /* ── 마크다운 ── */
    .cg-md > :first-child { margin-top: 0; }
    .cg-md > :last-child { margin-bottom: 0; }
    .cg-md p { margin: .6em 0; }
    .cg-md ul, .cg-md ol { margin: .6em 0; padding-left: 1.4em; }
    .cg-md ul { list-style: disc; }
    .cg-md ol { list-style: decimal; }
    .cg-md li { margin: .25em 0; }
    .cg-md li > ul, .cg-md li > ol { margin: .25em 0; }
    .cg-md strong { font-weight: 600; }
    .cg-md em { font-style: italic; }
    .cg-md h1, .cg-md h2, .cg-md h3, .cg-md h4 {
        margin: 1em 0 .5em; font-weight: 600; line-height: 1.3;
    }
    .cg-md h1 { font-size: 1.25em; }
    .cg-md h2 { font-size: 1.15em; }
    .cg-md h3, .cg-md h4 { font-size: 1.05em; }
    .cg-md a { text-decoration: underline; }
    .cg-md code {
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-size: .875em;
        padding: .1em .35em;
        border-radius: .3rem;
        background: color-mix(in oklab, currentColor 12%, transparent);
    }
    /* 넓은 코드는 말풍선을 밀지 않고 스스로 스크롤한다. */
    .cg-md pre {
        margin: .6em 0;
        padding: .7rem .85rem;
        border-radius: .5rem;
        overflow-x: auto;
        background: color-mix(in oklab, currentColor 8%, transparent);
    }
    .cg-md pre code { padding: 0; background: none; }
    .cg-md blockquote {
        margin: .6em 0;
        padding-left: .8em;
        border-left: 3px solid color-mix(in oklab, currentColor 25%, transparent);
    }
    .cg-md table { display: block; overflow-x: auto; border-collapse: collapse; margin: .6em 0; }
    .cg-md th, .cg-md td {
        border: 1px solid color-mix(in oklab, currentColor 20%, transparent);
        padding: .3em .6em;
    }
    .cg-md hr {
        margin: 1em 0; border: 0;
        border-top: 1px solid color-mix(in oklab, currentColor 20%, transparent);
    }

    /* ── 확인 카드 ── */
    .cg-card {
        align-self: flex-start;
        max-width: 100%;
        border: 1px solid var(--gray-300, #d1d5db);
        border-radius: .75rem;
        padding: .9rem 1rem;
        /* 패널과 같은 순백 — 구분은 테두리가 한다. */
        background: #fff;
        font-size: .875rem;
    }
    :where(.dark) .cg-card { border-color: var(--gray-700, #374151); background: var(--gray-900, #111827); }
    .cg-card.is-danger { border-color: var(--danger-500, #ef4444); }

    .cg-card-title { font-weight: 600; margin-bottom: .6rem; }
    .cg-card dl { display: grid; grid-template-columns: auto 1fr; gap: .25rem .8rem; margin: 0 0 .7rem; }
    .cg-card dt { color: var(--gray-500, #6b7280); }
    .cg-card dd { margin: 0; }
    .cg-card-input-label {
        display: block;
        margin: .5rem 0 .25rem;
        font-size: .75rem;
        color: var(--gray-500, #6b7280);
    }
    .cg-card-input {
        display: block; width: 100%;
        margin-top: .25rem;
        padding: .4rem .55rem;
        font-size: .8125rem;
        border: 1px solid var(--gray-300, #d1d5db);
        border-radius: .45rem;
        background: transparent;
        color: inherit;
    }
    :where(.dark) .cg-card-input { border-color: var(--gray-700, #374151); }

    .cg-card-note {
        margin: 0 0 .7rem;
        font-size: .8125rem;
        color: var(--danger-600, #dc2626);
    }
    .cg-card-actions { display: flex; gap: .5rem; }

    .cg-diff {
        margin: 0 0 .7rem;
        border-radius: .5rem;
        overflow-x: auto;
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-size: .75rem;
        line-height: 1.6;
        background: color-mix(in oklab, currentColor 6%, transparent);
    }
    .cg-diff > div { padding: .15rem .6rem; white-space: pre; }
    .cg-diff-del { color: var(--danger-600, #dc2626); }
    .cg-diff-add { color: var(--success-600, #16a34a); }

    /* 카드 실행/취소 안내 — 대화가 아니라 화면 표시다. 가운데 작게. */
    .cg-event {
        align-self: center;
        font-size: .75rem;
        color: var(--gray-500, #6b7280);
    }

    /* ── 확정된 카드(#6) ──
       버튼 없이 결과 배지를 단 채 대화에 남는다 — 카드가 보여준 요약이 곧
       "무엇이 실행됐는가"의 기록이다. 살짝 가라앉혀 진행 중 카드와 구분한다. */
    .cg-card.is-resolved { opacity: .8; }
    .cg-card-head { display: flex; align-items: center; gap: .5rem; margin-bottom: .6rem; }
    .cg-card-head .cg-card-title { margin-bottom: 0; flex: 1 1 auto; }
    .cg-card-outcome {
        flex: 0 0 auto;
        font-size: .6875rem; font-weight: 600;
        padding: .1rem .5rem; border-radius: 999px;
        color: var(--gray-500, #6b7280);
        background: color-mix(in oklab, currentColor 12%, transparent);
    }
    .cg-card-outcome.is-approved {
        color: var(--success-600, #16a34a);
        background: color-mix(in oklab, var(--success-600, #16a34a) 12%, transparent);
    }

    /* 실행된 액션 뒤의 구간 경계(#6) — 기록 패널의 구간 항목이 여기로 이동한다. */
    .cg-boundary {
        align-self: stretch;
        border-top: 1px dashed color-mix(in oklab, currentColor 30%, transparent);
        margin: .4rem 0;
    }

    /* ── 대화 목록 ──
       ⚠ 기록 패널은 **흐름 안에 두지 않는다.** flex 컬럼의 자식으로 두면 채팅이 길 때
       flex 축소가 max-height 보다 먼저 걸려 찌그러지고, 축소된 높이에서는 overflow 도
       의도대로 안 돼 스크롤바가 안 보였다(#28, 실측). 오버레이로 띄우면 채팅 영역과
       아예 경쟁하지 않는다. */
    .cg-head-wrap { position: relative; }
    .cg-head {
        display: flex; align-items: center; gap: .5rem;
        padding-bottom: .75rem;
        border-bottom: 1px solid var(--gray-200, #e5e7eb);
    }
    :where(.dark) .cg-head { border-color: var(--gray-800, #1f2937); }
    .cg-head-title {
        flex: 1 1 auto; min-width: 0;
        font-size: .875rem; font-weight: 600;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }

    .cg-history {
        position: absolute;
        top: calc(100% + .5rem); left: 0; right: 0;
        z-index: 10;
        /* ⚠ **flex 를 쓰지 않는다.** flex 컬럼은 높이가 제한되면 overflow 를 내기 전에
           **자식부터 수축시킨다**(flex-shrink 기본 1) — 아이템이 다 찌그러진 뒤에야
           스크롤바가 생겼다(#28 실측, 세로가 작은 화면). 아이템이 block+width:100% 라
           일반 block 흐름이면 자연 높이가 유지되고 overflow 가 곧바로 동작한다. */
        display: block;
        /* 목록이 아무리 길어도 사이드바 안에서 스크롤한다. 30개(목록 상한) 기준 검증. */
        max-height: min(60vh, 24rem);
        overflow-y: auto;
        scrollbar-width: thin;
        border: 1px solid var(--gray-200, #e5e7eb);
        border-radius: .625rem;
        padding: .25rem;
        /* 오버레이는 아래가 비쳐 보이면 안 된다 — 패널과 같은 불투명 배경 + 그림자. */
        background: #fff;
        box-shadow: 0 8px 24px rgb(0 0 0 / .18);
    }
    :where(.dark) .cg-history {
        border-color: var(--gray-800, #1f2937);
        background: var(--gray-950, #030712);
    }
    .cg-history-item {
        /* 항목 내부의 가로 정렬만 flex — 목록 컨테이너는 block 이어야 한다(위 주석). */
        display: flex; align-items: baseline; gap: .5rem;
        width: 100%;
        padding: .45rem .6rem;
        border-radius: .45rem;
        font-size: .8125rem;
        text-align: left;
        color: var(--gray-700, #374151);
    }
    .cg-history-name {
        flex: 1 1 auto; min-width: 0;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .cg-history-when {
        flex: 0 0 auto;
        font-size: .6875rem;
        color: var(--gray-400, #9ca3af);
    }
    /* 기록 아이콘 위 배지 — 목록을 열기 전에도 미읽음이 있음을 알린다(#29). */
    .cg-iconwrap { position: relative; display: inline-flex; }
    .cg-history-badge {
        position: absolute; top: 1px; right: 1px;
        width: .45rem; height: .45rem; border-radius: 50%;
        background: var(--danger-500, #ef4444);
        pointer-events: none;
    }

    /* 다른 대화에 새 알림이 도착했다는 표시(#29). 열면 꺼진다. */
    .cg-history-dot {
        flex: 0 0 auto;
        width: .45rem; height: .45rem; border-radius: 50%;
        background: var(--danger-500, #ef4444);
        align-self: center;
    }
    :where(.dark) .cg-history-item { color: var(--gray-300, #d1d5db); }
    .cg-history-item:hover { background: color-mix(in oklab, currentColor 10%, transparent); }
    .cg-history-item.is-active {
        background: color-mix(in oklab, var(--primary-600) 15%, transparent);
        font-weight: 600;
    }
    .cg-history-empty { padding: .45rem .6rem; font-size: .8125rem; color: var(--gray-500, #6b7280); }

    /* 삭제 버튼(#8) — 항상 보이되 흐리게. hover 로만 드러내면 터치 화면에서 누를 수 없다. */
    .cg-history-row { display: flex; align-items: stretch; gap: .1rem; }
    .cg-history-row > .cg-history-item { flex: 1 1 auto; min-width: 0; }
    .cg-history-del {
        flex: 0 0 auto;
        display: inline-flex; align-items: center;
        padding: 0 .4rem;
        border-radius: .45rem;
        color: var(--gray-400, #9ca3af);
        opacity: .45;
    }
    .cg-history-del:hover {
        opacity: 1;
        color: var(--danger-500, #ef4444);
        background: color-mix(in oklab, var(--danger-500, #ef4444) 10%, transparent);
    }
    .cg-history-del-icon { width: 1rem; height: 1rem; }

    /* ── 진행 중 카드 ── */
    .cg-watch {
        border: 1px solid var(--gray-200, #e5e7eb);
        border-radius: .625rem;
        padding: .5rem .7rem;
        font-size: .8125rem;
    }
    :where(.dark) .cg-watch { border-color: var(--gray-800, #1f2937); }
    .cg-watch-title { color: var(--gray-500, #6b7280); font-size: .75rem; margin-bottom: .3rem; }
    .cg-watch-row { display: flex; align-items: center; gap: .5rem; }
    .cg-watch-name {
        flex: 1 1 auto; min-width: 0;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        font-weight: 600;
    }
    .cg-watch-state { display: inline-flex; align-items: center; gap: .35rem; color: var(--gray-500, #6b7280); }
    .cg-spinner {
        width: .7rem; height: .7rem; border-radius: 50%;
        border: 2px solid color-mix(in oklab, currentColor 30%, transparent);
        border-top-color: var(--primary-600);
        animation: cg-spin .8s linear infinite;
    }
    @keyframes cg-spin { to { transform: rotate(360deg); } }

    /* ── 입력 ── */
    .cg-form { display: flex; align-items: flex-end; gap: .5rem; }

    /* 한도 게이지(#4) — 입력창 위의 가는 줄 하나. 80% 경고색, 초과 위험색.
       음수 마진: .cg-chat 의 gap(1rem)이 그대로 벌리면 입력창과 남남처럼 보인다. */
    .cg-limit { display: flex; align-items: center; gap: .5rem; margin-bottom: -.65rem; }
    .cg-limit-bar {
        flex: 1 1 auto; height: 4px; border-radius: 999px; overflow: hidden;
        background: color-mix(in oklab, currentColor 12%, transparent);
    }
    .cg-limit-fill { height: 100%; border-radius: 999px; background: var(--primary-600, #4f46e5); }
    .cg-limit-text { flex: 0 0 auto; font-size: .6875rem; color: var(--gray-500, #6b7280); }
    .cg-limit.is-warn .cg-limit-fill { background: var(--warning-500, #f59e0b); }
    .cg-limit.is-full .cg-limit-fill { background: var(--danger-600, #dc2626); }
    .cg-input {
        flex: 1 1 auto;
        min-height: 3.25rem;
        padding: .6rem .8rem;
        font-size: .875rem;
        line-height: 1.5;
        border-radius: .625rem;
        border: 1px solid var(--gray-300, #d1d5db);
        background: var(--gray-50, #fff);
        color: var(--gray-900, #111827);
        resize: vertical;
    }
    :where(.dark) .cg-input {
        border-color: var(--gray-700, #374151);
        background: var(--gray-900, #111827);
        color: var(--gray-100, #f3f4f6);
    }
    .cg-input:disabled { opacity: .5; }

    {{-- 커스텀 색(#10). 스코프는 .cg-root — :root 에 쓰면 패널 전체가 다시 칠해진다
         (의도의 정반대이고 활성 테마와 싸운다). 기본(패널 따름)은 이 블록 자체가 없다. --}}
    @if ($palette = $this->sidebarPalette())
    .cg-root {
        @foreach ($palette as $shade => $value)
        --primary-{{ $shade }}: {{ $value }};
        @endforeach
        --cg-on-primary: {{ $this->sidebarOnPrimary() }};
    }
    @endif
    </style>

    <aside class="cg-panel" x-show="open" x-cloak>
        {{-- 폭 조절(#9). 드래그 + 키보드(화살표 = 1rem, Shift+화살표 = 4rem, Home = 기본).
             separator 역할과 이름을 줘서 마우스 없이도 닿는다. --}}
        <div
            class="cg-resize"
            x-data
            x-init="window.cgResize($el)"
            role="separator"
            aria-orientation="vertical"
            tabindex="0"
            aria-label="{{ trans('concierge::strings.resize_sidebar') }}"
        ></div>
        <div class="cg-chat">
            {{-- 기록 오버레이의 기준점. 바깥을 누르면 닫힌다. --}}
            <div class="cg-head-wrap" x-on:click.outside="history = false">
            <div class="cg-head">
                <div class="cg-head-title">{{ $this->currentTitle() }}</div>

                {{-- 폭이 24rem 뿐이라 아이콘만 쓴다. 이름은 title 로 남긴다.
                     다른 대화에 미읽음 알림이 있으면 아이콘에도 점을 띄운다(#29) —
                     목록을 열어보기 전에는 점이 어디 있는지 알 수 없기 때문이다. --}}
                <span class="cg-iconwrap">
                    <x-filament::icon-button
                        size="sm" color="gray" icon="tabler-history"
                        :label="trans('concierge::strings.conversation_history')"
                        x-on:click="history = ! history"
                        x-bind:aria-expanded="history"
                    />
                    @if (collect($this->conversations)->contains(fn ($c) => $c['unread'] ?? false))
                        <span class="cg-history-badge"></span>
                    @endif
                </span>

                <x-filament::icon-button
                    size="sm" color="gray" icon="tabler-plus"
                    :label="trans('concierge::strings.new_conversation')"
                    wire:click="startConversation"
                    x-on:click="history = false"
                />

                <x-filament::icon-button
                    size="sm" color="gray" icon="tabler-x"
                    :label="trans('concierge::strings.close')"
                    x-on:click="open = false"
                />
            </div>

            <div class="cg-history" x-show="history" x-cloak>
                @forelse ($this->conversations as $conversation)
                    <div class="cg-history-row" wire:key="conv-{{ $conversation['id'] }}">
                    <button
                        type="button"
                        wire:click="openConversation('{{ $conversation['id'] }}')"
                        x-on:click="history = false"
                        @class(['cg-history-item', 'is-active' => $conversation['id'] === $this->conversationId])
                    ><span class="cg-history-name">{{ $conversation['title'] }}</span>@if ($conversation['unread'] ?? false)<span class="cg-history-dot"></span>@endif<span class="cg-history-when">{{ $conversation['when'] ?? '' }}</span></button>
                    {{-- 삭제(#8) — soft. 설정이 켜졌을 때만 그리고, 서버 쪽에서 한 번 더 검사한다. --}}
                    @if ($this->canDeleteConversations())
                        <button
                            type="button"
                            class="cg-history-del"
                            wire:click="deleteConversation('{{ $conversation['id'] }}')"
                            wire:confirm="{{ trans('concierge::strings.confirm_delete_conversation') }}"
                            title="{{ trans('concierge::strings.delete_conversation') }}"
                        ><x-filament::icon icon="tabler-trash" class="cg-history-del-icon" /></button>
                    @endif
                    </div>
                @empty
                    <div class="cg-history-empty">{{ trans('concierge::strings.' . ($this->canCreateServers ? 'empty' : 'empty_no_create')) }}</div>
                @endforelse
            </div>
            </div>

            {{-- 스크롤 위치는 CSS(column-reverse)가 지킨다 — 위 .cg-scroll 규칙 참고.
                 바닥 고정·복원·이력 읽기 보존이 전부 브라우저 네이티브 동작이라
                 시점을 쫓는 JS 가 필요 없다. JS 에 남은 일은 둘뿐이다:
                  · 확인 카드가 뜨면 바닥이 아니라 **카드 머리**를 보여준다 — 카드는
                    로그의 마지막 요소라, 바닥엔 버튼만 남고 제목은 위로 밀려난다.
                  · 내가 발화하면 어디를 보고 있었든 대화 끝(0)으로 돌아간다. --}}
            <div class="cg-scroll"
                 x-data="{ shown: null }"
                 x-on:cg-sent.window="shown = null; $nextTick(() => { $el.scrollTop = 0 })"
                 x-init="new MutationObserver(() => {
                         const card = $el.querySelector('.cg-card');

                         if (card && card !== shown) {
                             shown = card;
                             card.scrollIntoView({ block: 'start' });
                         } else if (! card && shown) {
                             /* 카드가 결정돼 사라졌다 — 대화가 다시 흐르므로 끝으로. */
                             shown = null;
                             $el.scrollTop = 0;
                         }
                     }).observe($el, { subtree: true, childList: true })">
                <div class="cg-log">
            @forelse ($this->messages as $message)
                @if ($message['role'] === 'user')
                    <div class="cg-bubble cg-user">{{ $message['text'] }}</div>
                @elseif ($message['role'] === 'event')
                    <div class="cg-event">{{ $message['text'] }}</div>
                @elseif ($message['role'] === 'card')
                    {{-- 확정된 카드(#6). 버튼 없이 결과 배지만 — 무엇이 결정됐는지가 기록으로 남는다. --}}
                    @php($card = $message['card'] ?? [])
                    <div class="cg-card is-resolved">
                        <div class="cg-card-head">
                            <span class="cg-card-title">{{ $card['title'] ?? '' }}</span>
                            <span class="cg-card-outcome is-{{ $card['outcome'] ?? 'cancelled' }}">
                                {{ trans('concierge::strings.card_outcome_' . ($card['outcome'] ?? 'cancelled')) }}
                            </span>
                        </div>

                        @if ($card['lines'] ?? [])
                            <dl>
                                @foreach ($card['lines'] as $line)
                                    <dt>{{ $line['label'] ?? '' }}</dt>
                                    <dd>{{ $line['value'] ?? '' }}</dd>
                                @endforeach
                            </dl>
                        @endif

                        @if ($card['diff'] ?? null)
                            <div class="cg-diff">
                                <div class="cg-diff-del">- {{ $card['diff']['before'] }}</div>
                                <div class="cg-diff-add">+ {{ $card['diff']['after'] }}</div>
                            </div>
                        @endif
                    </div>

                    {{-- 승인된 액션이 구간 경계다(#6) — 실행 전후의 대화가 눈으로 나뉜다. --}}
                    @if ($card['anchor'] ?? null)
                        <div class="cg-boundary"></div>
                    @endif
                @else
                    <div class="cg-bubble cg-agent cg-md">{!! $this->markdown($message['text']) !!}</div>

                    {{-- 이 턴에서 실제로 무언가를 한 서버로 가는 버튼.
                         `wire:navigate` 로 이동해야 사이드바가 살아남는다(전체 리로드면 재마운트된다). --}}
                    @if ($message['links'] ?? [])
                        <div class="cg-links">
                            @foreach ($message['links'] as $link)
                                <a href="{{ $link['url'] }}" wire:navigate class="cg-link">
                                    {{ $link['label'] }}
                                </a>
                            @endforeach
                        </div>
                    @endif
                @endif
            @empty
                {{-- 개설을 못 하는 사람에게 "서버 만들고 싶어" 예시는 막다른 길이다(#48). --}}
                <p class="cg-hint">{{ trans('concierge::strings.' . ($this->canCreateServers ? 'empty' : 'empty_no_create')) }}</p>
            @endforelse

            {{--
                스트리밍 목적지. wire:stream 은 **이미 DOM 에 있는** 요소만 찾으므로 항상 렌더한다.
                응답이 끝나면 위 @forelse 가 같은 내용을 정식으로 그리고 Livewire 가 여기를 비운다.
                ⚠ 태그 안에 공백·줄바꿈을 넣지 말 것 — :empty 가 안 먹어서 빈 말풍선이 남는다.
            --}}
            <div wire:stream="live-user" class="cg-bubble cg-user"></div>
            {{--
                응답 스트림은 **더블 버퍼**다(#22). API 가 텍스트를 ~30자 덩어리로 0.7초씩
                띄엄띄엄 보내는 것을 실측했다(와이어 레벨 — 우리 파이프라인 문제가 아니다).
                Livewire 는 숨은 원본에 쓰고, 보이는 쪽은 타자기처럼 일정 속도로 드러낸다.
                ⚠ 원본은 렌더된 HTML 이다 — 글자 수로 자르되 태그 안은 자르지 않는다(스크립트).
            --}}
            <div wire:stream="live-assistant" class="cg-stream-src" hidden></div>
            <div class="cg-bubble cg-agent cg-md" x-data x-init="window.cgTypewriter($el)"></div>

            {{--
                확인 카드. 내용은 **모델이 쓴 문장이 아니라 우리가 조회한 사실**이다 —
                모델이 "안전한 작업입니다" 같은 말로 사용자를 유도할 수 없어야 한다.
            --}}
            @if ($this->pendingCard)
                <div @class(['cg-card', 'is-danger' => $this->pendingCard['danger'] ?? false])>
                    <div class="cg-card-title">{{ $this->pendingCard['title'] }}</div>

                    <dl>
                        @foreach ($this->pendingCard['lines'] as $line)
                            <dt>{{ $line['label'] }}</dt>
                            <dd>{{ $line['value'] }}</dd>
                        @endforeach
                    </dl>

                    {{-- 편집 필드(#59) — 개설 카드의 서버 이름. 고친 값이 그대로 실행에 들어간다. --}}
                    @if ($this->pendingCard['name_input'] ?? null)
                        <label class="cg-card-input-label">
                            {{ $this->pendingCard['name_input']['label'] }}
                            <input type="text" maxlength="40" class="cg-card-input" wire:model="cardName" />
                        </label>
                    @endif

                    {{-- 파일 수정은 **무엇이 바뀌는지 눈으로 보여야** 확인의 의미가 있다. --}}
                    @if ($this->pendingCard['diff'] ?? null)
                        <div class="cg-diff">
                            <div class="cg-diff-del">- {{ $this->pendingCard['diff']['before'] }}</div>
                            <div class="cg-diff-add">+ {{ $this->pendingCard['diff']['after'] }}</div>
                        </div>
                    @endif

                    @if ($this->pendingCard['note'] ?? null)
                        <p class="cg-card-note">{{ $this->pendingCard['note'] }}</p>
                    @endif

                    <div class="cg-card-actions">
                        <x-filament::button
                            wire:click="confirmTool"
                            wire:loading.attr="disabled"
                            wire:target="confirmTool,cancelTool"
                            :color="($this->pendingCard['danger'] ?? false) ? 'danger' : 'primary'"
                        >
                            {{ $this->pendingCard['confirm'] }}
                        </x-filament::button>

                        <x-filament::button
                            wire:click="cancelTool"
                            wire:loading.attr="disabled"
                            wire:target="confirmTool,cancelTool"
                            color="gray"
                        >
                            {{ $this->pendingCard['cancel'] ?? trans('concierge::strings.card_cancel') }}
                        </x-filament::button>
                    </div>
                </div>
            @endif
                </div>
            </div>

            {{-- 진행 중인 서버. 입력창 바로 위에 둬서 대화를 밀어내지 않는다. --}}
            @if ($this->watching)
                <div class="cg-watch">
                    <div class="cg-watch-title">{{ trans('concierge::strings.watch_title') }}</div>
                    @foreach ($this->watching as $w)
                        <div class="cg-watch-row" wire:key="watch-{{ $w['id'] }}">
                            <span class="cg-watch-name">{{ $w['name'] }}</span>
                            <span class="cg-watch-state">
                                <span class="cg-spinner"></span>
                                {{ trans('concierge::strings.watch_state_' . $w['state'], [], null)
                                   ?: trans('concierge::strings.watch_state_unknown') }}
                            </span>
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- 한도 게이지(#4) — 70% 이상 썼을 때만 뜬다. 무엇 기준인지는 툴팁이 말한다. --}}
            @if ($this->limitStatus)
                <div class="cg-limit {{ $this->limitStatus['percent'] >= 100 ? 'is-full' : ($this->limitStatus['percent'] >= 80 ? 'is-warn' : '') }}"
                     title="{{ trans('concierge::strings.limit_meter_tip', [
                         'scope' => trans('concierge::strings.limit_meter_scope_' . $this->limitStatus['scope']),
                         'period' => trans('concierge::strings.limit_hit_period_' . $this->limitStatus['period']),
                         'metric' => trans('concierge::strings.limit_hit_metric_' . $this->limitStatus['metric']),
                     ]) }}">
                    <div class="cg-limit-bar">
                        <div class="cg-limit-fill" style="width: {{ $this->limitStatus['percent'] }}%"></div>
                    </div>
                    <span class="cg-limit-text">{{ trans('concierge::strings.limit_meter', [
                        'percent' => $this->limitStatus['percent'],
                    ]) }}</span>
                </div>
            @endif

            {{-- 내가 말을 걸었으면 이력을 올려다보던 중이라도 대화 끝으로 돌아간다(#84) —
                 방금 보낸 말과 그 답을 보려고 보낸 것이니까. --}}
            <form wire:submit="send" x-data="{}" x-on:submit="$dispatch('cg-sent')" class="cg-form">
            {{-- ⚠ 입력창은 **disable 하지 않는다.** wire:loading 에 target 없이 disabled 를
                 걸었더니 30초(진행 중 5초) 폴링마다 입력창이 잠기며 **포커스가 풀려 타이핑이
                 끊겼다**(실측, #26). 전송 중 중복 제출은 보내기 버튼과 send() 의 빈 입력
                 검사가 막는다 — 입력 자체를 잠글 이유가 없다. --}}
            <textarea
                wire:model="draft"
                rows="2"
                class="cg-input"
                placeholder="{{ trans('concierge::strings.placeholder') }}"
                {{-- Enter 로 보내고 Shift+Enter 로 줄바꿈. 값 비우기는 $nextTick 으로 미뤄
                     Livewire 가 먼저 읽게 한다 — 먼저 지우면 빈 메시지가 전송된다. --}}
                @keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); $el.closest('form').requestSubmit(); $nextTick(() => $el.value = ''); }"
            ></textarea>

            {{-- 폴링에는 반응하지 않도록 반드시 target 을 건다(#26). --}}
            <x-filament::button type="submit" icon="tabler-send" wire:loading.attr="disabled" wire:target="send">
                <span wire:loading.remove wire:target="send">{{ trans('concierge::strings.send') }}</span>
                <span wire:loading wire:target="send">{{ trans('concierge::strings.sending') }}</span>
            </x-filament::button>
            </form>
        </div>
    </aside>

    {{-- ⚠ Livewire 루트는 요소 하나여야 한다 — <style> 과 같은 이유로 루트 div **안**에 둔다.
         morph 는 스크립트를 다시 실행하지 않고, ??= 가 재정의도 막는다. --}}
    <script>
        // 타자기 스무딩(#22). API 는 텍스트를 ~30자 덩어리로 0.7초 간격으로 보낸다(실측).
        // 덩어리를 받아 두고 글자 단위로 일정 속도로 드러낸다 — 표시만 다르고,
        // 모델·기록에 닿는 것은 아무것도 없다.
        window.cgTypewriter ??= function (view) {
            const src = view.previousElementSibling; // wire:stream 원본(숨김)
            const instant = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            let shown = 0;   // 지금까지 드러낸 글자 수 — 상태 문구로 잠깐 줄어도 유지한다(아래)
            let timer = null;

            // 글자 예산만큼만 남긴다. **텍스트 노드 단위**로 자르므로 태그 안이 잘리지 않는다.
            const truncate = (node, budget) => {
                for (let child = node.firstChild; child;) {
                    const next = child.nextSibling;

                    if (budget <= 0) {
                        child.remove();
                    } else if (child.nodeType === Node.TEXT_NODE) {
                        if (child.textContent.length > budget) {
                            child.textContent = child.textContent.slice(0, budget);
                            budget = 0;
                        } else {
                            budget -= child.textContent.length;
                        }
                    } else {
                        budget = truncate(child, budget);
                    }

                    child = next;
                }

                return budget;
            };

            const render = (count) => {
                const clone = src.cloneNode(true);
                truncate(clone, count);
                view.replaceChildren(...clone.childNodes);
            };

            const stop = () => { if (timer) { clearInterval(timer); timer = null; } };

            const tick = () => {
                const total = src.textContent.length;

                // 응답이 끝나면 Livewire 가 원본을 비운다(정식 말풍선이 위 목록 루프로 뜬다) —
                // 애니메이션을 즉시 접어야 마지막 렌더와 싸우지 않는다.
                // ⚠ 이 스크립트의 주석에 blade 지시문 이름을 쓰면 안 된다 — script 안이라도
                //   컴파일러가 @ 로 시작하는 단어를 지시문으로 파싱한다(실측: 컴파일 실패).
                if (total === 0) {
                    shown = 0;
                    view.replaceChildren();
                    stop();

                    return;
                }

                // 원본이 줄었다 = "생각 중" 같은 상태 문구로 교체된 것 → 통째로 비춘다.
                // shown 은 **줄이지 않는다** — 도구 왕복 뒤 본문이 돌아왔을 때 이미 보여준
                // 부분을 처음부터 다시 치면 안 된다.
                if (shown >= total) {
                    render(total);
                    stop(); // 다음 덩어리가 오면 옵저버가 다시 깨운다

                    return;
                }

                // 밀린 만큼에 비례해 빨라진다 — 덩어리 하나(~30자)를 다음 덩어리가 오기
                // 전(~0.5초)에 소화하고, 큰 버스트도 화면이 스트림에 크게 뒤처지지 않는다.
                shown = Math.min(total, shown + Math.max(1, Math.round((total - shown) / 14)));
                render(shown);
            };

            new MutationObserver(() => {
                // 동작 축소 선호(접근성) — 애니메이션 없이 그대로 비춘다.
                if (instant) {
                    shown = src.textContent.length;
                    render(shown);

                    return;
                }

                if (!timer) {
                    timer = setInterval(tick, 33);
                }
            }).observe(src, { childList: true, characterData: true, subtree: true });
        };

        // 사이드바 폭 조절(#9). 폭은 :root 의 --cg-w 하나다 — 패널과 본문 밀어내기가
        // 같은 변수를 읽으므로 여기만 쓰면 페이지가 알아서 따라온다.
        //  ⚠ documentElement 에 써야 한다(.cg-root 는 body 의 조상이 아니다 — 상단 주석).
        //  저장은 localStorage — 열림 상태(cg-open)와 같은 곳이다. 폭은 "이 화면에서 얼마가
        //  편한가"라 모니터(브라우저)마다 다른 것이 맞고, 계정 동기화가 오히려 어색하다.
        window.cgResize ??= function (handle) {
            const MIN = 384;                                                 // 24rem — 기존 기본값
            const KEY = 'cg-w';
            const max = () => Math.min(Math.round(window.innerWidth * 0.6), 800);
            const clamp = (px) => Math.min(max(), Math.max(MIN, Math.round(px)));
            const width = () => Math.round(handle.parentElement.getBoundingClientRect().width);

            const apply = (px) => {
                document.documentElement.style.setProperty('--cg-w', px + 'px');
                handle.setAttribute('aria-valuenow', px);
                handle.setAttribute('aria-valuemin', MIN);
                handle.setAttribute('aria-valuemax', max());
            };

            const set = (px) => {
                px = clamp(px);
                apply(px);
                localStorage.setItem(KEY, px);
            };

            // 저장된 폭 복원 — persist 래퍼는 이동만 버티고 전체 리로드는 여기로 돌아온다.
            // (지시문 함정 재발 — 이 주석에 골뱅이표를 붙였다가 런타임 e() 오류로 사이드바가
            //  통째로 빠졌다. blade 는 script 주석 안 지시문 단어도 컴파일한다. 위 경고 참고.)
            // 저장값이 없어도 apply 한다: 기본값과 같은 384px 라 화면은 그대로고,
            // aria-value* 가 처음부터 채워진다.
            const restore = () => {
                const saved = parseInt(localStorage.getItem(KEY), 10);

                apply(Number.isNaN(saved) ? MIN : clamp(saved));
            };

            restore();

            // ⚠ wire:navigate 는 <html> 속성을 통째로 갈아치운다 — cg-open 클래스와 같은
            //   함정. documentElement 의 인라인 --cg-w 도 날아가므로 이동마다 다시 쓴다.
            document.addEventListener('livewire:navigated', restore);

            let dragging = false;

            handle.addEventListener('pointerdown', (event) => {
                if (window.innerWidth < 1024) return; // 오버레이 모드 — 조절 없음

                dragging = true;
                handle.setPointerCapture(event.pointerId);
                handle.classList.add('is-dragging');
                document.documentElement.classList.add('cg-resizing');
                event.preventDefault();
            });

            handle.addEventListener('pointermove', (event) => {
                if (dragging) set(window.innerWidth - event.clientX);
            });

            const end = () => {
                if (!dragging) return;

                dragging = false;
                handle.classList.remove('is-dragging');
                document.documentElement.classList.remove('cg-resizing');
            };

            handle.addEventListener('pointerup', end);
            handle.addEventListener('pointercancel', end);

            // 키보드 경로 — 드래그 전용이면 마우스 없는 사용자는 못 닿는다.
            handle.addEventListener('keydown', (event) => {
                if (window.innerWidth < 1024) return;

                const step = event.shiftKey ? 64 : 16; // 1rem, Shift 는 4rem

                if (event.key === 'ArrowLeft') { set(width() + step); event.preventDefault(); }
                else if (event.key === 'ArrowRight') { set(width() - step); event.preventDefault(); }
                else if (event.key === 'Home') { set(MIN); event.preventDefault(); }
            });
        };
    </script>
</div>
