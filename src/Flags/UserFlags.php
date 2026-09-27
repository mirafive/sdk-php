<?php

declare(strict_types=1);

namespace MiraFive\Flags;

use Closure;

/**
 * The flags for one unit. Reads are synchronous and never throw. `enabled`, `variant` and `config` count the person in
 * an experiment counted on your server; `evaluate` never counts anyone.
 */
final readonly class UserFlags
{
    private const int READ = 0;

    private const int EXPOSING_READ = 1;

    private const int BOOTSTRAP_READ = 2;

    /**
     * @param  Closure(string, string, ?string, ?string): void  $expose
     */
    public function __construct(
        private ?Document $document,
        private Facts $facts,
        private int $confirmedAt,
        private Closure $expose,
        private bool $experiments = true,
        private bool $targeting = true,
    ) {}

    public function enabled(string $key, bool $fallback = false): bool
    {
        $variant = $this->read($key, self::EXPOSING_READ)[0]->variant;

        return $variant === 'on' || ($variant !== 'off' && $fallback);
    }

    public function variant(string $key, ?string $fallback = null): ?string
    {
        return $this->read($key, self::EXPOSING_READ)[0]->variant ?? $fallback;
    }

    /** The variant's remote-config value, with JSON objects as associative arrays. */
    public function config(string $key, mixed $fallback = null): mixed
    {
        return $this->read($key, self::EXPOSING_READ)[0]->value ?? $fallback;
    }

    public function evaluate(string $key): Evaluation
    {
        return $this->read($key, self::READ)[0];
    }

    /**
     * The flags the website reads, as `<script type="application/json" id="mirafive-flags">` for the browser SDK.
     * Escaped so no value can end the script. Send MiraFlags::BOOTSTRAP_HEADERS with the page.
     */
    public function bootstrap(): string
    {
        $values = [];
        $browser = [];

        foreach ($this->document->flags ?? [] as $key => $flag) {
            // Only flags the website reads: a server-only value must never reach the page.
            if (($flag['w'] ?? null) !== 1) {
                continue;
            }

            // MIRA's anonymous id never reaches a server rendering a page, so a split by browser waits for the browser.
            if (($flag['u'] ?? null) === 'b') {
                $bare = Evaluator::evaluate($flag, $this->facts->withoutId());

                if ($bare->reason === Reason::Default && $bare->rule !== null) {
                    $browser[] = $key;

                    continue;
                }
            }

            [$evaluation, $marked] = $this->read($key, self::BOOTSTRAP_READ);

            if ($evaluation->variant === null || $this->document === null) {
                continue;
            }

            [$hasValue, $value] = $this->document->value($key, $evaluation->variant);
            $values[$key] = match (true) {
                $marked => [$evaluation->variant, $value, 1],
                $hasValue => [$evaluation->variant, $value],
                default => [$evaluation->variant],
            };
        }

        $bootstrap = ['v' => 1, 'at' => $this->confirmedAt, 'values' => (object) $values];

        if ($browser !== []) {
            $bootstrap['browser'] = $browser;
        }

        // The browser SDK compares this with its own identify() before counting a marked value.
        if ($this->facts->userId !== null) {
            $bootstrap['unit'] = (string) Hash::fnv1a32($this->facts->userId);
        }

        // FLAGS.md §5.3: as JSON.stringify, then only <, > and & as lower-case \u escapes (U+2028/9 are by default).
        $json = strtr(
            json_encode($bootstrap, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ['<' => '\u003c', '>' => '\u003e', '&' => '\u0026'],
        );

        return '<script type="application/json" id="mirafive-flags">'.$json.'</script>';
    }

    /**
     * @return array{0: Evaluation, 1: bool} the answer, and whether the browser counts it (bootstrap only)
     */
    private function read(string $key, int $mode): array
    {
        $flag = $this->document?->flags[$key] ?? null;

        if ($flag === null) {
            return [Evaluation::failed($this->document === null ? ErrorCode::NotReady : ErrorCode::FlagNotFound), false];
        }

        $decision = Evaluator::evaluate($flag, $this->facts);

        if ($decision->variant === null) {
            return [$decision, false];
        }

        $errorCode = match (true) {
            $this->segmentsAnswered($flag) => null,
            $this->targeting => ErrorCode::MembershipUnavailable,
            default => ErrorCode::NotAllowed,
        };
        $experiment = isset($flag['e']);
        $countedHere = ($flag['c'] ?? null) === 's';

        // Without experiments consent every experiment shows its default. One counted in the browser is decided
        // there, except in a bootstrap, which hands it over.
        if ($experiment && $decision->reason !== Reason::Disabled && (! $this->experiments || (! $countedHere && $mode !== self::BOOTSTRAP_READ))) {
            $decision = Evaluation::decided(is_string($flag['d'] ?? null) ? $flag['d'] : $decision->variant, Reason::Default);
            $errorCode = ErrorCode::NotAllowed;
        }

        $variant = (string) $decision->variant;
        $counted = $experiment && $decision->reason === Reason::Split;

        if ($counted && $countedHere && $mode !== self::READ) {
            ($this->expose)($key, $variant, $this->facts->userId, $this->facts->id);
        }

        $values = is_array($flag['p'] ?? null) ? $flag['p'] : [];

        return [
            new Evaluation($variant, $decision->reason, $decision->rule, $errorCode, $values[$variant] ?? null),
            $counted && ! $countedHere && $mode === self::BOOTSTRAP_READ,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $flag
     */
    private function segmentsAnswered(array $flag): bool
    {
        $refs = Document::refs($flag);
        $segments = $this->facts->segments;

        return $refs === [] || ($segments instanceof Membership && array_intersect($refs, $segments->unavailable) === []);
    }
}
