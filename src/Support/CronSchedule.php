<?php

namespace WisdomIT\Concierge\Support;

use App\Models\Schedule;
use WisdomIT\Concierge\Tools\ToolInputException;

/**
 * 크론 표현식을 사람 말로, 사람 말을 크론으로 (#41).
 *
 * 🔴 **사용자에게 크론을 묻지 않는다**(#7 원칙: 기술 값을 사용자에게 떠넘기지 않는다).
 *    모델이 "매일 새벽 4시" → `0 4 * * *` 로 옮기고, **카드에는 다시 사람 말로** 되돌려
 *    보여준다. 사용자가 확인하는 것은 크론 문자열이 아니라 "언제 도는가"다.
 */
final class CronSchedule
{
    /**
     * 🔴 **크론은 앱 타임존(UTC)으로 저장된다.** 사용자는 자기 시계로 말한다 —
     *    "새벽 4시"를 그대로 저장하면 실제로는 **낮 1시**에 돈다(실측: APP_TIMEZONE=UTC).
     *    그래서 저장할 때 사용자 시간 → 앱 타임존으로 옮기고, 보여줄 때 되돌린다.
     *
     * ⚠ 이 표시 시간대는 한때 'Asia/Seoul' 로 박혀 있었다(#79). 우리 배포에서는 맞았지만
     *   그건 운영자의 사정이다 — 다른 패널에서는 예약이 엉뚱한 시각에 돌게 된다.
     *   지금은 **요청한 사용자의 프로필 시간대**를 따른다(UserTime).
     */
    private static function offsetHours(): int
    {
        $app = new \DateTimeZone((string) config('app.timezone', 'UTC'));
        $display = new \DateTimeZone(UserTime::timezone());
        $now = new \DateTime('now', $app);

        return intdiv($display->getOffset($now) - $app->getOffset($now), 3600);
    }

    /**
     * 한국 시간 기준으로 받은 크론을 **저장용(앱 타임존)** 으로 옮긴다.
     *
     * @param  array<string, string>  $parts
     * @return array<string, string>
     *
     * @throws ToolInputException
     */
    public static function toStorage(array $parts): array
    {
        return self::shift(self::validate($parts), -self::offsetHours());
    }

    /**
     * 저장된 크론을 **한국 시간 기준**으로 되돌린다(설명·확인용).
     *
     * @param  array<string, string>  $parts
     * @return array<string, string>
     */
    public static function toDisplay(array $parts): array
    {
        return self::shift($parts, self::offsetHours());
    }

    /**
     * 시(hour)를 옮기고, 날을 넘어가면 요일도 함께 민다.
     *
     * ⚠ `일(day_of_month)` 이 지정된 채 날짜를 넘기면 달의 길이 때문에 정확히 옮길 수 없다
     *   ("매달 1일 새벽 4시" → 전달 말일). 그런 조합은 **조용히 틀리게 두지 않고 거절한다.**
     *
     * @param  array<string, string>  $parts
     * @return array<string, string>
     *
     * @throws ToolInputException
     */
    private static function shift(array $parts, int $hours): array
    {
        $hour = $parts['cron_hour'] ?? '*';

        // 시가 '*' 면 매시간이라 타임존과 무관하다.
        if ($hours === 0 || $hour === '*' || !ctype_digit($hour)) {
            return $parts;
        }

        $shifted = (int) $hour + $hours;
        $wrapped = intdiv($shifted < 0 ? $shifted - 23 : $shifted, 24);
        $parts['cron_hour'] = (string) (($shifted % 24 + 24) % 24);

        if ($wrapped === 0) {
            return $parts;
        }

        if (ctype_digit((string) ($parts['cron_day_of_month'] ?? '*'))) {
            // 도구 예외는 모델이 읽고 사용자의 언어로 옮긴다 — 그래서 영어다(#79 관례).
            throw new ToolInputException(
                'A schedule pinned to a day of the month cannot be converted exactly, because '
                . "the time crosses a day boundary in the user's timezone. Ask for a weekday "
                . '("every Monday") or a daily schedule instead.',
            );
        }

        if (ctype_digit((string) ($parts['cron_day_of_week'] ?? '*'))) {
            $parts['cron_day_of_week'] = (string) ((((int) $parts['cron_day_of_week'] + $wrapped) % 7 + 7) % 7);
        }

        return $parts;
    }

    /**
     * 다섯 칸을 검증한다. 크론 라이브러리에 넘기기 전에 형태부터 본다 —
     * 잘못된 표현식은 저장된 뒤 조용히 안 도는 것이 최악이다.
     *
     * @param  array<string, string>  $parts
     * @return array<string, string>
     *
     * @throws ToolInputException
     */
    public static function validate(array $parts): array
    {
        $fields = [
            'cron_minute' => [0, 59],
            'cron_hour' => [0, 23],
            'cron_day_of_month' => [1, 31],
            'cron_month' => [1, 12],
            'cron_day_of_week' => [0, 6],
        ];

        $clean = [];

        foreach ($fields as $field => [$min, $max]) {
            $value = trim((string) ($parts[$field] ?? '*'));

            if ($value === '') {
                $value = '*';
            }

            // `*`, `*/5`, `4`, `1,3,5`, `1-5` 만 받는다. 그 밖의 표현은 이 용도에 필요 없다.
            if (!preg_match('/^(\*|\*\/\d+|\d+(-\d+)?(,\d+(-\d+)?)*)$/', $value)) {
                throw new ToolInputException("'{$value}' 는 이 자리에 쓸 수 없는 값입니다({$field}).");
            }

            foreach (self::numbersIn($value) as $number) {
                if ($number < $min || $number > $max) {
                    throw new ToolInputException("{$field} 는 {$min}~{$max} 사이여야 합니다 ('{$value}').");
                }
            }

            $clean[$field] = $value;
        }

        return $clean;
    }

    /** @return array<int, int> */
    private static function numbersIn(string $value): array
    {
        preg_match_all('/\d+/', str_replace('*/', '', $value), $matches);

        return array_map('intval', $matches[0]);
    }

    /**
     * 사람이 읽는 문장. 흔한 형태를 먼저 맞춰보고, 아니면 있는 그대로 풀어 쓴다.
     *
     * @param  array<string, string>  $parts
     */
    public static function describe(array $parts): string
    {
        $minute = $parts['cron_minute'] ?? '*';
        $hour = $parts['cron_hour'] ?? '*';
        $dayOfMonth = $parts['cron_day_of_month'] ?? '*';
        $month = $parts['cron_month'] ?? '*';
        $dayOfWeek = $parts['cron_day_of_week'] ?? '*';

        // 시각·요일 이름·문장 틀은 전부 번역이 만든다(#79). 시간대는 반드시 함께 적는다 —
        // "04:00" 만 있으면 어느 시계 기준인지 알 수 없고, 예약은 그게 전부다.
        $zone = UserTime::timezone();

        $time = ctype_digit($minute) && ctype_digit($hour)
            ? sprintf('%02d:%02d', (int) $hour, (int) $minute)
            : null;

        if ($time !== null && $dayOfMonth === '*' && $month === '*' && $dayOfWeek === '*') {
            return trans('concierge::strings.cron_daily', ['time' => $time, 'zone' => $zone]);
        }

        if ($time !== null && $dayOfMonth === '*' && $month === '*' && ctype_digit($dayOfWeek)) {
            return trans('concierge::strings.cron_weekly', [
                'day' => trans('concierge::strings.weekday_' . ((int) $dayOfWeek % 7)),
                'time' => $time,
                'zone' => $zone,
            ]);
        }

        if ($time !== null && ctype_digit($dayOfMonth) && $month === '*' && $dayOfWeek === '*') {
            return trans('concierge::strings.cron_monthly', [
                'day' => (int) $dayOfMonth,
                'time' => $time,
                'zone' => $zone,
            ]);
        }

        if (str_starts_with($minute, '*/') && $hour === '*') {
            return trans('concierge::strings.cron_every_minutes', ['n' => (int) substr($minute, 2)]);
        }

        // 어느 틀에도 안 맞는 크론 — 있는 그대로 풀어 쓴다(시간대 환산이 이미 끝난 값이다).
        return trans('concierge::strings.cron_raw', [
            'minute' => $minute, 'hour' => $hour, 'dom' => $dayOfMonth, 'month' => $month, 'dow' => $dayOfWeek,
        ]);
    }

    /** 저장된 스케줄을 사람 말로 — **한국 시간 기준**이다. */
    public static function describeSchedule(Schedule $schedule): string
    {
        return self::describe(self::toDisplay([
            'cron_minute' => $schedule->cron_minute,
            'cron_hour' => $schedule->cron_hour,
            'cron_day_of_month' => $schedule->cron_day_of_month,
            'cron_month' => $schedule->cron_month,
            'cron_day_of_week' => $schedule->cron_day_of_week,
        ]));
    }
}
