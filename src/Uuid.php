<?php

declare(strict_types=1);

namespace MiraFive;

/** Event ids: UUIDv7 for new events, UUIDv8 for stable ids from your own keys (RFC 9562). */
final class Uuid
{
    private const string PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    private static int $lastMs = 0;

    private static int $counter = 0;

    /**
     * A UUIDv7: 48 bits of Unix milliseconds, then randomness, so ids sort by creation time.
     * Monotonic within one process: in the same millisecond the 12-bit `rand_a` field counts up
     * (RFC 9562, section 6.2, method 1), and an overflow moves the timestamp 1 ms forward.
     */
    public static function v7(?int $nowMs = null): string
    {
        $now = $nowMs ?? (int) floor(microtime(true) * 1000);
        $bytes = random_bytes(16);

        if ($now > self::$lastMs) {
            self::$lastMs = $now;
            // Each millisecond starts at a random point in the lower half, so there is room to count up.
            self::$counter = ((ord($bytes[6]) & 0x07) << 8) | ord($bytes[7]);
        } elseif (++self::$counter > 0xFFF) {
            self::$lastMs++;
            self::$counter = 0;
        }

        $bytes = substr(pack('J', self::$lastMs), 2, 6)
            .chr(0x70 | (self::$counter >> 8))
            .chr(self::$counter & 0xFF)
            .chr((ord($bytes[8]) & 0x3F) | 0x80)
            .substr($bytes, 9);

        return self::format($bytes);
    }

    /**
     * A stable event id from your own key, such as `order:123:paid`: the same key always gives the
     * same UUID, in every MIRA FIVE SDK. A UUIDv8 from the first 122 bits of the key's SHA-256.
     */
    public static function forKey(string $key): string
    {
        $digest = hash('sha256', $key, true);
        $digest[6] = chr((ord($digest[6]) & 0x0F) | 0x80);
        $digest[8] = chr((ord($digest[8]) & 0x3F) | 0x80);

        return self::format(substr($digest, 0, 16));
    }

    public static function valid(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }

    private static function format(string $bytes): string
    {
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20, 12);
    }
}
