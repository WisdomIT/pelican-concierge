@php
    use WisdomIT\Concierge\Models\ConciergeUsage;
    use WisdomIT\Concierge\Support\Markdown;

    $totalIn = $messages->sum('input_tokens');
    $totalOut = $messages->sum('output_tokens');
@endphp

{{-- 사용자 채팅 화면과 같은 이유로 Tailwind 유틸리티를 쓰지 않는다 —
     plugins/ 는 Pelican 의 Tailwind 빌드 대상이 아니다. --}}
<div class="wac">
    <style>
        .wac { display: flex; flex-direction: column; gap: 1rem; }
        .wac-log { display: flex; flex-direction: column; gap: .75rem; }

        .wac-row { display: flex; flex-direction: column; gap: .25rem; }
        .wac-row.is-user { align-items: flex-end; }
        .wac-row.is-agent { align-items: flex-start; }

        .wac-bubble {
            max-width: 40rem;
            border-radius: .75rem;
            padding: .6rem .9rem;
            font-size: .8125rem;
            line-height: 1.6;
            overflow-wrap: anywhere;
        }
        .wac-user { background: var(--primary-600, #4f46e5); color: #fff; white-space: pre-wrap; }
        .wac-agent { background: var(--gray-100, #f3f4f6); color: var(--gray-900, #111827); }
        :where(.dark) .wac-agent { background: var(--gray-800, #1f2937); color: var(--gray-100, #f3f4f6); }

        .wac-meta {
            font-size: .6875rem;
            color: var(--gray-500, #6b7280);
            display: flex;
            gap: .5rem;
            align-items: center;
        }
        .wac-chip {
            border: 1px solid color-mix(in oklab, currentColor 25%, transparent);
            border-radius: .35rem;
            padding: 0 .3rem;
        }
        .wac-chip.is-bad { color: var(--danger-600, #dc2626); font-weight: 600; }

        .wac-total {
            display: flex;
            flex-wrap: wrap;
            gap: 1.25rem;
            padding: .7rem .9rem;
            border-radius: .625rem;
            background: var(--gray-50, #f9fafb);
            border: 1px solid var(--gray-200, #e5e7eb);
            font-size: .8125rem;
        }
        :where(.dark) .wac-total {
            background: var(--gray-900, #111827);
            border-color: var(--gray-700, #374151);
        }
        .wac-total b { font-weight: 600; }
        .wac-total span { color: var(--gray-500, #6b7280); }

        /* 마크다운 — 채팅 화면과 같은 규칙의 축약판 */
        .wac-md > :first-child { margin-top: 0; }
        .wac-md > :last-child { margin-bottom: 0; }
        .wac-md p { margin: .5em 0; }
        .wac-md ul, .wac-md ol { margin: .5em 0; padding-left: 1.4em; }
        .wac-md ul { list-style: disc; }
        .wac-md ol { list-style: decimal; }
        .wac-md li { margin: .2em 0; }
        .wac-md strong { font-weight: 600; }
        .wac-md em { font-style: italic; }
        .wac-md h1, .wac-md h2, .wac-md h3, .wac-md h4 { margin: .8em 0 .4em; font-weight: 600; }
        .wac-md a { text-decoration: underline; }
        .wac-md code {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: .875em; padding: .1em .3em; border-radius: .3rem;
            background: color-mix(in oklab, currentColor 12%, transparent);
        }
        .wac-md pre {
            margin: .5em 0; padding: .6rem .75rem; border-radius: .5rem; overflow-x: auto;
            background: color-mix(in oklab, currentColor 8%, transparent);
        }
        .wac-md pre code { padding: 0; background: none; }
        .wac-md blockquote {
            margin: .5em 0; padding-left: .8em;
            border-left: 3px solid color-mix(in oklab, currentColor 25%, transparent);
        }
        .wac-md table { display: block; overflow-x: auto; border-collapse: collapse; margin: .5em 0; }
        .wac-md th, .wac-md td {
            border: 1px solid color-mix(in oklab, currentColor 20%, transparent); padding: .25em .5em;
        }

        /* ── 도구 이력 ── */
        .wac-tools { display: flex; flex-direction: column; gap: .3rem; max-width: 40rem; }
        .wac-tool {
            border: 1px dashed color-mix(in oklab, currentColor 25%, transparent);
            border-radius: .5rem;
            padding: .35rem .6rem;
            font-size: .75rem;
        }
        .wac-tool > summary { cursor: pointer; display: flex; gap: .4rem; align-items: center; }
        .wac-tool > summary::marker { color: var(--gray-400, #9ca3af); }
        .wac-tool-name { font-weight: 600; }
        .wac-tool-server { color: var(--gray-500, #6b7280); }
        .wac-tool-bad { color: var(--danger-600, #dc2626); font-weight: 600; }
        .wac-tool pre {
            margin: .4rem 0 0;
            padding: .45rem .6rem;
            border-radius: .4rem;
            overflow-x: auto;
            max-height: 18rem;
            font-size: .6875rem;
            line-height: 1.5;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
            background: color-mix(in oklab, currentColor 8%, transparent);
        }
        .wac-tool dt { font-size: .6875rem; color: var(--gray-500, #6b7280); margin-top: .4rem; }

        /* ── 확정된 카드(#6) — 채팅 화면과 같은 정보를 같은 모양으로 ── */
        .wac-card {
            max-width: 40rem;
            border: 1px solid var(--gray-300, #d1d5db);
            border-radius: .75rem;
            padding: .7rem .9rem;
            font-size: .8125rem;
        }
        :where(.dark) .wac-card { border-color: var(--gray-700, #374151); }
        .wac-card-head { display: flex; align-items: center; gap: .5rem; }
        .wac-card-title { font-weight: 600; flex: 1 1 auto; }
        .wac-card-outcome {
            flex: 0 0 auto;
            font-size: .6875rem; font-weight: 600;
            padding: .1rem .5rem; border-radius: 999px;
            color: var(--gray-500, #6b7280);
            background: color-mix(in oklab, currentColor 12%, transparent);
        }
        .wac-card-outcome.is-approved {
            color: var(--success-600, #16a34a);
            background: color-mix(in oklab, var(--success-600, #16a34a) 12%, transparent);
        }
        .wac-card dl { display: grid; grid-template-columns: auto 1fr; gap: .2rem .8rem; margin: .5rem 0 0; }
        .wac-card dt { color: var(--gray-500, #6b7280); }
        .wac-card dd { margin: 0; }
        .wac-card-diff {
            margin: .5rem 0 0; border-radius: .5rem; overflow-x: auto;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: .6875rem; line-height: 1.6;
            background: color-mix(in oklab, currentColor 6%, transparent);
        }
        .wac-card-diff > div { padding: .15rem .6rem; white-space: pre; }
        .wac-card-diff .is-del { color: var(--danger-600, #dc2626); }
        .wac-card-diff .is-add { color: var(--success-600, #16a34a); }
    </style>

    <div class="wac-total">
        <div><span>{{ trans('concierge::strings.field_messages') }}</span> <b>{{ $messages->count() }}</b></div>
        <div><span>{{ trans('concierge::strings.field_input_tokens') }}</span> <b>{{ number_format($totalIn) }}</b></div>
        <div><span>{{ trans('concierge::strings.field_output_tokens') }}</span> <b>{{ number_format($totalOut) }}</b></div>
        <div><span>{{ trans('concierge::strings.total_tokens') }}</span> <b>{{ number_format($totalIn + $totalOut) }}</b></div>
        {{-- 공급자(#3) 다음에 모델 — 대화 중간에 바뀌었을 수 있어 전부 나열한다.
             🔴 장애 조치(#89)가 있으면 실제로 여럿이다. 어느 항목으로 청구됐는지가
                provider_entry 에 있으므로 그것을 먼저 쓴다 — 같은 공급자를 둘 두면
                공급자 이름만으로는 어느 쪽인지 알 수 없다. --}}
        <div><span>{{ trans('concierge::strings.field_provider') }}</span> <b>{{
            $messages->map(fn ($m) => filled($m->provider_entry)
                    ? $m->provider_entry
                    : (\WisdomIT\Concierge\Llm\ProviderFactory::badge((string) $m->provider) ?: $m->provider))
                ->filter()->unique()->implode(', ') ?: 'Anthropic'
        }}</b></div>
        <div><span>{{ trans('concierge::strings.field_model') }}</span> <b>{{
            $messages->pluck('model')->filter()->unique()->implode(', ')
        }}</b></div>
    </div>

    <div class="wac-log">
        @foreach ($messages as $message)
            {{-- 대화 본문 저장을 꺼둔 기간의 기록은 내용이 없다. 그래도 토큰은 보여준다. --}}
            @if (filled($message->user_message))
                <div class="wac-row is-user">
                    <div class="wac-bubble wac-user">{{ $message->user_message }}</div>
                </div>
            @endif

            {{--
                도구 이력은 응답 **위에** 둔다 — 실제 순서가 그렇고(보고 나서 답한다),
                "무엇을 보고 이렇게 답했나"를 위에서 아래로 읽게 된다.
            --}}
            @if ($message->toolCalls->isNotEmpty())
                <div class="wac-row is-agent">
                    <div class="wac-tools">
                        @foreach ($message->toolCalls as $call)
                            <details class="wac-tool">
                                <summary>
                                    <span class="wac-tool-name">
                                        {{ trans('concierge::strings.tool_' . $call->tool_name) }}
                                    </span>

                                    @if ($call->server)
                                        <span class="wac-tool-server">· {{ $call->server->name }}</span>
                                    @endif

                                    @if ($call->is_error)
                                        <span class="wac-tool-bad">· {{ trans('concierge::strings.tool_failed') }}</span>
                                    @endif
                                </summary>

                                @if (filled($call->input))
                                    <dt>{{ trans('concierge::strings.field_tool_input') }}</dt>
                                    <pre>{{ $call->input }}</pre>
                                @endif

                                @if (filled($call->result))
                                    <dt>{{ trans('concierge::strings.field_tool_result') }}</dt>
                                    <pre>{{ $call->result }}</pre>
                                @else
                                    <dt>{{ trans('concierge::strings.content_not_logged') }}</dt>
                                @endif
                            </details>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="wac-row is-agent">
                @if (filled($message->assistant_message))
                    <div class="wac-bubble wac-agent wac-md">{!! Markdown::render($message->assistant_message) !!}</div>
                @elseif (empty($message->resolved_cards))
                    {{-- 카드 전용 행(#6)은 본문이 없는 것이 정상이다 — 안내문을 띄우지 않는다. --}}
                    <div class="wac-bubble wac-agent">{{ trans('concierge::strings.content_not_logged') }}</div>
                @endif

                {{-- 이 턴에서 결정된 확인 카드(#6) — 채팅 화면이 남기는 것과 같은 기록이다. --}}
                @foreach ($message->resolved_cards ?? [] as $card)
                    <div class="wac-card">
                        <div class="wac-card-head">
                            <span class="wac-card-title">{{ $card['title'] ?? '' }}</span>
                            <span class="wac-card-outcome is-{{ $card['outcome'] ?? 'cancelled' }}">
                                {{ trans('concierge::strings.card_outcome_' . ($card['outcome'] ?? 'cancelled')) }}
                            </span>
                        </div>

                        @if ($card['lines'] ?? [])
                            <dl>
                                @foreach ($card['lines'] as $line)
                                    <dt>{{ $line['label'] ?? '' }}</dt>
                                    <dd>{{ $line['value'] ?? '' }}</dd>
                                @endforeach
                            </dl>
                        @endif

                        @if ($card['diff'] ?? null)
                            <div class="wac-card-diff">
                                <div class="is-del">- {{ $card['diff']['before'] }}</div>
                                <div class="is-add">+ {{ $card['diff']['after'] }}</div>
                            </div>
                        @endif
                    </div>
                @endforeach

                <div class="wac-meta">
                    {{-- 시각 표시는 패널 규약대로 보는 사용자의 프로필 timezone (저장은 UTC). --}}
                    <span>{{ $message->created_at->timezone(auth()->user()?->timezone ?? 'UTC')->format('H:i:s') }}</span>
                    <span class="wac-chip">in {{ number_format($message->input_tokens) }}</span>
                    <span class="wac-chip">out {{ number_format($message->output_tokens) }}</span>

                    @if ($message->status !== ConciergeUsage::STATUS_OK)
                        <span class="wac-chip is-bad">
                            {{ trans('concierge::strings.status_' . $message->status) }}
                        </span>
                    @endif
                </div>

                @if (filled($message->error))
                    <div class="wac-meta">{{ $message->error }}</div>
                @endif
            </div>
        @endforeach
    </div>
</div>
