<?php

declare(strict_types=1);

namespace MiraFive;

/** @internal After a failure, skip the network for a while, so an outage cannot stall every request of a site. */
final class Breaker
{
    public const int OPEN_MS = 30_000;

    private int $reportedUntil = 0;

    public function __construct(private readonly Store $store, private readonly string $name) {}

    public function isOpen(): bool
    {
        return Clock::ms() < $this->openUntil();
    }

    public function trip(int $ms = self::OPEN_MS): void
    {
        $this->store->set($this->name, Clock::ms() + $ms, (int) ceil($ms / 1000));
    }

    /** True for the first skip of each open period, so a skip is reported once. */
    public function firstSkip(): bool
    {
        $until = $this->openUntil();

        if ($until <= $this->reportedUntil) {
            return false;
        }

        $this->reportedUntil = $until;

        return true;
    }

    private function openUntil(): int
    {
        $until = $this->store->get($this->name);

        return is_int($until) ? $until : 0;
    }
}
