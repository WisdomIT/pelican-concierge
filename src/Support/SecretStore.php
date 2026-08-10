<?php

namespace WisdomIT\Concierge\Support;

use App\Models\Server;

/**
 * Secret Variables 플러그인과의 접점 (#12) — 이 파일 밖에서는 그쪽 클래스명을 모른다.
 *
 * 배관은 여기 없다. 그쪽 플러그인이 `ServerVariable::saving` 모델 이벤트로 **모든**
 * 쓰기를 가로채므로(개설의 forceCreate, update_server_variable 의 updateOrCreate 포함),
 * managed 변수의 값은 우리가 아무것도 안 해도 암호화 저장소로 우회된다. 직접 put() 하면
 * 같은 값이 두 번 저장될 뿐이다. 우리가 할 일은 **상태를 알고 사용자에게 말하는 것**이다:
 * 이 값이 어디에 저장되는지, 패널 운영자에게 보이는지.
 *
 * 🔴 판정은 OptionalPlugins::usable 이 먼저다(#13 실측). 설치돼 있어도 꺼진 플러그인은
 *    클래스가 로드되고 isManaged() 도 동작하지만(모델 쿼리라 프로바이더 없이 돈다),
 *    인터셉터도 환경 주입도 등록되지 않았다 — 그때 "암호화 저장됩니다"라고 말하면
 *    거짓말이고, 값은 평문으로 남는다.
 */
final class SecretStore
{
    private const API = 'WisdomIT\\SecretVariables\\SecretVariables';

    /** 플러그인이 설치·활성이라 실제로 값을 우회·재주입하는가. */
    public static function usable(): bool
    {
        return OptionalPlugins::usable('secret-variables') && class_exists(self::API);
    }

    /**
     * 이 변수에 쓴 값이 암호화 저장소로 우회되는가.
     *
     * managed 여부는 관리자가 그쪽 설정에서 변수 이름 단위로 정한다 — 카탈로그가
     * 시크릿이라 선언해도 관리자가 안 올린 이름은 평문으로 남는다(폴백, 이슈에서 결정).
     */
    public static function routes(string $env): bool
    {
        if (!self::usable()) {
            return false;
        }

        $api = self::API;

        return $api::isManaged($env);
    }

    /** 이 서버에 값이 저장돼 있는가 — 값 자체는 그쪽에 getter 가 없어 **읽을 수 없다**(설계). */
    public static function has(Server $server, string $env): bool
    {
        if (!self::usable()) {
            return false;
        }

        $api = self::API;

        return $api::has($server, $env);
    }
}
