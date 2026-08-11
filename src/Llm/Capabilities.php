<?php

namespace WisdomIT\Concierge\Llm;

/**
 * 공급자가 할 수 있는 것 (#3). 설정 화면이 이걸 보고 폼을 바꾼다 —
 * 지원 안 하는 기능은 런타임에 터지는 게 아니라 화면에서 꺼져 있어야 한다.
 */
final class Capabilities
{
    public function __construct(
        /** 도구 호출. false 인 공급자는 출하 대상이 아니다 — 이 플러그인의 존재 이유가 도구다. */
        public readonly bool $supportsTools,
        /** 서버 측(또는 네이티브) 웹 검색. 없으면 설정의 검색 토글이 비활성화된다. */
        public readonly bool $supportsWebSearch,
        /** 사고 깊이(effort) 조절. 없으면 설정에서 숨긴다. */
        public readonly bool $supportsEffort,
        /** base URL 입력이 필요한가(로컬 OpenAI 호환 엔드포인트). */
        public readonly bool $needsBaseUrl,
    ) {}
}
