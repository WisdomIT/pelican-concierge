{{--
    줄 번호가 붙은 YAML 편집기 (#81).

    텍스트에어리어에는 거터가 없다. 그런데 YAML 은 **줄이 곧 주소**다 — 검사 결과가
    "3번째 줄"이라고 말하는데 정작 편집기에서 세어야 한다면 그 안내는 반쪽이다.

    ⚠ 줄 수는 Alpine 이 **로컬에서** 센다. 서버에 물으면 글자마다 왕복이 생긴다.
      값은 칸을 벗어날 때만 보낸다(검사도 그때 다시 돈다).
--}}
@php
    $statePath = $getStatePath();
    // 문제가 있는 줄은 거터에서 바로 보인다 — 목록과 편집기를 눈으로 잇는 부분이다.
    $issues = \WisdomIT\Concierge\Catalog\AdvancedYaml::issues((string) $getState());
    $errorLines = collect($issues)->where('severity', 'error')->pluck('line')->filter()->unique()->values()->all();
    $warningLines = collect($issues)->where('severity', 'warning')->pluck('line')->filter()->unique()->values()->all();
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        x-data="{
            value: @js((string) $getState()),
            lines: 1,
            errorLines: @js($errorLines),
            warningLines: @js($warningLines),
            count() { this.lines = Math.max(1, this.value.split('\n').length) },
            init() { this.count() },
        }"
        class="cg-yaml"
        wire:ignore.self
    >
        <div class="cg-yaml-gutter" x-ref="gutter">
            <template x-for="n in lines" :key="n">
                <div
                    class="cg-yaml-line"
                    :class="{
                        'cg-yaml-line-error': errorLines.includes(n),
                        'cg-yaml-line-warning': ! errorLines.includes(n) && warningLines.includes(n),
                    }"
                    x-text="n"
                ></div>
            </template>
        </div>

        <textarea
            x-ref="editor"
            x-model="value"
            @input="count()"
            @scroll="$refs.gutter.scrollTop = $refs.editor.scrollTop"
            {{-- 값은 칸을 벗어날 때 보낸다 — 그때 검사가 다시 돌고 거터가 갱신된다. --}}
            @blur="$wire.set('{{ $statePath }}', value)"
            rows="16"
            spellcheck="false"
            wrap="off"
            @disabled($isDisabled())
            class="cg-yaml-input fi-input"
        ></textarea>
    </div>

    <style>
        .cg-yaml {
            display: flex;
            border: 1px solid var(--gray-300, #d1d5db);
            border-radius: .5rem;
            overflow: hidden;
            background: var(--gray-50, #f9fafb);
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: .8125rem;
            line-height: 1.6;
        }
        :where(.dark) .cg-yaml { border-color: var(--gray-700, #374151); background: var(--gray-900, #111827); }

        .cg-yaml-gutter {
            flex: none;
            overflow: hidden;
            padding: .55rem .5rem .55rem .65rem;
            text-align: right;
            color: var(--gray-400, #9ca3af);
            user-select: none;
            border-right: 1px solid var(--gray-200, #e5e7eb);
        }
        :where(.dark) .cg-yaml-gutter { border-right-color: var(--gray-700, #374151); }

        /* 문제가 있는 줄은 번호로 표시한다 — 목록의 "3번째 줄"과 눈으로 이어진다. */
        .cg-yaml-line { min-width: 1.6em; }
        .cg-yaml-line-error { color: #dc2626; font-weight: 600; }
        .cg-yaml-line-warning { color: #d97706; font-weight: 600; }

        .cg-yaml-input {
            flex: 1 1 auto;
            min-width: 0;
            padding: .55rem .7rem;
            border: 0;
            border-radius: 0;
            background: transparent;
            resize: vertical;
            white-space: pre;
            overflow-x: auto;
            font: inherit;
            box-shadow: none;
        }
        .cg-yaml-input:focus { outline: none; box-shadow: none; }
    </style>
</x-dynamic-component>
