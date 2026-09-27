<?php

declare(strict_types=1);

namespace MiraFive\Flags;

/** The segments a unit is in, and those whose membership could not be looked up. */
final readonly class Membership
{
    /**
     * @param  list<string>  $in
     * @param  list<string>  $unavailable
     */
    public function __construct(
        public array $in = [],
        public array $unavailable = [],
    ) {}

    /** Null when the segment's membership is unavailable. */
    public function contains(string $ref): ?bool
    {
        return in_array($ref, $this->unavailable, true) ? null : in_array($ref, $this->in, true);
    }
}
