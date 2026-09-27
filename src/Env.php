<?php

declare(strict_types=1);

namespace MiraFive;

use InvalidArgumentException;

/** @internal Key and host from arguments or MIRAFIVE_* variables, as every MIRA FIVE SDK reads them. */
final class Env
{
    public static function key(string|false|null $key): string
    {
        return trim(($key ?? self::read('MIRAFIVE_SECRET_KEY')) ?: '');
    }

    public static function host(?string $host): string
    {
        $host = $host ?? self::read('MIRAFIVE_HOST') ?? Mira::DEFAULT_HOST;

        if (preg_match('#^https?://[^/\s]+#i', $host) !== 1) {
            throw new InvalidArgumentException("The host needs a scheme, e.g. https://events.mirafive.io; got \"{$host}\".");
        }

        return rtrim($host, '/');
    }

    private static function read(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
