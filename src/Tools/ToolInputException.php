<?php

namespace WisdomIT\Concierge\Tools;

/**
 * 모델이 준 인자로는 작업을 특정할 수 없다.
 *
 * 예: 바꿀 내용이 파일에 없거나, 여러 군데에 있어 어디를 고칠지 확정할 수 없는 경우.
 * 메시지는 **모델이 스스로 고쳐 다시 시도할 수 있게** 구체적으로 쓴다.
 */
final class ToolInputException extends ToolException {}
