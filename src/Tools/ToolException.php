<?php

namespace WisdomIT\Concierge\Tools;

use RuntimeException;

/**
 * 도구가 "요청 자체를 수행할 수 없다"고 판단한 경우의 기반 예외.
 *
 * 버그(=Throwable)와 구분하려고 따로 둔다. 이 계열은 모델에게 사실을 돌려주면 되는 상황이고,
 * 확인 카드를 만들다 발생하면 **사용자를 귀찮게 하지 않고** 모델에게 바로 되돌린다.
 */
abstract class ToolException extends RuntimeException {}
