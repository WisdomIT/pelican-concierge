<?php

/*
 |  이 파일은 PluginService 가 부팅 때 `config()->set('concierge', require ...)` 로 읽는다.
 |
 |  ⚠ 여기 있는 값은 **설정이 아니라 기본값·선택지**다. 실제 설정은 DB(concierge_settings)에
 |     있고 관리자 화면(Admin → 고급 → AI 도우미 설정)에서 바꾼다.
 |     API 키를 여기나 env 에 두지 않는 이유는 README 의 "왜 DB 인가" 절 참고.
 */

return [
    // ── 신규 설치 시 seed 되는 기본값 ─────────────────────────────
    'model' => 'claude-opus-5',
    'effort' => 'medium',
    'max_tokens' => 8192,
    'daily_message_limit' => 50,

    // ── LLM 공급자별 선택지 (#3) ─────────────────────────────────
    //  모델이 새로 나오면 여기만 고치면 된다. 없는 id 를 고르면 API 가 404 를 낸다.
    //  effort 는 사고 깊이와 전체 토큰 지출을 함께 조절한다 — 공급자마다 어휘가 다르다.
    'providers' => [
        'anthropic' => [
            'label' => 'Anthropic (Claude)',
            'default_model' => 'claude-opus-5',
            'default_effort' => 'medium',
            'models' => [
                'claude-opus-5' => 'Claude Opus 5 (권장 — 도구를 쓰는 에이전트)',
                'claude-sonnet-5' => 'Claude Sonnet 5 (더 저렴)',
                'claude-haiku-4-5' => 'Claude Haiku 4.5 (가장 저렴 · 단순 작업용)',
            ],
            'efforts' => [
                'low' => 'low — 가장 저렴 · 단순한 요청',
                'medium' => 'medium — 균형 (권장)',
                'high' => 'high — 기본값 · 어려운 진단',
                'xhigh' => 'xhigh — 복잡한 다단계 작업',
                'max' => 'max — 비용 무관, 정확도 우선',
            ],
        ],

        'openai' => [
            'label' => 'OpenAI (ChatGPT)',
            'default_model' => 'gpt-5.1',
            'default_effort' => 'medium',
            'models' => [
                'gpt-5.1' => 'GPT-5.1 (도구 사용에 권장)',
                'gpt-5.1-mini' => 'GPT-5.1 mini (더 저렴)',
                'gpt-5' => 'GPT-5',
                'gpt-5-mini' => 'GPT-5 mini',
            ],
            // Responses API 의 reasoning.effort 어휘.
            'efforts' => [
                'minimal' => 'minimal — 추론 최소화 · 가장 빠름',
                'low' => 'low — 가벼운 추론',
                'medium' => 'medium — 균형 (권장)',
                'high' => 'high — 깊은 추론',
            ],
        ],

        'openai-compatible' => [
            'label' => 'OpenAI 호환 (로컬: Ollama · vLLM · llama.cpp)',
            'default_model' => '',
            'default_effort' => null,
            // 로컬 엔드포인트의 모델 이름은 설치마다 다르다 — 자유 입력.
            'models' => [],
            'efforts' => [],
        ],
    ],
];
