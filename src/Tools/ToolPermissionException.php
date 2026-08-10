<?php

namespace WisdomIT\Concierge\Tools;

/**
 * 서버에는 접근할 수 있지만 그 작업을 할 권한은 없다(Pelican 의 서브유저 권한).
 *
 * "서버를 못 찾음"과 구분해서 던진다 — 이쪽은 사용자가 자기 서버라는 걸 이미 아는
 * 상황이라, 권한이 없다고 정확히 알려주는 편이 낫다.
 */
final class ToolPermissionException extends ToolException {}
