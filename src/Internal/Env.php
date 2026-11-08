<?php

declare(strict_types=1);

namespace MiraFive\Internal;

/** @internal `MIRAFIVE_*` variables, as every MIRA FIVE PHP package reads them. */
final class Env
{
    public static function read(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
