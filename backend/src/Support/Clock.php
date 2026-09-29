<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * The time, for everything the app decides with it: when a run is due, how long a crawl delay has left,
 * when a lock or a cache entry expires. Always UTC.
 *
 * One place so the tests can move it (travel()) the way a closed tab lets an hour go by, without sleeping.
 * Real waiting (usleep between requests) still takes real time.
 */
final class Clock
{
    private static float $offset = 0.0;

    public static function now(): DateTimeImmutable
    {
        return self::fromMicrotime(self::microtime());
    }

    public static function microtime(): float
    {
        return microtime(true) + self::$offset;
    }

    public static function timestamp(): int
    {
        return (int) floor(self::microtime());
    }

    public static function fromMicrotime(float $microtime): DateTimeImmutable
    {
        return (DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $microtime), self::utc()) ?: new DateTimeImmutable('@'.(int) $microtime))
            ->setTimezone(self::utc());
    }

    public static function fromTimestamp(int $timestamp): DateTimeImmutable
    {
        return (new DateTimeImmutable('@'.$timestamp))->setTimezone(self::utc());
    }

    /**
     * A time read from the database or a site ("2026-09-28 10:00:00+00", "2026-09-20T08:00:00.000Z"), in UTC.
     */
    public static function parse(?string $value): ?DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value, self::utc()))->setTimezone(self::utc());
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * As the API sends times: ISO 8601 in UTC without fractions ("2026-09-28T10:00:00+00:00").
     */
    public static function iso(?DateTimeInterface $time): ?string
    {
        return $time === null ? null : DateTimeImmutable::createFromInterface($time)->setTimezone(self::utc())->format(DATE_ATOM);
    }

    /**
     * Milliseconds since the epoch.
     */
    public static function ms(DateTimeInterface $time): int
    {
        return (int) floor((float) $time->format('U.u') * 1000);
    }

    /** "2 hours ago": for the reason a source was skipped. */
    public static function ago(DateTimeInterface $time): string
    {
        $seconds = max(0, self::timestamp() - $time->getTimestamp());
        foreach ([[31536000, 'year'], [2592000, 'month'], [604800, 'week'], [86400, 'day'], [3600, 'hour'], [60, 'minute'], [1, 'second']] as [$size, $unit]) {
            if ($seconds >= $size) {
                $count = intdiv($seconds, $size);

                return $count.' '.$unit.($count === 1 ? '' : 's').' ago';
            }
        }

        return '1 second ago';
    }

    public static function utc(): DateTimeZone
    {
        static $utc = null;

        return $utc ??= new DateTimeZone('UTC');
    }

    /** Tests only: let this many seconds pass. */
    public static function travel(float $seconds): void
    {
        self::$offset += $seconds;
    }

    /** Tests only: back to the real time. */
    public static function reset(): void
    {
        self::$offset = 0.0;
    }
}
