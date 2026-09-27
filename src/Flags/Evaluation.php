<?php

declare(strict_types=1);

namespace MiraFive\Flags;

/**
 * A variant and why, or an ERROR with no variant. `rule` is the 0-based index of the deciding rule; `errorCode`
 * beside a variant names what was left out. `value` is the variant's remote-config value.
 */
final readonly class Evaluation
{
    public function __construct(
        public ?string $variant,
        public Reason $reason,
        public ?int $rule = null,
        public ?ErrorCode $errorCode = null,
        public mixed $value = null,
    ) {}

    public static function decided(string $variant, Reason $reason, ?int $rule = null): self
    {
        return new self($variant, $reason, $rule);
    }

    public static function failed(ErrorCode $errorCode): self
    {
        return new self(null, Reason::Error, errorCode: $errorCode);
    }

    /**
     * The shape of the shared fixtures.
     *
     * @return array{variant?: string, reason: string, errorCode?: string, rule?: int}
     */
    public function toArray(): array
    {
        return array_filter([
            'variant' => $this->variant,
            'reason' => $this->reason->value,
            'errorCode' => $this->errorCode?->value,
            'rule' => $this->rule,
        ], fn (string|int|null $value): bool => $value !== null);
    }
}
