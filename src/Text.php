<?php

declare(strict_types=1);

namespace MiraFive;

/** @internal Lengths in UTF-16 code units, as the protocol counts them, without needing ext-mbstring. */
final class Text
{
    public static function length(string $text): int
    {
        // Code points are the bytes that do not continue a sequence; four-byte sequences are surrogate pairs.
        return strlen($text) - (int) preg_match_all('/[\x80-\xBF]/', $text) + (int) preg_match_all('/[\xF0-\xF7]/', $text);
    }

    public static function clamp(string $text, int $max): string
    {
        if (self::length($text) <= $max) {
            return $text;
        }

        if (preg_match_all('/./su', $text, $matches) === false) {
            return substr($text, 0, $max);
        }

        $kept = '';
        $units = 0;

        foreach ($matches[0] as $character) {
            $units += strlen($character) === 4 ? 2 : 1;

            if ($units > $max) {
                break;
            }

            $kept .= $character;
        }

        return $kept;
    }
}
