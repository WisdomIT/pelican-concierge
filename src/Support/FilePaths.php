<?php

namespace WisdomIT\Concierge\Support;

use WisdomIT\Concierge\Tools\ToolInputException;

/**
 * 파일 도구가 만지는 경로·주소의 안전 규칙 (#37).
 *
 * 🔴 **규칙을 도구마다 흩어두지 않는다.** 삭제는 되돌릴 수 없고 다운로드는 서버가 외부로
 *    나가는 통로다 — 조건이 여러 곳에 있으면 새 도구를 만들 때 반드시 빠뜨린다.
 */
final class FilePaths
{
    /** 지우면 서버가 못 뜨는 것들. 이름이 **정확히** 일치할 때만 막는다. */
    private const PROTECTED_NAMES = [
        'server.jar', 'server.properties', 'eula.txt',
        'start.sh', 'entrypoint.sh', 'run.sh',
        'RustDedicated', 'srcds_run', 'factorio',
    ];

    /** 통째로 지우면 곤란한 최상위 디렉터리. **안쪽 개별 파일은 지울 수 있다.** */
    private const PROTECTED_DIRS = ['plugins', 'mods', 'oxide', 'bin', 'lib'];

    /**
     * 알려진 배포처.
     *
     * 🔴 모드 설치(#16)는 Modrinth·uMod **API 가 준** URL 만 써서 안전했다. 사용자가 부르는
     *    대로 받게 하면 성격이 다르다 — 여기 없는 곳은 받지 않고 업로드 화면을 안내한다.
     */
    private const TRUSTED_HOSTS = [
        'modrinth.com', 'cdn.modrinth.com',
        'github.com', 'raw.githubusercontent.com', 'objects.githubusercontent.com',
        'spigotmc.org', 'curseforge.com', 'forgecdn.net',
        'papermc.io', 'hangarcdn.papermc.io',
        'fabricmc.net', 'minecraftforge.net',
        'umod.org',
    ];

    /**
     * `/`, `.`, `..` 를 걷어내고 서버 루트 기준 상대 경로로 만든다.
     *
     * ⚠ **카드를 만들기 전에** 정규화해야 한다. 카드에 보여준 문자열과 실제로 지워질 대상이
     *   다르면 확인의 의미가 없다.
     */
    public static function normalize(string $path): string
    {
        $parts = [];

        foreach (explode('/', str_replace('\\', '/', trim($path))) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                array_pop($parts);

                continue;
            }

            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    /**
     * 쓰거나 만들어도 되는 경로. 루트 자체만 막는다.
     *
     * @throws ToolInputException
     */
    public static function assertWritable(string $path): string
    {
        $clean = self::normalize($path);

        if ($clean === '') {
            throw new ToolInputException('A path is required.');
        }

        return $clean;
    }

    /**
     * 지워도 되는 경로.
     *
     * @throws ToolInputException
     */
    public static function assertDeletable(string $path): string
    {
        $clean = self::normalize($path);

        if ($clean === '') {
            throw new ToolInputException('The whole server folder cannot be deleted. To delete the server itself, point at the delete screen.');
        }

        $name = basename($clean);
        $isTopLevel = !str_contains($clean, '/');

        if (in_array($name, self::PROTECTED_NAMES, true)) {
            throw new ToolInputException("'{$name}' is required for the server to run and cannot be deleted.");
        }

        if ($isTopLevel && in_array($name, self::PROTECTED_DIRS, true)) {
            throw new ToolInputException("The whole '{$name}' folder cannot be deleted. Files inside it can be deleted one by one.");
        }

        return $clean;
    }

    /**
     * 받아도 되는 주소.
     *
     * @throws ToolInputException
     */
    public static function assertDownloadable(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);

        if (($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === '') {
            throw new ToolInputException('Only https URLs can be downloaded.');
        }

        $host = strtolower($parts['host']);

        // 사설망·루프백은 화이트리스트와 **무관하게** 막는다(SSRF 안전망).
        if (filter_var($host, FILTER_VALIDATE_IP) !== false
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new ToolInputException('Downloads from private network addresses are not allowed.');
        }

        foreach (self::TRUSTED_HOSTS as $trusted) {
            if ($host === $trusted || str_ends_with($host, '.' . $trusted)) {
                return $url;
            }
        }

        throw new ToolInputException(
            "'{$host}' is not a known distributor, so it cannot be downloaded. "
            . 'The user has to upload the file themselves — point at the files screen with suggest_page.',
        );
    }

    /** 화면에 보여줄 이름. 빈 경로는 루트다. */
    public static function label(string $path): string
    {
        return self::normalize($path) ?: '/';
    }
}
