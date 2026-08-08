<?php

namespace WisdomIT\WisdomAiAssistant\Models;

use App\Models\Server;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 에이전트가 도구를 한 번 부른 기록.
 *
 * 왜 남기나 — 에이전트는 "무엇을 답했는가"보다 **"무엇을 보고 답했는가"** 가 중요하다.
 * 잘못된 답이 나왔을 때 모델이 헛소리한 건지, 도구가 이상한 값을 준 건지 구분해야 한다.
 *
 * @property int $id
 * @property int $usage_id
 * @property ?string $conversation_id
 * @property string $tool_name
 * @property ?int $server_id
 * @property ?Server $server
 * @property ?string $input
 * @property ?string $result
 * @property bool $is_error
 * @property Carbon $created_at
 */
class WisdomAiAssistantToolCall extends Model
{
    protected $table = 'wisdom_ai_assistant_tool_calls';

    protected $fillable = [
        'usage_id',
        'conversation_id',
        'tool_name',
        'server_id',
        'input',
        'result',
        'is_error',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_error' => 'boolean'];
    }

    public function usage(): BelongsTo
    {
        return $this->belongsTo(WisdomAiAssistantUsage::class, 'usage_id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
