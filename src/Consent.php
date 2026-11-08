<?php

declare(strict_types=1);

namespace MiraFive;

/**
 * The consent an event was collected under. Server-only facts (a paid invoice) are
 * `notApplicable()`, the default. For what a visitor did, pass what your banner recorded.
 *
 * Every event method also takes `'not_applicable'` or an array such as
 * `['analytics' => true, 'marketing' => false]`; booleans and `'granted'`, `'denied'`, `'unknown'`
 * are both fine, and a missing `marketing` is `unknown`.
 */
final readonly class Consent
{
    public const array STATES = ['granted', 'denied', 'unknown'];

    /**
     * @param  'granted'|'denied'|'unknown'|null  $analytics  null: not applicable
     * @param  'granted'|'denied'|'unknown'|null  $marketing
     */
    private function __construct(
        public ?string $analytics,
        public ?string $marketing,
    ) {}

    public static function notApplicable(): self
    {
        return new self(null, null);
    }

    /** Analytics and marketing granted: tier 3. */
    public static function granted(): self
    {
        return new self('granted', 'granted');
    }

    /** Analytics granted, marketing denied. */
    public static function analyticsOnly(): self
    {
        return new self('granted', 'denied');
    }

    /** Nothing granted: the event counts, but is not linked to a person (tier 0). */
    public static function denied(): self
    {
        return new self('denied', 'denied');
    }

    /**
     * @throws MiraError for a state that is not a boolean, `granted`, `denied` or `unknown`
     */
    public static function of(bool|string $analytics, bool|string|null $marketing = null): self
    {
        return new self(self::state($analytics), self::state($marketing ?? 'unknown'));
    }

    /**
     * What an event method was given, as a Consent.
     *
     * @throws MiraError
     */
    public static function from(mixed $input): self
    {
        if ($input === null || $input === 'not_applicable') {
            return self::notApplicable();
        }

        if ($input instanceof self) {
            return $input;
        }

        if (is_array($input) && array_key_exists('analytics', $input)) {
            $analytics = $input['analytics'];
            $marketing = $input['marketing'] ?? null;

            if ((is_bool($analytics) || is_string($analytics)) && ($marketing === null || is_bool($marketing) || is_string($marketing))) {
                return self::of($analytics, $marketing);
            }
        }

        throw new MiraError("consent must be 'not_applicable', a Consent, or ['analytics' => …, 'marketing' => …]");
    }

    /**
     * The wire value: `"not_applicable"` or `{analytics, marketing}`.
     *
     * @return 'not_applicable'|array{analytics: string, marketing: string}
     */
    public function toWire(): string|array
    {
        if ($this->analytics === null) {
            return 'not_applicable';
        }

        return ['analytics' => $this->analytics, 'marketing' => $this->marketing ?? 'unknown'];
    }

    /**
     * @return 'granted'|'denied'|'unknown'
     */
    private static function state(bool|string $value): string
    {
        return match ($value) {
            true, 'granted' => 'granted',
            false, 'denied' => 'denied',
            'unknown' => 'unknown',
            default => throw new MiraError("consent: \"{$value}\" is not granted, denied or unknown"),
        };
    }
}
