<?php

namespace WisdomIT\Concierge\Models;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 에이전트 메시지 1건 = 이 테이블 1행. 사용량 집계와 진단 로그를 겸한다.
 *
 * @property int $id
 * @property int $user_id
 * @property ?string $conversation_id
 * @property User $user
 * @property string $model
 * @property string $effort
 * @property int $input_tokens
 * @property int $output_tokens
 * @property string $status
 * @property ?string $error
 * @property ?string $user_message
 * @property ?string $assistant_message
 * @property Carbon $created_at
 */
class ConciergeUsage extends Model
{
    public const STATUS_OK = 'ok';

    public const STATUS_RATE_LIMITED = 'rate_limited';

    public const STATUS_NOT_CONFIGURED = 'not_configured';

    /** ⚠ 더 이상 생산되지 않는다(#2 — 토글 제거). 과거 행이 이 값을 갖고 있어 상수는 남긴다. */
    public const STATUS_DISABLED = 'disabled';

    public const STATUS_ERROR = 'error';

    /** 확인 카드를 띄우고 사용자의 결정을 기다리는 중. 결정이 오면 같은 행이 갱신된다. */
    public const STATUS_AWAITING = 'awaiting_confirmation';

    /**
     * 모델 루프 밖(standalone) 카드의 결정만 담는 행 (#6). 토큰 0, 본문 없음 —
     * 카드가 대화 어느 지점에서 결정됐는지를 기록이 남기기 위한 자리다.
     * ⚠ 메시지 한도(UsageLimiter)가 세지 않는다 — 버튼 하나 누른 것이 한도를 깎으면 안 된다.
     */
    public const STATUS_CARD = 'card_resolved';

    protected $table = 'concierge_usages';

    protected $fillable = [
        'user_id',
        // 대화를 묶는 ULID = `concierge_conversations.id`.
        'conversation_id',
        'provider',
        'model',
        'effort',
        'input_tokens',
        'output_tokens',
        'search_count',
        'status',
        'error',
        'user_message',
        'assistant_message',
        'segment',
        'resolved_cards',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'search_count' => 'integer',
            'segment' => 'integer',
            'resolved_cards' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ConciergeConversation::class, 'conversation_id');
    }

    /** @return HasMany<ConciergeToolCall, $this> */
    public function toolCalls(): HasMany
    {
        return $this->hasMany(ConciergeToolCall::class, 'usage_id');
    }

    /**
     * ⚠ 대화 본문은 **항상** 저장한다. 예전에는 `log_content` 로 끌 수 있었지만,
     *   이 저장소가 곧 사용자의 대화 이어보기 원본이 되면서 끄면 기능이 깨진다.
     *   운영 방침도 "관리자는 항상 내용을 볼 수 있어야 한다"로 정해졌다 — 에이전트가
     *   남의 서버에 실제 명령을 내리므로 감사 기록이 선택 사항일 수 없다 (006 마이그레이션).
     *
     *   로그에는 게임 콘솔에서 온 텍스트가 섞이므로, **시크릿 마스킹을 거친 값만** 넘길 것 (#13).
     *
     * @param array<string, mixed> $attributes
     */
    public static function record(int $userId, ConciergeSettings $settings, array $attributes): self
    {
        // 새 행은 그 대화의 **현재 구간**에 속한다(#6). 호출부가 명시하면 그 값을 쓴다.
        if (!array_key_exists('segment', $attributes) && filled($attributes['conversation_id'] ?? null)) {
            $attributes['segment'] = (int) ConciergeConversation::query()
                ->whereKey($attributes['conversation_id'])
                ->value('active_segment');
        }

        return static::query()->create([
            'user_id' => $userId,
            // 어느 공급자의 지출인지 행마다 남는다(#3) — 단가·집계가 공급자별로 다르다.
            'provider' => $settings->provider ?? 'anthropic',
            'model' => $settings->model,
            'effort' => $settings->effort,
            ...$attributes,
        ]);
    }

    /**
     * 이 턴에서 결정된 카드를 기록에 덧붙인다 (#6). 한 턴에 카드가 이어질 수 있어(승인 후
     * 다음 확인) 배열로 쌓는다. 화면 복원과 관리자 대화 보기가 이걸 그대로 그린다.
     *
     * @param array<string, mixed> $card
     */
    public function appendResolvedCard(array $card): void
    {
        $cards = $this->resolved_cards ?? [];
        $cards[] = $card;

        $this->forceFill(['resolved_cards' => $cards])->save();
    }
}
