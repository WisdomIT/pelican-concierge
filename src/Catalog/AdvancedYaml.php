<?php

namespace WisdomIT\Concierge\Catalog;

use App\Models\Egg;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * 카탈로그 "고급" 칸의 검사 (#81).
 *
 * 문법만 보면 모자란다 — `ports: {count: "one"}` 은 올바른 YAML 이지만 개설할 때 터진다.
 * 그래서 **아는 키인지, 형태가 맞는지**까지 본다.
 *
 * 🔴 **모르는 키를 오류로 만들지 않는다.** 카탈로그는 운영자의 데이터이고, 우리가 아직
 *    모르는 키를 쓸 수도 있다. 오타를 알려는 주되(경고) 저장은 막지 않는다 — 막으면
 *    플러그인이 따라잡을 때까지 그 배포는 아무것도 못 고친다.
 *
 * ⚠ 줄 번호는 **원문에서 그 키를 찾아** 붙인다. 파서는 위치를 돌려주지 않는다.
 */
final class AdvancedYaml
{
    /** SecretMasker 의 안전망과 **같은 패턴** — 두 곳이 갈리면 검사가 거짓말을 한다. */
    private const SECRET_NAME_PATTERN = '/(PASSWORD|PASSWD|SECRET|TOKEN|LICENSE|APIKEY|API_KEY|_KEY|^KEY$|PASS)/i';

    /** 폼이 이미 다루는 칸 — 여기 적으면 저장할 때 버려지므로 미리 알려 준다. */
    private const FORM_KEYS = [
        'id', 'name', 'summary', 'egg', 'available', 'unavailable_reason', 'sizes', 'ask',
        'name_translations', 'summary_translations',
    ];

    /** 아는 키와 기대하는 형태. 실제 카탈로그 18종에서 뽑았다. */
    private const SHAPES = [
        'query' => 'string',
        'query_port_variable' => 'string',
        'java_from' => 'string',
        'player_var' => 'string_or_null',
        'install_min_mb' => 'int',
        'defaults' => 'map',
        'caps' => 'map',
        'verified' => 'map',
        'mods' => 'map',
        'ports' => 'map',
        'secrets' => 'list',
        'notes' => 'list',
        'post_install' => 'list',
    ];

    /**
     * @return array<int, array{line: ?int, message: string, severity: string}>
     *         빈 배열 = 이상 없음. severity: error(저장 불가) | warning(저장은 된다)
     */
    public static function issues(string $yaml, ?string $eggName = null): array
    {
        if (blank(trim($yaml))) {
            return [];
        }

        try {
            $parsed = Yaml::parse($yaml);
        } catch (ParseException $exception) {
            return [[
                'line' => $exception->getParsedLine() > 0 ? $exception->getParsedLine() : null,
                'message' => trans('concierge::strings.catalog_yaml_invalid', ['error' => $exception->getMessage()]),
                'severity' => 'error',
            ]];
        }

        if (!is_array($parsed)) {
            return [['line' => null, 'message' => trans('concierge::strings.catalog_yaml_not_map'), 'severity' => 'error']];
        }

        $issues = [];

        foreach ($parsed as $key => $value) {
            $line = self::lineOfPath($yaml, [(string) $key]);

            if (in_array($key, self::FORM_KEYS, true)) {
                $issues[] = self::issue($line, 'catalog_check_form_key', ['key' => $key], 'warning');

                continue;
            }

            if (!isset(self::SHAPES[$key])) {
                $issues[] = self::issue($line, 'catalog_check_unknown_key', ['key' => $key], 'warning');

                continue;
            }

            if (!self::matches($value, self::SHAPES[$key])) {
                $issues[] = self::issue($line, 'catalog_check_wrong_type', [
                    'key' => $key,
                    'expected' => trans('concierge::strings.catalog_type_' . self::SHAPES[$key]),
                    'actual' => self::describe($value),
                ], 'error');

                continue;
            }

            $issues = array_merge($issues, self::inspect((string) $key, $value, $yaml, $line));
        }

        return array_merge($issues, self::againstEgg($parsed, $yaml, $eggName));
    }

    /**
     * egg 와 대조한다 — 카탈로그만 봐서는 알 수 없는 것들이다(scripts/validate-catalog.py
     * 가 하던 일을 화면 안으로 들였다).
     *
     * 둘 다 **경고**다: egg 는 나중에 갈릴 수 있고, 저장을 막으면 egg 를 고치는 동안
     * 카탈로그를 손댈 수 없게 된다.
     *
     * @param  array<string, mixed>  $parsed
     * @return array<int, array<string, mixed>>
     */
    private static function againstEgg(array $parsed, string $yaml, ?string $eggName): array
    {
        if (blank($eggName)) {
            return [];
        }

        $egg = Egg::query()->where('name', $eggName)->first();

        if ($egg === null) {
            return [];
        }

        $vars = $egg->variables->pluck('env_variable')->all();
        $issues = [];

        // 1) 이름만 봐도 비밀인데 선언되지 않은 변수. 이름 패턴 안전망이 가려 주긴 하지만,
        //    게임별로 무엇이 비밀인지 아는 곳은 카탈로그뿐이다 — 안전망은 최후의 방어선이지
        //    선언을 대신하지 않는다. (실측: 6종이 선언 없이 안전망에만 기대고 있었다.)
        $declared = (array) ($parsed['secrets'] ?? []);
        $undeclared = array_values(array_filter(
            $vars,
            fn (string $v) => preg_match(self::SECRET_NAME_PATTERN, $v) && !in_array($v, $declared, true),
        ));

        if ($undeclared !== []) {
            $issues[] = self::issue(
                self::lineOfPath($yaml, ['secrets']),
                'catalog_check_secret_undeclared',
                ['vars' => implode(', ', $undeclared)],
                'warning',
            );
        }

        // 2) 없는 egg 변수를 가리키는 값 — 조용히 무시되므로 눈으로는 못 찾는다.
        foreach (self::envReferences($parsed) as [$path, $env]) {
            if (!in_array($env, $vars, true)) {
                $issues[] = self::issue(
                    self::lineOfPath($yaml, $path),
                    'catalog_check_env_unknown',
                    ['env' => $env, 'egg' => $eggName],
                    'warning',
                );
            }
        }

        return $issues;
    }

    /**
     * egg 변수 이름이 들어가는 자리 전부.
     *
     * @param  array<string, mixed>  $parsed
     * @return array<int, array{0: array<int, string|int>, 1: string}>
     */
    private static function envReferences(array $parsed): array
    {
        $refs = [];

        foreach (['player_var', 'java_from', 'query_port_variable'] as $key) {
            if (is_string($parsed[$key] ?? null) && $parsed[$key] !== '') {
                $refs[] = [[$key], $parsed[$key]];
            }
        }

        foreach (['caps', 'defaults'] as $key) {
            foreach ((array) ($parsed[$key] ?? []) as $env => $ignored) {
                $refs[] = [[$key, (string) $env], (string) $env];
            }
        }

        foreach ((array) ($parsed['secrets'] ?? []) as $i => $env) {
            if (is_string($env)) {
                $refs[] = [['secrets', $i], $env];
            }
        }

        foreach ((array) ($parsed['ports']['derive'] ?? []) as $i => $derive) {
            if (is_string($derive['env'] ?? null)) {
                $refs[] = [['ports', 'derive', $i], $derive['env']];
            }
        }

        return $refs;
    }

    /** 저장을 막아야 하는 문제만. */
    public static function errors(string $yaml, ?string $eggName = null): array
    {
        return array_values(array_filter(
            self::issues($yaml, $eggName),
            fn (array $i) => $i['severity'] === 'error',
        ));
    }

    /**
     * 키별 속내용 검사. 여기서 잡는 것들은 전부 **개설할 때에야 터지던** 것이다.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function inspect(string $key, mixed $value, string $yaml, ?int $line): array
    {
        $issues = [];
        // 경로의 줄. 못 찾으면 부모 줄로 물러난다.
        $at = fn (array $path) => self::lineOfPath($yaml, array_merge([$key], $path)) ?? $line;

        if ($key === 'ports') {
            if (!isset($value['count'])) {
                $issues[] = self::issue($at([]), 'catalog_check_ports_count_missing', [], 'error');
            } elseif (!is_int($value['count']) || $value['count'] < 1) {
                // 형태 이름이 아니라 **적힌 값**을 보여준다 — "지금: 정수" 는 아무 도움이 안 된다.
                $issues[] = self::issue($at(['count']), 'catalog_check_ports_count', [
                    'value' => is_scalar($value['count']) ? var_export($value['count'], true) : self::describe($value['count']),
                ], 'error');
            }

            foreach ((array) ($value['protocol'] ?? []) as $protocol) {
                if (!in_array($protocol, ['tcp', 'udp'], true)) {
                    $issues[] = self::issue($at(['protocol']), 'catalog_check_protocol', ['value' => (string) $protocol], 'error');
                }
            }

            // derive 는 "몇 번째 할당을 어느 변수에 넣을지"다 — 범위를 벗어나면 개설이 실패한다.
            foreach ((array) ($value['derive'] ?? []) as $i => $derive) {
                $index = $derive['index'] ?? null;

                if (!isset($derive['env']) || !is_int($index)) {
                    $issues[] = self::issue($at(['derive', $i]), 'catalog_check_derive_shape', ['n' => $i + 1], 'error');
                } elseif (is_int($value['count'] ?? null) && $index >= $value['count']) {
                    $issues[] = self::issue($at(['derive', $i]), 'catalog_check_derive_range', [
                        'env' => (string) $derive['env'], 'index' => $index, 'count' => $value['count'],
                    ], 'error');
                }
            }
        }

        if ($key === 'post_install') {
            foreach ($value as $i => $step) {
                $where = ['n' => $i + 1];

                if (!is_array($step) || !isset($step['type'])) {
                    $issues[] = self::issue($at([$i]), 'catalog_check_step_type_missing', $where, 'error');

                    continue;
                }

                $required = match ($step['type']) {
                    'file_replace' => ['path', 'from', 'to'],
                    'json_vmarg' => ['path', 'arg', 'value_ratio'],
                    default => null,
                };

                if ($required === null) {
                    $issues[] = self::issue($at([$i]), 'catalog_check_step_type', $where + ['type' => (string) $step['type']], 'error');

                    continue;
                }

                foreach (array_diff($required, array_keys($step)) as $missing) {
                    $issues[] = self::issue($at([$i, $missing]) ?? $at([$i]), 'catalog_check_step_missing', $where + [
                        'type' => (string) $step['type'], 'field' => $missing,
                    ], 'error');
                }
            }
        }

        if ($key === 'secrets') {
            foreach ($value as $i => $name) {
                if (!is_string($name) || trim($name) === '') {
                    $issues[] = self::issue($at([$i]), 'catalog_check_secret_shape', ['n' => $i + 1], 'error');
                }
            }
        }

        // ⚠ mods.path 를 요구하는 규칙이 있었는데 **그 값을 쓰는 코드가 없다.** 근거 없는
        //   규칙이라 걷어냈다 — 우리 카탈로그 5종이 걸렸고, 확인해 보니 워크숍 ID·모드 탭
        //   방식이라 경로 자체가 필요 없는 게임들이었다. 검사기가 사실보다 앞서면 안 된다.

        if ($key === 'defaults') {
            foreach ($value as $env => $default) {
                if (is_array($default)) {
                    $issues[] = self::issue($at([(string) $env]), 'catalog_check_default_scalar', ['key' => (string) $env], 'error');
                }
            }
        }

        return $issues;
    }

    private static function matches(mixed $value, string $shape): bool
    {
        return match ($shape) {
            'string' => is_string($value),
            'string_or_null' => $value === null || is_string($value),
            'int' => is_int($value),
            // ⚠ 빈 배열은 PHP 에서 목록과 묶음을 구분할 수 없다(`{}` 도 `[]` 로 온다).
            //   비어 있으면 통과시킨다 — 실측에서 우리 카탈로그의 `defaults: {}` 가
            //   전부 "목록이 왔다"로 걸렸다.
            'map' => is_array($value) && ($value === [] || !array_is_list($value)),
            'list' => is_array($value) && ($value === [] || array_is_list($value)),
            default => true,
        };
    }

    /** 사람이 읽을 형태 이름 — "문자열을 넣어야 하는데 목록이 왔다"고 말할 수 있어야 한다. */
    private static function describe(mixed $value): string
    {
        return match (true) {
            $value === null => trans('concierge::strings.catalog_type_null'),
            is_bool($value) => trans('concierge::strings.catalog_type_bool'),
            is_int($value), is_float($value) => trans('concierge::strings.catalog_type_int'),
            is_string($value) => trans('concierge::strings.catalog_type_string'),
            is_array($value) && array_is_list($value) => trans('concierge::strings.catalog_type_list'),
            is_array($value) => trans('concierge::strings.catalog_type_map'),
            default => gettype($value),
        };
    }

    /**
     * 원문에서 그 **경로**가 있는 줄. 파서는 위치를 돌려주지 않으므로 직접 찾는다.
     *
     * ⚠ 최상위 키만 찾으면 중첩된 문제가 전부 부모 줄로 보고된다 — 14번째 줄이 틀렸는데
     *   12번째 줄이라고 말하던 원인이다(실측). 경로를 따라 블록 안으로 들어간다.
     *
     * @param  array<int, string|int>  $path  예: ['ports','count'] · ['post_install', 1]
     */
    private static function lineOfPath(string $yaml, array $path): ?int
    {
        $lines = explode("\n", $yaml);
        $from = 0;
        $until = count($lines);
        $indent = 0;
        $found = null;

        foreach ($path as $segment) {
            $line = is_int($segment)
                ? self::findItem($lines, $from, $until, $indent, $segment)
                : self::findKey($lines, $from, $until, $indent, (string) $segment);

            if ($line === null) {
                // 못 찾으면 여기까지 좁힌 위치라도 돌려준다 — 없는 것보다 낫다.
                return $found;
            }

            $found = $line + 1;
            $indent = self::indentOf($lines[$line]) + 1;
            $from = $line + 1;
            $until = self::blockEnd($lines, $from, self::indentOf($lines[$line]));
        }

        return $found;
    }

    /** @param array<int, string> $lines */
    private static function findKey(array $lines, int $from, int $until, int $minIndent, string $key): ?int
    {
        for ($i = $from; $i < $until; $i++) {
            if (self::indentOf($lines[$i]) >= $minIndent
                && preg_match('/^\s*-?\s*' . preg_quote($key, '/') . '\s*:/', $lines[$i])) {
                return $i;
            }
        }

        return null;
    }

    /**
     * 목록의 n 번째 항목(0 부터) 이 시작하는 줄.
     *
     * @param array<int, string> $lines
     */
    private static function findItem(array $lines, int $from, int $until, int $minIndent, int $index): ?int
    {
        $seen = -1;

        for ($i = $from; $i < $until; $i++) {
            if (self::indentOf($lines[$i]) >= $minIndent && preg_match('/^\s*-\s/', $lines[$i])) {
                $seen++;

                if ($seen === $index) {
                    return $i;
                }
            }
        }

        return null;
    }

    /** 그 줄이 여는 블록이 끝나는 지점(들여쓰기가 되돌아오는 첫 줄). */
    private static function blockEnd(array $lines, int $from, int $indent): int
    {
        for ($i = $from; $i < count($lines); $i++) {
            if (trim($lines[$i]) === '' || str_starts_with(trim($lines[$i]), '#')) {
                continue;
            }

            if (self::indentOf($lines[$i]) <= $indent) {
                return $i;
            }
        }

        return count($lines);
    }

    private static function indentOf(string $line): int
    {
        return strlen($line) - strlen(ltrim($line, ' '));
    }

    /**
     * @param  array<string, mixed>  $replace
     * @return array{line: ?int, message: string, severity: string}
     */
    private static function issue(?int $line, string $key, array $replace, string $severity): array
    {
        return [
            'line' => $line,
            'message' => trans("concierge::strings.{$key}", $replace),
            'severity' => $severity,
        ];
    }
}
