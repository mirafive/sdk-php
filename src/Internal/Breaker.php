<?php

declare(strict_types=1);

namespace MiraFive\Internal;

/**
 * @internal After a delivery failed for good, skip the network for a while, so an outage of the
 * ingest cannot add the flush deadline to every request of a site.
 */
final class Breaker
{
    public const int OPEN_MS = 30_000;

    private const string NAME = 'breaker';

    private int $reportedUntil = 0;

    public function __construct(private readonly Store $store) {}

    public function isOpen(): bool
    {
        return Clock::ms() < $this->openUntil();
    }

    public function trip(): void
    {
        $this->store->set(self::NAME, Clock::ms() + self::OPEN_MS, (int) ceil(self::OPEN_MS / 1000));
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
        $until = $this->store->get(self::NAME);

        return is_int($until) ? $until : 0;
    }
}
