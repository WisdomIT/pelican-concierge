<?php

namespace WisdomIT\Concierge\Llm;

use Illuminate\Support\Facades\Cache;
use Throwable;
use WisdomIT\Concierge\Models\ConciergeSettings;

/**
 * 공급자 목록을 순서대로 써 나간다 (#89).
 *
 * 주 공급자가 답을 멈추면 — 크레딧 소진, 쿼터, 장애 — 다음 항목이 이어받는다.
 * 예전에는 누군가 설정 화면을 열어 바꿀 때까지 어시스턴트가 그냥 고장이었고,
 * 첫 신호는 사용자의 "답을 안 해요"였다.
 *
 * ## 쉬는 시간
 *
 * 실패한 항목은 잠시 건너뛴다. 쿼터는 정시에 풀리고 장애는 끝나므로 **버리지 않고
 * 쉬게만 한다** — 시간이 지나면 다시 시도하고, 여전히 안 되면 다시 쉰다. 스스로
 * 바로잡히는 규칙이라 시간을 짧게 잡아도 위험하지 않고, 대신 거절하는 공급자를
 * 매 대화마다 두드리지도 않는다(ProviderFailure::cooldownMinutes).
 *
 * 🔴 **쉬는 상태는 캐시에 둔다. 설정에 쓰지 않는다.** 장애 조치는 운영자의 설정을
 *    바꾸는 일이 아니다 — 지금 어디로 나갈지를 정할 뿐이고, 그 사실은 저절로 만료되어야
 *    한다. 설정에 적으면 장애가 끝난 뒤에도 운영자가 고른 순서가 뒤집힌 채로 남는다.
 *
 * ⚠ 전부 쉬고 있으면 **그래도 주 공급자로 간다.** 아무 데도 안 보내는 것보다 한 번
 *   더 두드려 보고 진짜 이유를 사용자에게 말하는 편이 낫다.
 */
final class ProviderChain
{
    private const COOLDOWN_PREFIX = 'concierge:provider-cooldown:';

    /** 직전에 어느 항목으로 말했는가 — "돌아왔다"를 판정하는 근거. */
    private const SERVING_KEY = 'concierge:provider-serving';

    /** 알림을 한 번만 보내기 위한 자물쇠 — 한 사건에 하나, 대화마다 하나가 아니다. */
    private const NOTIFIED_PREFIX = 'concierge:provider-notified:';

    /** 한 번의 발화에서 시도할 항목 수 상한. 목록이 길어도 사용자를 무한정 기다리게 두지 않는다. */
    public const MAX_ATTEMPTS = 3;

    public function __construct(private readonly ConciergeSettings $settings) {}

    /**
     * 이번에 시도할 항목들 — 쉬는 것을 뺀 순서대로, 최대 MAX_ATTEMPTS 개.
     *
     * @return array<int, array<string, mixed>>
     */
    public function attempts(): array
    {
        $entries = $this->settings->entries();
        $awake = array_values(array_filter($entries, fn (array $e) => !$this->isCoolingDown($e)));

        // 전부 쉬고 있다 — 주 공급자를 한 번 더 두드린다(위 ⚠).
        return array_slice($awake !== [] ? $awake : $entries, 0, self::MAX_ATTEMPTS);
    }

    /** 운영자가 정한 첫 항목. "주 공급자로 돌아왔다"를 판정할 기준이다. */
    public function primary(): array
    {
        return $this->settings->entries()[0];
    }

    public function isPrimary(array $entry): bool
    {
        return self::idOf($entry) === self::idOf($this->primary());
    }

    /** 항목이 둘 이상 있어야 장애 조치가 의미를 갖는다. */
    public function hasFallbacks(): bool
    {
        return count($this->settings->entries()) > 1;
    }

    /**
     * 실패를 기록하고 그 항목을 쉬게 한다.
     *
     * @return ProviderFailure 판정된 갈래 — 호출자가 넘어갈지, 무엇이라 말할지 정한다
     */
    public function noteFailure(array $entry, Throwable $exception): ProviderFailure
    {
        $kind = ProviderError::kind($exception);

        // 우리 요청이 잘못된 것이라면 공급자 탓이 아니다 — 쉬게 할 이유가 없다.
        if ($kind->shouldFailOver()) {
            Cache::put(
                self::COOLDOWN_PREFIX . self::idOf($entry),
                $kind->value,
                now()->addMinutes($kind->cooldownMinutes()),
            );
        }

        return $kind;
    }

    /**
     * 이번 호출이 성공했다 — 돌아온 것인가.
     *
     * 지난번에 어느 항목으로 말했는지를 기억해 두고, 그것과 달라졌으면 알려 준다.
     * 대비책으로 넘어간 뒤 쿼터가 풀려 주 공급자로 **돌아오는 순간**도 사용자가 알 만한
     * 일이다 — 답의 성격이 다시 바뀌기 때문이다.
     *
     * @return ?string 직전에 쓰던 항목의 이름. 처음이거나 그대로면 null
     */
    public function noteSuccess(array $entry): ?string
    {
        $id = self::idOf($entry);
        $previous = Cache::get(self::SERVING_KEY);

        // 어디로 말하고 있는지는 오래 기억할 것이 아니다 — 쉬는 시간보다 길게 두면
        // 한참 전 일을 두고 "돌아왔다"고 말하게 된다.
        Cache::put(self::SERVING_KEY, ['id' => $id, 'label' => self::labelOf($entry)], now()->addHours(6));

        if (!is_array($previous) || ($previous['id'] ?? null) === $id) {
            return null;
        }

        return (string) ($previous['label'] ?? '');
    }

    /** 이 항목이 지금 쉬고 있는가. */
    public function isCoolingDown(array $entry): bool
    {
        return Cache::has(self::COOLDOWN_PREFIX . self::idOf($entry));
    }

    /**
     * 이 사건에 대해 아직 알리지 않았는가 — 알릴 차례면 true 를 주고 곧바로 잠근다.
     *
     * 🔴 **한 사건에 한 번.** 패널 전체가 안 되는 동안 대화가 백 번 일어나면 알림도 백 번
     *    간다 — 그러면 아무도 읽지 않는다. 잠금은 쉬는 시간과 같이 만료되므로, 장애가
     *    이어지면 다음 주기에 다시 한 번 알린다.
     */
    public function claimNotice(array $entry, ProviderFailure $kind): bool
    {
        return Cache::add(
            self::NOTIFIED_PREFIX . self::idOf($entry),
            $kind->value,
            now()->addMinutes($kind->cooldownMinutes()),
        );
    }

    /** 사람이 읽는 항목 이름. 없으면 공급자 이름으로 — 화면과 알림이 같은 말을 쓴다. */
    public static function labelOf(array $entry): string
    {
        $label = trim((string) ($entry['label'] ?? ''));

        return $label !== '' ? $label : ProviderFactory::label((string) ($entry['provider'] ?? ''));
    }

    /**
     * 항목의 식별자. 운영자가 이름을 바꿔도 쉬는 상태가 따라가도록 id 를 쓰고,
     * 없으면(옛 데이터) 공급자와 모델로 대신한다.
     */
    public static function idOf(array $entry): string
    {
        return (string) ($entry['id'] ?? ($entry['provider'] ?? '?') . ':' . ($entry['model'] ?? '?'));
    }
}
