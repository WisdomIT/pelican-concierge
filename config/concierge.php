<?php

/*
 |  이 파일은 PluginService 가 부팅 때 `config()->set('concierge', require ...)` 로 읽는다.
 |
 |  ⚠ 여기 있는 값은 **설정이 아니라 기본값·선택지**다. 실제 설정은 DB(concierge_settings)에
 |     있고 관리자 화면(Admin → 고급 → AI Agent 설정)에서 바꾼다.
 |     API 키를 여기나 env 에 두지 않는 이유는 README 의 "왜 DB 인가" 절 참고.
 */

return [
    // ── 신규 설치 시 seed 되는 기본값 ─────────────────────────────
    'model' => 'claude-opus-5',
    'effort' => 'medium',
    'max_tokens' => 8192,
    // 사용 한도 규칙(#4): 기준(messages|tokens) × 범위(user|panel) × 주기(hour|day|week|month).
    // 여러 개면 먼저 걸린 것이 막는다. 빈 목록 = 무제한.
    'usage_limits' => [
        ['metric' => 'messages', 'scope' => 'user', 'period' => 'day', 'amount' => 50],
    ],

    // ── LLM 공급자별 선택지 (#3) ─────────────────────────────────
    //  모델이 새로 나오면 여기만 고치면 된다. 없는 id 를 고르면 API 가 404 를 낸다.
    //  effort 는 사고 깊이와 전체 토큰 지출을 함께 조절한다 — 공급자마다 어휘가 다르다.
    //
    //  ⚠ 여기에는 **데이터만** 둔다 — 화면에 그대로 찍히는 문구를 넣지 말 것(#79).
    //     한때 'claude-opus-5' => 'Claude Opus 5 (권장)' 처럼 라벨을 박아 뒀는데,
    //     번역 계층을 거치지 않으니 영어 사용자에게 "(권장)" 이 한국어로 나왔다.
    //     · 모델 이름은 고유명사라 그대로 둔다(번역 대상이 아니다)
    //     · '권장' 표시와 effort 설명문은 **문장**이라 lang 파일이 만든다
    //       (effort 설명은 strings.php 의 effort_{id})
    'providers' => [
        'anthropic' => [
            'label' => 'Anthropic (Claude)',
            'short' => 'Anthropic',
            'badge' => 'Anthropic',
            'default_model' => 'claude-opus-5',
            'default_effort' => 'medium',
            // id => 표시 이름(고유명사). 권장 표시는 default_model 이 정한다.
            'models' => [
                'claude-opus-5' => 'Claude Opus 5',
                'claude-sonnet-5' => 'Claude Sonnet 5',
                'claude-haiku-4-5' => 'Claude Haiku 4.5',
            ],
            // 설명문은 lang 이 만든다 — 여기서는 지원 여부와 순서만 정한다.
            'efforts' => ['low', 'medium', 'high', 'xhigh', 'max'],
        ],

        'openai' => [
            'label' => 'OpenAI (ChatGPT)',
            'short' => 'OpenAI',
            'badge' => 'OpenAI',
            'default_model' => 'gpt-5.1',
            'default_effort' => 'medium',
            'models' => [
                'gpt-5.1' => 'GPT-5.1',
                'gpt-5.1-mini' => 'GPT-5.1 mini',
                'gpt-5' => 'GPT-5',
                'gpt-5-mini' => 'GPT-5 mini',
            ],
            // Responses API 의 reasoning.effort 어휘.
            'efforts' => ['minimal', 'low', 'medium', 'high'],
        ],

        'gemini' => [
            'label' => 'Google (Gemini)',
            'short' => 'Gemini',
            'badge' => 'Gemini',
            'default_model' => 'gemini-3.1-pro-preview',
            'default_effort' => null,
            // 2.5 세대는 신규 키에 404("no longer available to new users") — 뺐다(#35 조사).
            'models' => [
                'gemini-3.1-pro-preview' => 'Gemini 3.1 Pro',
                'gemini-3.6-flash' => 'Gemini 3.6 Flash',
            ],
            // thinking 어휘가 모델 세대마다 달라(3: thinkingLevel, 2.5: thinkingBudget)
            // 기본 동작(dynamic)에 맡긴다 — 어댑터 주석 참고.
            'efforts' => [],
        ],

        'openai-compatible' => [
            // 이 항목만 라벨이 설명문이다 — lang 의 provider_openai_compatible 이 채운다.
            'label' => null,
            'short' => '',
            'badge' => null,
            'default_model' => '',
            'default_effort' => null,
            // 로컬 엔드포인트의 모델 이름은 설치마다 다르다 — 자유 입력.
            'models' => [],
            'efforts' => [],
        ],
    ],
];
