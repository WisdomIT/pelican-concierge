<?php

namespace WisdomIT\WisdomAiAssistant\Support;

use Illuminate\Support\Str;

/**
 * 모델이 만든 마크다운을 HTML 로 바꾼다.
 *
 * 채팅 화면과 관리자의 대화 보기가 **같은 함수**를 써야 한다 — 아래 두 옵션이
 * 보안의 전부인데, 복사해 두면 한쪽만 고쳐지고 다른 쪽이 XSS 로 남는다.
 */
final class Markdown
{
    public static function render(string $text): string
    {
        return Str::markdown($text, [
            // 모델 출력은 사용자 입력만큼이나 신뢰할 수 없다. 도구가 붙으면 게임 콘솔
            // 로그(다른 플레이어가 쓴 텍스트)가 이 경로로 들어온다.
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);
    }
}
