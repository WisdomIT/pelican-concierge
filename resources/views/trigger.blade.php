{{--
    사이드바를 여는 트리거 (#7). 플로팅 런처를 없애고 패널 자신의 크롬에 들어간다.

    두 자리 중 페이지가 그리는 쪽만 나타난다 — Filament 이 패널마다 알림 위치를 정하므로
    (topbar/사이드바) 훅 둘을 조건 없이 등록하고 각 패널이 하나만 그리게 둔다:

      topbar   → GLOBAL_SEARCH_AFTER   (알림 벨 **바로 앞**. TOPBAR_END 는 .fi-topbar-end
                                        바깥이라 벨·사용자 메뉴 뒤로 간다 — 오답)
      sidebar  → SIDEBAR_NAV_END       (nav 안 끝 = 알림 열기 위. SIDEBAR_FOOTER 는
                                        사용자 메뉴 아래 — 오답)

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
    {{-- nav 안, 그룹 목록 다음. 접힌 사이드바에서는 코어처럼 라벨만 숨긴다.
         ⚠ $store.sidebar 는 코어 레이아웃이 만든다 — 없을 수도 있으니 옵셔널로 읽는다. --}}
    <button
        type="button"
        class="fi-sidebar-item-btn cg-trigger"
        style="width: 100%;"
        x-data
        x-on:click="window.dispatchEvent(new CustomEvent('concierge-toggle'))"
        title="{{ trans('concierge::strings.title') }}"
    >
        <x-filament::icon icon="tabler-message-chatbot" class="fi-sidebar-item-icon fi-icon fi-size-lg" />
        <span class="fi-sidebar-item-label" x-show="$store.sidebar?.isOpen ?? true">
            {{ trans('concierge::strings.title') }}
        </span>
        <span class="cg-trigger-dot"></span>
    </button>
@endif
