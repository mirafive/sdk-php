<?php

declare(strict_types=1);

namespace MiraFive\Flags;

/** FNV-1a (32 bit), twice, into 10,000 buckets; the same number in every MIRA FIVE SDK and on the server. */
final class Hash
{
    /** Two salts keep share and variant independent: a larger share moves no one between variants. */
    public const string SHARE = '.r';

    public const string VARIANT = '.v';

    public const int BUCKETS = 10_000;

    public static function fnv1a32(string $bytes): int
    {
        return intval(hash('fnv1a32', $bytes), 16);
    }

    public static function bucket(string $seed, string $salt, string $unit): int
    {
        return self::fnv1a32((string) self::fnv1a32($seed.$salt.$unit)) % self::BUCKETS;
    }
}
