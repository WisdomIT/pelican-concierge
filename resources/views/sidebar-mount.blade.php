{{--
    사이드바를 모든 페이지에 붙이는 자리 (`PanelsRenderHook::BODY_END`).

    ⚠ `@persist` 가 핵심이다. Filament 는 SPA 모드라 화면 이동이 `wire:navigate` 로 일어나고,
      그때 body 가 통째로 갈린다. `@persist` 로 감싼 요소만 새 문서로 **옮겨져** 살아남는다 —
      없으면 화면을 옮길 때마다 대화가 처음부터 다시 시작된다.

    ⚠ 이름(`wisdom-ai-assistant`)은 **모든 페이지에서 같아야** 한다. 페이지마다 다르면 Alpine 이
      다른 요소로 보고 새로 만든다.

    ⚠ **서버 콘솔 페이지는 SPA 예외**다(`PanelProvider` 의 `->spa()`). 그 화면을 드나들 때는
      전체 리로드가 나서 `@persist` 가 안 먹고 컴포넌트가 다시 마운트된다. 그 구멍은
      대화 이어보기(`mount()` 가 마지막 대화를 연다)가 메운다.
--}}
{{--
    본문을 미는 클래스를 **Alpine 이 붙기 전에** 걸어 준다.

    이게 없으면 페이지가 한 번 안 밀린 채로 그려졌다가 Alpine 이 뜨면서 밀려, 열어 둔 채
    새로고침할 때마다 화면이 튄다. `@persist` 밖에 두어야 이동할 때마다 다시 실행된다.
--}}
<script>
    document.documentElement.classList.toggle('wa-open', localStorage.getItem('wa-open') === '1');
</script>

@persist('wisdom-ai-assistant')
    <livewire:wisdom-ai-assistant-sidebar />
@endpersist
