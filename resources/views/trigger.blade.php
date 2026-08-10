{{--
    사이드바를 여는 트리거 (#7). 플로팅 런처를 없애고 패널 자신의 크롬에 들어간다.

    두 자리 중 페이지가 그리는 쪽만 나타난다 — Filament 이 패널마다 알림 위치를 정하므로
    (topbar/사이드바) 훅 둘을 조건 없이 등록하고 각 패널이 하나만 그리게 둔다:

      topbar   → GLOBAL_SEARCH_AFTER   (알림 벨 **바로 앞**. TOPBAR_END 는 .fi-topbar-end
                                        바깥이라 벨·사용자 메뉴 뒤로 간다 — 오답)
      sidebar  → SIDEBAR_FOOTER        (aside 직계 flex 자식. DOM 은 footer **뒤**지만
                                        아래 order 트릭으로 footer 앞에 세운다)

    ⚠ 사이드바 트리거는 nav 항목이 아니라 **footer 가족**이어야 한다(사용자 피드백).
      SIDEBAR_NAV_END(nav 안)에 두면 nav 패딩 안에 갇혀 '알림 열기'와 같은 성격으로
      보이지 않는다. footer 안쪽에는 훅이 없으므로:
        - 알림 버튼과 **같은 클래스**(fi-sidebar-database-notifications-btn)를 입고
        - .fi-sidebar 가 flex 열인 것을 이용해 footer 에 order:1 을 줘 우리 뒤로 보낸다
          (visual: nav → 트리거 → footer(알림·사용자 메뉴))

    코어의 버튼 클래스(fi-icon-btn / fi-sidebar-item-btn)를 그대로 입어 그 자리의
    다른 버튼과 똑같이 보인다 — 자체 스타일은 미읽음 점 하나뿐이다.

    열고 닫기는 window 이벤트로 전달한다. 사이드바 컴포넌트(cg-root)가
    `x-on:concierge-toggle.window` 로 받는다 — 트리거는 Livewire 컴포넌트 밖이라
    Alpine 상태를 직접 만질 수 없다.

    미읽음 표시: 사이드바가 닫혀 있고 안 읽은 알림이 있으면 컴포넌트의 x-effect 가
    <html> 에 `cg-unread` 클래스를 건다. 트리거의 점은 그 클래스로만 보인다 —
    트리거 자신은 상태를 모른다.
--}}
<style>
    .cg-trigger { position: relative; }
    /* ⚠ <button> 의 UA 기본은 text-align:center 다. 코어 nav 항목은 <a> 라 해당이 없지만
       우리(와 코어 알림 버튼)는 button 이라, flex:1 라벨 안의 글자가 가운데로 간다 —
       실측으로 잡은 원인. 전역 리셋은 없다(빌드 CSS 확인). */
    .cg-trigger { text-align: start; }
    /* footer(알림·사용자 메뉴)를 우리 뒤로 — .fi-sidebar 는 flex 열이고 나머지 자식은
       order 0 이라 DOM 순서를 유지한다. 모달 컨테이너는 fixed 라 순서와 무관하다. */
    .fi-sidebar > .fi-sidebar-footer { order: 1; }
    /* ⚠ 알림 버튼 클래스는 width:100% 다. 코어에선 그 버튼이 footer(자체 여백 16px)
       **안**에 있어 100% = 내용 폭이지만, 우리는 aside 직계라 100% + 좌우 여백이
       사이드바를 뚫었다(실측 피드백). auto 로 두면 flex stretch 가 여백을 뺀 폭을 준다. */
    .cg-trigger-sidebar {
        margin: 0 calc(var(--spacing, .25rem) * 4);
        width: auto;
        flex-shrink: 0;
    }
    .cg-trigger-dot {
        display: none;
        position: absolute; top: .2rem; right: .2rem;
        width: .5rem; height: .5rem; border-radius: 50%;
        background: var(--danger-500, #ef4444);
    }
    html.cg-unread .cg-trigger-dot { display: block; }
</style>

@if ($variant === 'topbar')
    <button
        type="button"
        class="fi-icon-btn cg-trigger"
        x-data
        x-on:click="window.dispatchEvent(new CustomEvent('concierge-toggle'))"
        title="{{ trans('concierge::strings.title') }}"
        aria-label="{{ trans('concierge::strings.title') }}"
    >
        <x-filament::icon icon="tabler-message-chatbot" class="fi-icon fi-size-lg" />
        <span class="cg-trigger-dot"></span>
    </button>
@else
    {{-- 알림 버튼(fi-sidebar-database-notifications-btn)과 같은 클래스 = 같은 생김새.
         라벨의 x-show 는 코어처럼 **접이식 사이드바일 때만** 단다 — isOpen 은 비접이식
         패널에서 모바일 드로어 상태라 데스크톱에서 false 다. 조건 없이 달았더니
         라벨이 숨고 아이콘만 가운데 떴다(실측). --}}
    <button
        type="button"
        class="fi-sidebar-database-notifications-btn cg-trigger cg-trigger-sidebar"
        x-data
        x-on:click="window.dispatchEvent(new CustomEvent('concierge-toggle'))"
        title="{{ trans('concierge::strings.title') }}"
    >
        <x-filament::icon icon="tabler-message-chatbot" class="fi-icon fi-size-lg" />
        <span
            @if (filament()->isSidebarCollapsibleOnDesktop())
                x-show="$store.sidebar.isOpen"
            @endif
            class="fi-sidebar-database-notifications-btn-label"
        >
            {{ trans('concierge::strings.title') }}
        </span>
        <span class="cg-trigger-dot"></span>
    </button>
@endif
