<?php

declare(strict_types=1);

namespace MiraFive\Internal;

/**
 * @internal String limits as the ingest counts them: in UTF-16 code units, like JavaScript's
 * `length` and the Node SDK's `slice`. Works without ext-mbstring.
 */
final class Text
{
    /** Valid UTF-8, with every invalid byte replaced by U+FFFD, so JSON encoding cannot fail. */
    public static function valid(string $value): string
    {
        if (preg_match('//u', $value) === 1) {
            return $value;
        }

        $decoded = json_decode((string) json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE));

        return is_string($decoded) ? $decoded : '';
    }

    /** At most `$max` UTF-16 code units, cut at a character boundary. */
    public static function cut(string $value, int $max): string
    {
        $value = self::valid($value);

        // A UTF-8 string never has more UTF-16 units than bytes.
        if (strlen($value) <= $max) {
            return $value;
        }

        $units = 0;
        $bytes = 0;

        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);

        foreach ($characters === false ? [] : $characters as $character) {
            $size = strlen($character);
            $units += $size === 4 ? 2 : 1;

            if ($units > $max) {
                break;
            }

            $bytes += $size;
        }

        return substr($value, 0, $bytes);
    }
}
