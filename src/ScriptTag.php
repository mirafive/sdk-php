<?php

declare(strict_types=1);

namespace MiraFive;

use InvalidArgumentException;

/**
 * The browser script tag, exactly as the MIRA FIVE dashboard shows it: a stub that queues calls made
 * before the SDK has loaded, then the SDK with the project's public key. The Blade directive and the
 * Twig function print this.
 */
final class ScriptTag
{
    /** Where pages load the web SDK from when events go to the default host. */
    public const string DEFAULT_SRC = 'https://cdn.mirafive.io/sdk/mira.js';

    public const array CONSENT = ['auto', 'granted', 'denied', 'analytics'];

    /** The parts of autocapture a page can turn off, as `data-*` attributes. */
    public const array AUTOCAPTURE = ['spa', 'engagement', 'outbound', 'downloads', 'forms'];

    private const string STUB = <<<'JS'
          window.mira = window.mira || function () {
            (mira.q = mira.q || []).push(arguments);
          };
        JS;

    /**
     * @param  string  $publicKey  the project's public key (`mira_pk_…`); an empty key prints nothing
     * @param  string|null  $host  the ingest origin; default https://events.mirafive.io
     * @param  string|null  $src  where the SDK loads from; default the CDN for the default host, else `{host}/sdk/mira.js`
     * @param  string  $consent  auto (default) follows the consent banner (CMP); analytics: analytics yes, marketing no
     * @param  bool|array<string, bool>  $autocapture  false turns off all automatic events; or turn off parts: ['forms' => false]
     * @param  bool  $debug  logs what the SDK does to the browser console
     * @param  string|null  $nonce  a CSP nonce for both tags
     *
     * @throws MiraError for a secret key: it must never reach a page
     * @throws InvalidArgumentException for an unknown option value
     */
    public static function render(
        string $publicKey,
        ?string $host = null,
        ?string $src = null,
        string $consent = 'auto',
        bool|array $autocapture = true,
        bool $debug = false,
        ?string $nonce = null,
    ): string {
        $key = trim($publicKey);

        if ($key === '') {
            return '';
        }

        if (str_starts_with($key, Mira::SECRET_KEY_PREFIX)) {
            throw new MiraError('refused to print a secret key (mira_sk_…) into a page: set MIRAFIVE_PUBLIC_KEY to the public key (mira_pk_…)');
        }

        if (! in_array($consent, self::CONSENT, true)) {
            throw new InvalidArgumentException('MIRA FIVE script consent must be one of '.implode(', ', self::CONSENT).", got \"{$consent}\"");
        }

        $host = rtrim(trim((string) $host), '/');
        $host = $host === '' ? Mira::DEFAULT_HOST : $host;
        $src = trim((string) $src);
        $src = $src !== '' ? $src : ($host === Mira::DEFAULT_HOST ? self::DEFAULT_SRC : $host.'/sdk/mira.js');
        $nonce = $nonce === null || $nonce === '' ? '' : ' nonce="'.self::escape($nonce).'"';

        $attributes = [];

        if (self::origin($src) !== self::origin($host)) {
            $attributes['data-host'] = $host;
        }

        $attributes['data-key'] = $key;

        if ($consent !== 'auto') {
            $attributes['data-consent'] = $consent;
        }

        foreach (self::autocapture($autocapture) as $name) {
            $attributes["data-{$name}"] = 'false';
        }

        if ($debug) {
            $attributes['data-debug'] = 'true';
        }

        $lines = '';

        foreach ($attributes as $name => $value) {
            $lines .= "\n  {$name}=\"".self::escape($value).'"';
        }

        return "<script{$nonce}>\n".self::STUB."\n</script>\n<script async{$nonce} src=\"".self::escape($src).'"'.$lines.'></script>';
    }

    /** Whether a key is a secret key, which must never be printed into a page. */
    public static function isSecretKey(string $key): bool
    {
        return str_starts_with(trim($key), Mira::SECRET_KEY_PREFIX);
    }

    /**
     * The autocapture attributes to print, each one turned off.
     *
     * @param  bool|array<array-key, mixed>  $autocapture
     * @return list<string>
     */
    private static function autocapture(bool|array $autocapture): array
    {
        if ($autocapture === true) {
            return [];
        }

        if ($autocapture === false) {
            return ['autocapture'];
        }

        foreach ($autocapture as $part => $on) {
            if (! in_array($part, self::AUTOCAPTURE, true) || ! is_bool($on)) {
                throw new InvalidArgumentException('MIRA FIVE script autocapture takes true, false, or booleans for '.implode(', ', self::AUTOCAPTURE));
            }
        }

        // In a fixed order, so the tag does not change with the order of the options.
        return array_values(array_filter(self::AUTOCAPTURE, static fn (string $part): bool => ($autocapture[$part] ?? true) === false));
    }

    private static function origin(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        return strtolower($parts['scheme'].'://'.$parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
