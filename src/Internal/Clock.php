<?php

declare(strict_types=1);

namespace MiraFive\Internal;

/** @internal */
final class Clock
{
    public static function ms(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
