<?php

namespace WisdomIT\WisdomAiAssistant\Tools;

/**
 * 모델이 지정한 서버를 요청자의 접근 가능 목록에서 찾지 못했다.
 *
 * 오타일 수도, 남의 서버를 지어낸 것일 수도 있다 — **둘을 구분하지 않는다.**
 * 어느 쪽이든 모델에게는 "없다"고만 답한다. 존재 여부를 알려주는 것 자체가 정보 누출이다.
 */
final class ServerNotFoundException extends ToolException {}
