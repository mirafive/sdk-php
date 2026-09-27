<?php

declare(strict_types=1);

namespace MiraFive;

/** @internal What one flush may still spend on attempts and waits together, in milliseconds. */
final class Budget
{
    public function __construct(public int $remainingMs) {}

    public function spend(int $ms): void
    {
        $this->remainingMs -= $ms;
    }
}
