<?php

namespace WisdomIT\WisdomAiAssistant\Models;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * 대화 하나. 메시지는 `wisdom_ai_assistant_usages` 가 갖고 있고 이 모델은 목록에 필요한 것만 든다.
 *
 * ⚠ **빈 대화는 만들지 않는다.** 채팅 화면을 열기만 해도 행이 생기면 사이드바가 빈 항목으로
 *   가득 찬다. id 는 화면에서 미리 정하고, 행은 **첫 발화 때** `ensure()` 가 만든다.
 *
 * @property string $id
 * @property int $user_id
 * @property ?string $title
 * @property ?Carbon $last_message_at
 * @property ?string $pending_token
 * @property ?array<string, mixed> $pending_card
 * @property ?Carbon $notice_unread_at
 * @property Carbon $created_at
 */
class WisdomAiAssistantConversation extends Model
{
    /** 목록에 보여줄 제목 길이. 넘으면 자른다. */
    private const TITLE_LENGTH = 60;

    protected $table = 'wisdom_ai_assistant_conversations';

    // PK 가 ULID 문자열이다 — 자동 증가로 두면 저장할 때 id 가 날아간다.
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'title',
        'last_message_at',
        'pending_token',
        'pending_card',
        'notice_unread_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'pending_card' => 'array',
            'notice_unread_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 이 대화의 메시지들. 오래된 것부터 — 화면에 그대로 쌓아 올리는 순서다.
     *
     * @return HasMany<WisdomAiAssistantUsage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(WisdomAiAssistantUsage::class, 'conversation_id')->oldest('id');
    }

    /**
     * 새 대화에 쓸 id. **행은 아직 만들지 않는다** (클래스 주석 참고).
     *
     * ULID 라서 사전순 정렬이 곧 생성순이다.
     */
    public static function newId(): string
    {
        return (string) Str::ulid();
    }

    /**
     * 첫 발화 시점에 행을 만들고, 이후 발화에서는 활동 시각만 민다.
     *
     * `$firstMessage` 는 제목이 아직 없을 때만 쓴다 — 사용자가 제목을 바꿨거나 이미 정해진
     * 대화에서 두 번째 발화가 제목을 덮어쓰면 안 된다.
     */
    public static function ensure(string $id, int $userId, string $firstMessage): self
    {
        $conversation = static::query()->find($id) ?? new static([
            'id' => $id,
            'user_id' => $userId,
        ]);

        if (blank($conversation->title)) {
            // ⚠ 알림 텍스트가 제목이 되는 경우가 있다 — 마크다운(**, `)과 줄바꿈을 걷어내고
            //   첫 줄만 쓴다. 안 그러면 목록에 "**서버** 에\n\n…" 가 그대로 보인다(실측).
            $firstLine = trim((string) Str::of($firstMessage)->replace(['**', '`'], '')->explode("\n")->first());
            $conversation->title = Str::limit($firstLine, self::TITLE_LENGTH);
        }

        $conversation->last_message_at = now();
        $conversation->save();

        return $conversation;
    }

    /**
     * 사이드바 목록. 최근 활동순이다.
     *
     * @return Builder<self>
     */
    public static function listFor(int $userId): Builder
    {
        return static::query()
            ->where('user_id', $userId)
            // 활동 시각이 비는 경우는 없지만(ensure 가 항상 채운다), 정렬이 흔들리는 것보다
            // 생성순으로 떨어지는 편이 낫다.
            ->orderByDesc('last_message_at')
            ->orderByDesc('id');
    }

    /**
     * 확인 카드를 띄운 채로 멈췄다는 표시. 새로고침 후 카드를 다시 그리는 데 쓴다.
     *
     * @param array<string, mixed> $card
     */
    public function markPending(string $token, array $card): void
    {
        $this->forceFill(['pending_token' => $token, 'pending_card' => $card])->save();
    }

    public function clearPending(): void
    {
        if ($this->pending_token === null) {
            return;
        }

        $this->forceFill(['pending_token' => null, 'pending_card' => null])->save();
    }

    /**
     * 목록에 띄울 마지막 채팅 시각. 하루 미만은 상대 시간, 그 이상은 날짜(#28).
     *
     * Carbon 의 diffForHumans 를 안 쓰는 이유: "1주 전" 같은 뭉뚱그림 없이
     * "하루 지나면 날짜"라는 규칙을 정확히 지키기 위해서다.
     */
    public function lastMessageLabel(): string
    {
        $at = $this->last_message_at;

        if ($at === null) {
            return '';
        }

        $minutes = (int) $at->diffInMinutes(now());

        if ($minutes < 1) {
            return trans('wisdom-ai-assistant::strings.time_just_now');
        }

        if ($minutes < 60) {
            return trans('wisdom-ai-assistant::strings.time_minutes_ago', ['n' => $minutes]);
        }

        if ($minutes < 60 * 24) {
            return trans('wisdom-ai-assistant::strings.time_hours_ago', ['n' => intdiv($minutes, 60)]);
        }

        return $at->year === now()->year
            ? $at->format(trans('wisdom-ai-assistant::strings.time_date_this_year'))
            : $at->format(trans('wisdom-ai-assistant::strings.time_date_other_year'));
    }

    /** 화면에 띄울 제목. 내용 저장 이전 기록은 제목이 없다. */
    public function displayTitle(): string
    {
        return filled($this->title)
            ? $this->title
            : trans('wisdom-ai-assistant::strings.untitled_conversation');
    }
}
