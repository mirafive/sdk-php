<?php

declare(strict_types=1);

namespace MiraFive;

/** The collector's 202. `dropped > 0` with a reason means nothing was kept; it is still final. */
final readonly class Receipt
{
    public function __construct(
        public string $batch,
        public int $accepted,
        public int $dropped,
        public ?string $reason = null,
    ) {}
}
