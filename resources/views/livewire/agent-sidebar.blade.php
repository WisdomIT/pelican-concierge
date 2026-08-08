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
      바깥으로 나가는 규칙은 본문을 밀어내는 `html.wa-open body` 하나뿐이다.
--}}
{{--
    ⚠ **열림 여부는 서버가 아니라 브라우저에 둔다.**
      Livewire 프로퍼티로 두면 `wire:navigate` 이동은 `@persist` 덕에 버티지만,
      **전체 리로드에서는 컴포넌트가 새로 마운트되어 기본값으로 돌아간다.** 서버 콘솔 페이지가
      바로 그 경우다(SPA 예외) — 콘솔을 떠나는 순간 사이드바가 닫혔다. localStorage 에 두면
      두 경우 모두 유지되고, 토글할 때마다 서버를 왕복하지도 않는다.

    ⚠ `wa-open` 클래스는 **이동할 때마다 다시 붙여야 한다.**
      본문을 미는 규칙은 `<html>` 의 클래스로 걸리는데, `wire:navigate` 는 이동한 페이지의
      `<html>` 속성으로 갈아치우므로 그 클래스가 날아간다. 그런데 사이드바는 `@persist` 로
      살아남아 **다시 초기화되지 않으므로** `x-effect` 는 다시 돌지 않는다(`open` 이 바뀐 게
      아니다). → `livewire:navigated` 에서 직접 다시 붙인다.
      (첫 그림에서의 깜빡임은 `sidebar-mount.blade.php` 의 인라인 스크립트가 막는다)
--}}
<div
    class="wa-root"
    x-data="{
        history: false,
        open: localStorage.getItem('wa-open') === '1',
        apply() { document.documentElement.classList.toggle('wa-open', this.open) },
    }"
    x-effect="localStorage.setItem('wa-open', open ? '1' : '0'); apply(); if (open && $wire.unread) $wire.markRead()"
    x-on:livewire:navigated.document="apply()"
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
         `.wa-root` 에 두면 그 자손만 보게 되는데, 본문을 미는 규칙의 대상인 `body` 는
         `.wa-root` 의 **조상**이라 값을 못 본다 → `var(--wa-w)` 가 무효가 되어 padding 이
         0 으로 떨어지고, 사이드바가 항상 본문을 덮는 것처럼 보인다. 실제로 그렇게 틀렸다. */
    :root { --wa-w: 24rem; }

    /* 펼치면 본문을 밀어낸다. 덮어버리면 콘솔 로그를 보면서 물어볼 수가 없는데,
       그게 이 사이드바를 만든 이유다. Filament 의 topbar 는 sticky(고정 아님)라 함께 밀린다. */
    html.wa-open body {
        padding-right: var(--wa-w);
        transition: padding-right .2s ease;
    }
    /* 좁은 화면에서는 밀 자리가 없다 → 덮는다. */
    @media (max-width: 1023px) { html.wa-open body { padding-right: 0; } }

    .wa-launcher {
        position: fixed; right: 0; bottom: 4.5rem; z-index: 29;
        display: flex; align-items: center; gap: .4rem;
        padding: .6rem .8rem;
        border-radius: .625rem 0 0 .625rem;
        background: var(--primary-600, #4f46e5);
        color: #fff;
        font-size: .8125rem; font-weight: 600;
        box-shadow: 0 1px 6px rgb(0 0 0 / .25);
    }
    .wa-launcher:hover { filter: brightness(1.08); }
    /* blade-icons 는 width/height=24 를 그대로 박아 넣는다 → 글자 크기에 맞춰 줄인다. */
    .wa-launcher-icon { width: 1.1rem; height: 1.1rem; }
    .wa-dot {
        width: .5rem; height: .5rem; border-radius: 50%;
        background: var(--danger-500, #ef4444);
        box-shadow: 0 0 0 2px var(--primary-600, #4f46e5);
    }

    .wa-panel {
        position: fixed; inset: 0 0 0 auto; z-index: 30;
        display: flex; flex-direction: column;
        width: var(--wa-w); max-width: 100vw;
        padding: 1rem;
        background: var(--gray-50, #fff);
        border-left: 1px solid var(--gray-200, #e5e7eb);
        box-shadow: -2px 0 12px rgb(0 0 0 / .08);
    }
    :where(.dark) .wa-panel {
        background: var(--gray-950, #030712);
        border-color: var(--gray-800, #1f2937);
    }

    /* 대화 로그만 늘어나고 머리말·입력은 제자리에 있어야 한다. */
    .wa-chat { display: flex; flex-direction: column; gap: 1rem; min-height: 0; flex: 1 1 auto; }
    .wa-scroll { flex: 1 1 auto; min-height: 0; overflow-y: auto; }

    .wa-log { display: flex; flex-direction: column; gap: .75rem; }

    .wa-bubble {
        max-width: 100%;
        border-radius: .75rem;
        padding: .7rem 1rem;
        font-size: .875rem;
        line-height: 1.65;
        overflow-wrap: anywhere;
    }
    /* 스트리밍 목적지는 항상 DOM 에 있어야 해서, 비어 있을 때만 감춘다.
       :empty 는 공백 문자도 내용으로 치므로 아래 div 안에 줄바꿈을 넣지 말 것. */
    .wa-bubble:empty { display: none; }

    .wa-user {
        align-self: flex-end;
        background: var(--primary-600, #4f46e5);
        color: #fff;
        white-space: pre-wrap;
    }
    .wa-agent {
        align-self: flex-start;
        background: var(--gray-100, #f3f4f6);
        color: var(--gray-900, #111827);
    }
    :where(.dark) .wa-agent {
        background: var(--gray-800, #1f2937);
        color: var(--gray-100, #f3f4f6);
    }

    .wa-hint { font-size: .875rem; color: var(--gray-500, #6b7280); }

    /* ── 화면 이동 버튼 ── */
    .wa-links { display: flex; flex-wrap: wrap; gap: .4rem; align-self: flex-start; }
    .wa-link {
        display: inline-block;
        max-width: 100%;
        padding: .35rem .7rem;
        border-radius: .5rem;
        border: 1px solid var(--primary-600, #4f46e5);
        color: var(--primary-600, #4f46e5);
        font-size: .8125rem; font-weight: 600;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .wa-link:hover { background: color-mix(in oklab, var(--primary-600, #4f46e5) 12%, transparent); }

    /* ── 마크다운 ── */
    .wa-md > :first-child { margin-top: 0; }
    .wa-md > :last-child { margin-bottom: 0; }
    .wa-md p { margin: .6em 0; }
    .wa-md ul, .wa-md ol { margin: .6em 0; padding-left: 1.4em; }
    .wa-md ul { list-style: disc; }
    .wa-md ol { list-style: decimal; }
    .wa-md li { margin: .25em 0; }
    .wa-md li > ul, .wa-md li > ol { margin: .25em 0; }
    .wa-md strong { font-weight: 600; }
    .wa-md em { font-style: italic; }
    .wa-md h1, .wa-md h2, .wa-md h3, .wa-md h4 {
        margin: 1em 0 .5em; font-weight: 600; line-height: 1.3;
    }
    .wa-md h1 { font-size: 1.25em; }
    .wa-md h2 { font-size: 1.15em; }
    .wa-md h3, .wa-md h4 { font-size: 1.05em; }
    .wa-md a { text-decoration: underline; }
    .wa-md code {
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-size: .875em;
        padding: .1em .35em;
        border-radius: .3rem;
        background: color-mix(in oklab, currentColor 12%, transparent);
    }
    /* 넓은 코드는 말풍선을 밀지 않고 스스로 스크롤한다. */
    .wa-md pre {
        margin: .6em 0;
        padding: .7rem .85rem;
        border-radius: .5rem;
        overflow-x: auto;
        background: color-mix(in oklab, currentColor 8%, transparent);
    }
    .wa-md pre code { padding: 0; background: none; }
    .wa-md blockquote {
        margin: .6em 0;
        padding-left: .8em;
        border-left: 3px solid color-mix(in oklab, currentColor 25%, transparent);
    }
    .wa-md table { display: block; overflow-x: auto; border-collapse: collapse; margin: .6em 0; }
    .wa-md th, .wa-md td {
        border: 1px solid color-mix(in oklab, currentColor 20%, transparent);
        padding: .3em .6em;
    }
    .wa-md hr {
        margin: 1em 0; border: 0;
        border-top: 1px solid color-mix(in oklab, currentColor 20%, transparent);
    }

    /* ── 확인 카드 ── */
    .wa-card {
        align-self: flex-start;
        max-width: 100%;
        border: 1px solid var(--gray-300, #d1d5db);
        border-radius: .75rem;
        padding: .9rem 1rem;
        background: var(--gray-50, #fff);
        font-size: .875rem;
    }
    :where(.dark) .wa-card { border-color: var(--gray-700, #374151); background: var(--gray-900, #111827); }
    .wa-card.is-danger { border-color: var(--danger-500, #ef4444); }

    .wa-card-title { font-weight: 600; margin-bottom: .6rem; }
    .wa-card dl { display: grid; grid-template-columns: auto 1fr; gap: .25rem .8rem; margin: 0 0 .7rem; }
    .wa-card dt { color: var(--gray-500, #6b7280); }
    .wa-card dd { margin: 0; }
    .wa-card-input-label {
        display: block;
        margin: .5rem 0 .25rem;
        font-size: .75rem;
        color: var(--gray-500, #6b7280);
    }
    .wa-card-input {
        display: block; width: 100%;
        margin-top: .25rem;
        padding: .4rem .55rem;
        font-size: .8125rem;
        border: 1px solid var(--gray-300, #d1d5db);
        border-radius: .45rem;
        background: transparent;
        color: inherit;
    }
    :where(.dark) .wa-card-input { border-color: var(--gray-700, #374151); }

    .wa-card-note {
        margin: 0 0 .7rem;
        font-size: .8125rem;
        color: var(--danger-600, #dc2626);
    }
    .wa-card-actions { display: flex; gap: .5rem; }

    .wa-diff {
        margin: 0 0 .7rem;
        border-radius: .5rem;
        overflow-x: auto;
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-size: .75rem;
        line-height: 1.6;
        background: color-mix(in oklab, currentColor 6%, transparent);
    }
    .wa-diff > div { padding: .15rem .6rem; white-space: pre; }
    .wa-diff-del { color: var(--danger-600, #dc2626); }
    .wa-diff-add { color: var(--success-600, #16a34a); }

    /* 카드 실행/취소 안내 — 대화가 아니라 화면 표시다. 가운데 작게. */
    .wa-event {
        align-self: center;
        font-size: .75rem;
        color: var(--gray-500, #6b7280);
    }

    /* ── 대화 목록 ──
       ⚠ 기록 패널은 **흐름 안에 두지 않는다.** flex 컬럼의 자식으로 두면 채팅이 길 때
       flex 축소가 max-height 보다 먼저 걸려 찌그러지고, 축소된 높이에서는 overflow 도
       의도대로 안 돼 스크롤바가 안 보였다(#28, 실측). 오버레이로 띄우면 채팅 영역과
       아예 경쟁하지 않는다. */
    .wa-head-wrap { position: relative; }
    .wa-head {
        display: flex; align-items: center; gap: .5rem;
        padding-bottom: .75rem;
        border-bottom: 1px solid var(--gray-200, #e5e7eb);
    }
    :where(.dark) .wa-head { border-color: var(--gray-800, #1f2937); }
    .wa-head-title {
        flex: 1 1 auto; min-width: 0;
        font-size: .875rem; font-weight: 600;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }

    .wa-history {
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
        background: var(--gray-50, #fff);
        box-shadow: 0 8px 24px rgb(0 0 0 / .18);
    }
    :where(.dark) .wa-history {
        border-color: var(--gray-800, #1f2937);
        background: var(--gray-950, #030712);
    }
    .wa-history-item {
        /* 항목 내부의 가로 정렬만 flex — 목록 컨테이너는 block 이어야 한다(위 주석). */
        display: flex; align-items: baseline; gap: .5rem;
        width: 100%;
        padding: .45rem .6rem;
        border-radius: .45rem;
        font-size: .8125rem;
        text-align: left;
        color: var(--gray-700, #374151);
    }
    .wa-history-name {
        flex: 1 1 auto; min-width: 0;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .wa-history-when {
        flex: 0 0 auto;
        font-size: .6875rem;
        color: var(--gray-400, #9ca3af);
    }
    /* 기록 아이콘 위 배지 — 목록을 열기 전에도 미읽음이 있음을 알린다(#29). */
    .wa-iconwrap { position: relative; display: inline-flex; }
    .wa-history-badge {
        position: absolute; top: 1px; right: 1px;
        width: .45rem; height: .45rem; border-radius: 50%;
        background: var(--danger-500, #ef4444);
        pointer-events: none;
    }

    /* 다른 대화에 새 알림이 도착했다는 표시(#29). 열면 꺼진다. */
    .wa-history-dot {
        flex: 0 0 auto;
        width: .45rem; height: .45rem; border-radius: 50%;
        background: var(--danger-500, #ef4444);
        align-self: center;
    }
    :where(.dark) .wa-history-item { color: var(--gray-300, #d1d5db); }
    .wa-history-item:hover { background: color-mix(in oklab, currentColor 10%, transparent); }
    .wa-history-item.is-active {
        background: color-mix(in oklab, var(--primary-600, #4f46e5) 15%, transparent);
        font-weight: 600;
    }
    .wa-history-empty { padding: .45rem .6rem; font-size: .8125rem; color: var(--gray-500, #6b7280); }

    /* ── 진행 중 카드 ── */
    .wa-watch {
        border: 1px solid var(--gray-200, #e5e7eb);
        border-radius: .625rem;
        padding: .5rem .7rem;
        font-size: .8125rem;
    }
    :where(.dark) .wa-watch { border-color: var(--gray-800, #1f2937); }
    .wa-watch-title { color: var(--gray-500, #6b7280); font-size: .75rem; margin-bottom: .3rem; }
    .wa-watch-row { display: flex; align-items: center; gap: .5rem; }
    .wa-watch-name {
        flex: 1 1 auto; min-width: 0;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        font-weight: 600;
    }
    .wa-watch-state { display: inline-flex; align-items: center; gap: .35rem; color: var(--gray-500, #6b7280); }
    .wa-spinner {
        width: .7rem; height: .7rem; border-radius: 50%;
        border: 2px solid color-mix(in oklab, currentColor 30%, transparent);
        border-top-color: var(--primary-600, #4f46e5);
        animation: wa-spin .8s linear infinite;
    }
    @keyframes wa-spin { to { transform: rotate(360deg); } }

    /* ── 입력 ── */
    .wa-form { display: flex; align-items: flex-end; gap: .5rem; }
    .wa-input {
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
    :where(.dark) .wa-input {
        border-color: var(--gray-700, #374151);
        background: var(--gray-900, #111827);
        color: var(--gray-100, #f3f4f6);
    }
    .wa-input:disabled { opacity: .5; }
    </style>

    {{-- 런처. topbar 에 넣지 않은 이유는 topbar 가 사용자 설정에 따라 꺼질 수 있기 때문이다. --}}
    <button type="button" class="wa-launcher" x-show="! open"
            x-on:click="open = true" x-cloak>
        <x-filament::icon icon="tabler-message-chatbot" class="wa-launcher-icon" />
        {{ trans('wisdom-ai-assistant::strings.title') }}
        {{-- 닫혀 있는 동안 에이전트가 말을 걸었다는 표시. --}}
        @if ($this->unread)
            <span class="wa-dot"></span>
        @endif
    </button>

    <aside class="wa-panel" x-show="open" x-cloak>
        <div class="wa-chat">
            {{-- 기록 오버레이의 기준점. 바깥을 누르면 닫힌다. --}}
            <div class="wa-head-wrap" x-on:click.outside="history = false">
            <div class="wa-head">
                <div class="wa-head-title">{{ $this->currentTitle() }}</div>

                {{-- 폭이 24rem 뿐이라 아이콘만 쓴다. 이름은 title 로 남긴다.
                     다른 대화에 미읽음 알림이 있으면 아이콘에도 점을 띄운다(#29) —
                     목록을 열어보기 전에는 점이 어디 있는지 알 수 없기 때문이다. --}}
                <span class="wa-iconwrap">
                    <x-filament::icon-button
                        size="sm" color="gray" icon="tabler-history"
                        :label="trans('wisdom-ai-assistant::strings.conversation_history')"
                        x-on:click="history = ! history"
                        x-bind:aria-expanded="history"
                    />
                    @if (collect($this->conversations)->contains(fn ($c) => $c['unread'] ?? false))
                        <span class="wa-history-badge"></span>
                    @endif
                </span>

                <x-filament::icon-button
                    size="sm" color="gray" icon="tabler-plus"
                    :label="trans('wisdom-ai-assistant::strings.new_conversation')"
                    wire:click="startConversation"
                    x-on:click="history = false"
                />

                <x-filament::icon-button
                    size="sm" color="gray" icon="tabler-x"
                    :label="trans('wisdom-ai-assistant::strings.close')"
                    x-on:click="open = false"
                />
            </div>

            <div class="wa-history" x-show="history" x-cloak>
                @forelse ($this->conversations as $conversation)
                    <button
                        type="button"
                        wire:key="conv-{{ $conversation['id'] }}"
                        wire:click="openConversation('{{ $conversation['id'] }}')"
                        x-on:click="history = false"
                        @class(['wa-history-item', 'is-active' => $conversation['id'] === $this->conversationId])
                    ><span class="wa-history-name">{{ $conversation['title'] }}</span>@if ($conversation['unread'] ?? false)<span class="wa-history-dot"></span>@endif<span class="wa-history-when">{{ $conversation['when'] ?? '' }}</span></button>
                @empty
                    <div class="wa-history-empty">{{ trans('wisdom-ai-assistant::strings.empty') }}</div>
                @endforelse
            </div>
            </div>

            {{-- 로그만 늘어나고 스크롤한다. 스트리밍은 Livewire 재렌더 없이 DOM 을 직접
                 고치므로, 바닥 고정은 MutationObserver 로 해야 따라온다. --}}
            <div class="wa-scroll"
                 x-init="new MutationObserver(() => $el.scrollTop = $el.scrollHeight)
                            .observe($el, { subtree: true, childList: true, characterData: true })">
                <div class="wa-log">
            @forelse ($this->messages as $message)
                @if ($message['role'] === 'user')
                    <div class="wa-bubble wa-user">{{ $message['text'] }}</div>
                @elseif ($message['role'] === 'event')
                    <div class="wa-event">{{ $message['text'] }}</div>
                @else
                    <div class="wa-bubble wa-agent wa-md">{!! $this->markdown($message['text']) !!}</div>

                    {{-- 이 턴에서 실제로 무언가를 한 서버로 가는 버튼.
                         `wire:navigate` 로 이동해야 사이드바가 살아남는다(전체 리로드면 재마운트된다). --}}
                    @if ($message['links'] ?? [])
                        <div class="wa-links">
                            @foreach ($message['links'] as $link)
                                <a href="{{ $link['url'] }}" wire:navigate class="wa-link">
                                    {{ $link['label'] }}
                                </a>
                            @endforeach
                        </div>
                    @endif
                @endif
            @empty
                <p class="wa-hint">{{ trans('wisdom-ai-assistant::strings.empty') }}</p>
            @endforelse

            {{--
                스트리밍 목적지. wire:stream 은 **이미 DOM 에 있는** 요소만 찾으므로 항상 렌더한다.
                응답이 끝나면 위 @forelse 가 같은 내용을 정식으로 그리고 Livewire 가 여기를 비운다.
                ⚠ 태그 안에 공백·줄바꿈을 넣지 말 것 — :empty 가 안 먹어서 빈 말풍선이 남는다.
            --}}
            <div wire:stream="live-user" class="wa-bubble wa-user"></div>
            <div wire:stream="live-assistant" class="wa-bubble wa-agent wa-md"></div>

            {{--
                확인 카드. 내용은 **모델이 쓴 문장이 아니라 우리가 조회한 사실**이다 —
                모델이 "안전한 작업입니다" 같은 말로 사용자를 유도할 수 없어야 한다.
            --}}
            @if ($this->pendingCard)
                <div @class(['wa-card', 'is-danger' => $this->pendingCard['danger'] ?? false])>
                    <div class="wa-card-title">{{ $this->pendingCard['title'] }}</div>

                    <dl>
                        @foreach ($this->pendingCard['lines'] as $line)
                            <dt>{{ $line['label'] }}</dt>
                            <dd>{{ $line['value'] }}</dd>
                        @endforeach
                    </dl>

                    {{-- 편집 필드(#59) — 개설 카드의 서버 이름. 고친 값이 그대로 실행에 들어간다. --}}
                    @if ($this->pendingCard['name_input'] ?? null)
                        <label class="wa-card-input-label">
                            {{ $this->pendingCard['name_input']['label'] }}
                            <input type="text" maxlength="40" class="wa-card-input" wire:model="cardName" />
                        </label>
                    @endif

                    {{-- 파일 수정은 **무엇이 바뀌는지 눈으로 보여야** 확인의 의미가 있다. --}}
                    @if ($this->pendingCard['diff'] ?? null)
                        <div class="wa-diff">
                            <div class="wa-diff-del">- {{ $this->pendingCard['diff']['before'] }}</div>
                            <div class="wa-diff-add">+ {{ $this->pendingCard['diff']['after'] }}</div>
                        </div>
                    @endif

                    @if ($this->pendingCard['note'] ?? null)
                        <p class="wa-card-note">{{ $this->pendingCard['note'] }}</p>
                    @endif

                    <div class="wa-card-actions">
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
                            {{ $this->pendingCard['cancel'] ?? trans('wisdom-ai-assistant::strings.card_cancel') }}
                        </x-filament::button>
                    </div>
                </div>
            @endif
                </div>
            </div>

            {{-- 진행 중인 서버. 입력창 바로 위에 둬서 대화를 밀어내지 않는다. --}}
            @if ($this->watching)
                <div class="wa-watch">
                    <div class="wa-watch-title">{{ trans('wisdom-ai-assistant::strings.watch_title') }}</div>
                    @foreach ($this->watching as $w)
                        <div class="wa-watch-row" wire:key="watch-{{ $w['id'] }}">
                            <span class="wa-watch-name">{{ $w['name'] }}</span>
                            <span class="wa-watch-state">
                                <span class="wa-spinner"></span>
                                {{ trans('wisdom-ai-assistant::strings.watch_state_' . $w['state'], [], null)
                                   ?: trans('wisdom-ai-assistant::strings.watch_state_unknown') }}
                            </span>
                        </div>
                    @endforeach
                </div>
            @endif

            <form wire:submit="send" x-data="{}" class="wa-form">
            {{-- ⚠ 입력창은 **disable 하지 않는다.** wire:loading 에 target 없이 disabled 를
                 걸었더니 30초(진행 중 5초) 폴링마다 입력창이 잠기며 **포커스가 풀려 타이핑이
                 끊겼다**(실측, #26). 전송 중 중복 제출은 보내기 버튼과 send() 의 빈 입력
                 검사가 막는다 — 입력 자체를 잠글 이유가 없다. --}}
            <textarea
                wire:model="draft"
                rows="2"
                class="wa-input"
                placeholder="{{ trans('wisdom-ai-assistant::strings.placeholder') }}"
                {{-- Enter 로 보내고 Shift+Enter 로 줄바꿈. 값 비우기는 $nextTick 으로 미뤄
                     Livewire 가 먼저 읽게 한다 — 먼저 지우면 빈 메시지가 전송된다. --}}
                @keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); $el.closest('form').requestSubmit(); $nextTick(() => $el.value = ''); }"
            ></textarea>

            {{-- 폴링에는 반응하지 않도록 반드시 target 을 건다(#26). --}}
            <x-filament::button type="submit" icon="tabler-send" wire:loading.attr="disabled" wire:target="send">
                <span wire:loading.remove wire:target="send">{{ trans('wisdom-ai-assistant::strings.send') }}</span>
                <span wire:loading wire:target="send">{{ trans('wisdom-ai-assistant::strings.sending') }}</span>
            </x-filament::button>
            </form>
        </div>
    </aside>
</div>
