<?php

declare(strict_types=1);

namespace MiraFive\Internal;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use MiraFive\MiraError;

/** @internal Times on the wire: ISO 8601 in UTC with milliseconds, like JavaScript's `toISOString()`. */
final class Time
{
    public static function iso(DateTimeInterface $time): string
    {
        return DateTimeImmutable::createFromInterface($time)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * A time from the app: a DateTimeInterface, epoch milliseconds (as `Date.now()` gives them), or a
     * string PHP can parse (ISO 8601 recommended).
     *
     * @throws MiraError for a string that is not a time
     */
    public static function from(DateTimeInterface|int|string $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return self::iso($value);
        }

        if (is_int($value)) {
            $seconds = intdiv($value, 1000);
            $milliseconds = $value % 1000;

            if ($milliseconds < 0) {
                $seconds--;
                $milliseconds += 1000;
            }

            $time = DateTimeImmutable::createFromFormat('U', (string) $seconds, new DateTimeZone('UTC'));

            if ($time === false) {
                throw new MiraError("Invalid timestamp: {$value}");
            }

            return $time->format('Y-m-d\TH:i:s').sprintf('.%03dZ', $milliseconds);
        }

        if (trim($value) === '') {
            throw new MiraError('Invalid timestamp: an empty string');
        }

        try {
            return self::iso(new DateTimeImmutable($value));
        } catch (Exception) {
            throw new MiraError("Invalid timestamp: {$value}");
        }
    }
}
