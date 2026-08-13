<?php

namespace WisdomIT\Concierge\Catalog;

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
    public static function issues(string $yaml): array
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
            $line = self::lineOf($yaml, (string) $key);

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

        return $issues;
    }

    /** 저장을 막아야 하는 문제만. */
    public static function errors(string $yaml): array
    {
        return array_values(array_filter(self::issues($yaml), fn (array $i) => $i['severity'] === 'error'));
    }

    /**
     * 키별 속내용 검사. 여기서 잡는 것들은 전부 **개설할 때에야 터지던** 것이다.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function inspect(string $key, mixed $value, string $yaml, ?int $line): array
    {
        $issues = [];

        if ($key === 'ports') {
            if (!isset($value['count'])) {
                $issues[] = self::issue($line, 'catalog_check_ports_count_missing', [], 'error');
            } elseif (!is_int($value['count']) || $value['count'] < 1) {
                // 형태 이름이 아니라 **적힌 값**을 보여준다 — "지금: 정수" 는 아무 도움이 안 된다.
                $issues[] = self::issue($line, 'catalog_check_ports_count', [
                    'value' => is_scalar($value['count']) ? var_export($value['count'], true) : self::describe($value['count']),
                ], 'error');
            }

            foreach ((array) ($value['protocol'] ?? []) as $protocol) {
                if (!in_array($protocol, ['tcp', 'udp'], true)) {
                    $issues[] = self::issue($line, 'catalog_check_protocol', ['value' => (string) $protocol], 'error');
                }
            }

            // derive 는 "몇 번째 할당을 어느 변수에 넣을지"다 — 범위를 벗어나면 개설이 실패한다.
            foreach ((array) ($value['derive'] ?? []) as $i => $derive) {
                $index = $derive['index'] ?? null;

                if (!isset($derive['env']) || !is_int($index)) {
                    $issues[] = self::issue($line, 'catalog_check_derive_shape', ['n' => $i + 1], 'error');
                } elseif (is_int($value['count'] ?? null) && $index >= $value['count']) {
                    $issues[] = self::issue($line, 'catalog_check_derive_range', [
                        'env' => (string) $derive['env'], 'index' => $index, 'count' => $value['count'],
                    ], 'error');
                }
            }
        }

        if ($key === 'post_install') {
            foreach ($value as $i => $step) {
                $at = ['n' => $i + 1];

                if (!is_array($step) || !isset($step['type'])) {
                    $issues[] = self::issue($line, 'catalog_check_step_type_missing', $at, 'error');

                    continue;
                }

                $required = match ($step['type']) {
                    'file_replace' => ['path', 'from', 'to'],
                    'json_vmarg' => ['path', 'arg', 'value_ratio'],
                    default => null,
                };

                if ($required === null) {
                    $issues[] = self::issue($line, 'catalog_check_step_type', $at + ['type' => (string) $step['type']], 'error');

                    continue;
                }

                foreach (array_diff($required, array_keys($step)) as $missing) {
                    $issues[] = self::issue($line, 'catalog_check_step_missing', $at + [
                        'type' => (string) $step['type'], 'field' => $missing,
                    ], 'error');
                }
            }
        }

        if ($key === 'secrets') {
            foreach ($value as $i => $name) {
                if (!is_string($name) || trim($name) === '') {
                    $issues[] = self::issue($line, 'catalog_check_secret_shape', ['n' => $i + 1], 'error');
                }
            }
        }

        if ($key === 'mods' && ($value['supported'] ?? false) === true && blank($value['path'] ?? null)) {
            // 설치 경로를 모르면 모드를 어디에 둘지 알 수 없다 — 설치가 조용히 빗나간다.
            $issues[] = self::issue($line, 'catalog_check_mods_path', [], 'error');
        }

        if ($key === 'defaults') {
            foreach ($value as $env => $default) {
                if (is_array($default)) {
                    $issues[] = self::issue(self::lineOf($yaml, (string) $env) ?? $line, 'catalog_check_default_scalar', ['key' => (string) $env], 'error');
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
            'map' => is_array($value) && !array_is_list($value),
            'list' => is_array($value) && array_is_list($value),
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
     * 원문에서 그 키가 처음 나오는 줄. 파서가 위치를 주지 않으므로 직접 찾는다 —
     * 정확한 지점은 아니지만 "어디를 봐야 하는지"는 알려 준다.
     */
    private static function lineOf(string $yaml, string $key): ?int
    {
        foreach (explode("\n", $yaml) as $i => $line) {
            if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*:/', $line)) {
                return $i + 1;
            }
        }

        return null;
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
