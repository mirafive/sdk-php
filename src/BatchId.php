<?php

declare(strict_types=1);

namespace MiraFive;

/** Batch ids per PROTOCOL §5: random for buffered batches, derived for a caller's idempotency key. */
final class BatchId
{
    /** UUIDv8 of SHA-256("mirafive:batch:" ‖ key), so every SDK maps a key to the same batch. */
    public static function fromIdempotencyKey(string $key): string
    {
        $bytes = substr(hash('sha256', 'mirafive:batch:'.$key, true), 0, 16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0F | 0x80);
        $bytes[8] = chr(ord($bytes[8]) & 0x3F | 0x80);

        return self::format($bytes);
    }

    public static function random(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0F | 0x40);
        $bytes[8] = chr(ord($bytes[8]) & 0x3F | 0x80);

        return self::format($bytes);
    }

    private static function format(string $bytes): string
    {
        $hex = bin2hex($bytes);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }
}
