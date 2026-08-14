<?php

namespace WisdomIT\Concierge\Notifications;

use App\Models\User;
use Filament\Notifications\Notification;
use Throwable;
use WisdomIT\Concierge\Llm\ProviderFailure;
use WisdomIT\Concierge\Tools\RequesterScope;

/**
 * "주 공급자가 답을 멈춰 다음 것으로 넘어갔다"를 관리자에게 (#89).
 *
 * 이 알림이 없으면 장애 조치는 **조용한 강등**이다 — 어시스턴트는 계속 답하지만 더 약한
 * 모델로 답하고, 운영자는 청구서를 보거나 사용자가 불평할 때까지 모른다. 넘어간 것 자체는
 * 좋은 일이지만, 넘어갔다는 사실은 누군가 알아야 고칠 수 있다.
 *
 * 🔴 **키는 적지 않는다.** 실패한 항목의 이름과 갈래(쿼터·장애·키 거부)뿐이다 —
 *    이 플러그인 어디서나 지키는 규칙이고, 알림은 여러 사람의 화면에 뜬다.
 *
 * ⚠ 한 사건에 한 번이다. 몇 번 보낼지는 ProviderChain::claimNotice 가 정하고, 여기서는
 *   보내는 일만 한다 — 잠금과 전송이 한 클래스에 섞이면 "왜 백 통이 왔지"를 두 곳에서
 *   찾아야 한다.
 */
final class FailoverNotice
{
    /**
     * @param  array{from: string, to: string, reason: string}  $labels
     */
    public static function send(array $labels, ProviderFailure $kind): void
    {
        try {
            $recipients = self::admins();

            if ($recipients === []) {
                return;
            }

            $notification = Notification::make()
                ->title(trans('concierge::strings.failover_notice_title', ['from' => $labels['from']]))
                ->body(trans('concierge::strings.failover_notice_body', $labels))
                // 키가 거부된 것은 날씨가 아니라 설정 오류다 — 사람이 고쳐야 낫는다.
                ->{$kind->needsAttention() ? 'danger' : 'warning'}();

            foreach ($recipients as $admin) {
                $notification->sendToDatabase($admin);
            }
        } catch (Throwable $exception) {
            // ⚠ 알림이 실패해도 **대화는 계속돼야 한다.** 여기서 예외가 새면 장애 조치가
            //   성공한 바로 그 순간에 대화가 죽는다 — 고치려던 문제보다 나쁜 결과다.
            report($exception);
        }
    }

    /**
     * 받을 사람 — 관리 도구를 하나라도 쓸 수 있는 계정.
     *
     * 화면(사용량·카탈로그)이 권한으로 갈리듯 여기도 같은 판정을 쓴다(RequesterScope).
     * "관리자 역할"이라는 별도 개념을 만들면 그 둘이 언젠가 갈린다.
     *
     * @return array<int, User>
     */
    private static function admins(): array
    {
        return User::query()
            // 역할이 하나도 없는 계정은 볼 것이 없다 — 전체를 훑지 않기 위한 예선이다.
            ->whereHas('roles')
            ->get()
            ->filter(fn (User $user) => (new RequesterScope($user))->isPanelAdmin())
            ->values()
            ->all();
    }
}
