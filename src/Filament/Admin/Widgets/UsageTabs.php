<?php

namespace WisdomIT\Concierge\Filament\Admin\Widgets;

use Filament\Widgets\Widget;

/**
 * 사용량 화면의 탭 줄 (#32) — 로그 · 사용자별 통계 · 도표.
 *
 * 헤더를 통째로 갈지 않으려고 위젯으로 만든다: 세 페이지가 이 위젯을 헤더
 * 위젯 목록 맨 앞에 넣으면 제목 아래·본문 위에 같은 자리로 뜬다.
 * 활성 탭은 뷰가 현재 URL 로 알아낸다 — 페이지가 알려줄 것이 없다.
 */
class UsageTabs extends Widget
{
    protected string $view = 'concierge::filament.admin.usage-tabs';

    protected int|string|array $columnSpan = 'full';
}
