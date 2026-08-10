<?php

namespace WisdomIT\Concierge\Support;

use WisdomIT\Concierge\Models\ConciergeUsage;

/**
 * 대화 기록에서 비밀 값을 지운다 (#11).
 *
 * 사용자가 채팅으로 친 자격증명은 secrets 선언이 못 가린다 — 그건 도구 호출 기록만
 * 다룬다. 값이 **무엇인지 알게 되는 순간**(개설 카드·변수 변경)에 도구상자가 수집하고,
 * 여기서 그 대화의 저장된 행 전체(user_message·assistant_message)를 소급해 가린다.
 *
 * ⚠ 화면의 진행 중 대화($this->messages)는 건드리지 않는다 — 사용자가 방금 친 말이
 *   눈앞에서 별표로 바뀌면 안 된다(#11 요구). 새로고침하면 가려진 행으로 다시 그려지고,
 *   모델이 다시 읽는 이력도 그 행이라 값이 컨텍스트로 되돌아오지 않는다 — 모델은
 *   가려진 값을 재사용할 수 없으니 필요하면 다시 묻는다. 그게 의도다.
 */
final class TranscriptScrubber
{
    /** @param array<int, string> $values */
    public static function apply(?string $conversationId, array $values): void
    {
        if ($conversationId === null || $conversationId === '' || $values === []) {
            return;
        }

        ConciergeUsage::query()
            ->where('conversation_id', $conversationId)
            ->get()
            ->each(function (ConciergeUsage $usage) use ($values): void {
                $user = SecretMasker::maskValues((string) $usage->user_message, $values);
                $assistant = SecretMasker::maskValues((string) $usage->assistant_message, $values);

                if ($user !== $usage->user_message || $assistant !== $usage->assistant_message) {
                    $usage->update(['user_message' => $user, 'assistant_message' => $assistant]);
                }

                // 보존된 카드도 대화의 일부다(#6) — 카드 내용(diff·변수 값)에 비밀이
                // 실려 있을 수 있고, 영구 보존되므로 본문과 같은 기준으로 가린다.
                $cards = $usage->resolved_cards;

                if ($cards !== null && ($masked = self::maskCards($cards, $values)) !== $cards) {
                    $usage->forceFill(['resolved_cards' => $masked])->save();
                }
            });
    }

    /**
     * 카드 배열의 모든 문자열 값을 가린다. 구조는 그대로 둔다.
     *
     * @param  array<int, mixed>  $cards
     * @param  array<int, string>  $values
     * @return array<int, mixed>
     */
    private static function maskCards(array $cards, array $values): array
    {
        array_walk_recursive($cards, function (&$item) use ($values): void {
            if (is_string($item)) {
                $item = SecretMasker::maskValues($item, $values);
            }
        });

        return $cards;
    }
}
