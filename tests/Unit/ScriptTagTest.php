<?php

declare(strict_types=1);

use MiraFive\MiraError;
use MiraFive\ScriptTag;

it('prints the dashboard snippet for the default host', function (): void {
    expect(ScriptTag::render('mira_pk_live_123'))->toBe(<<<'HTML'
        <script>
          window.mira = window.mira || function () {
            (mira.q = mira.q || []).push(arguments);
          };
        </script>
        <script async src="https://cdn.mirafive.io/sdk/mira.js"
          data-host="https://events.mirafive.io"
          data-key="mira_pk_live_123"></script>
        HTML);
});

it('loads the SDK from a self-hosted ingest without data-host', function (): void {
    expect(ScriptTag::render('mira_pk_1', host: 'http://localhost:4500/'))
        ->toContain('<script async src="http://localhost:4500/sdk/mira.js"'."\n".'  data-key="mira_pk_1"></script>');
});

it('adds data-host when the SDK comes from elsewhere', function (): void {
    expect(ScriptTag::render('mira_pk_1', host: 'https://t.example.com', src: 'https://cdn.example.com/mira.js'))
        ->toContain('src="https://cdn.example.com/mira.js"'."\n".'  data-host="https://t.example.com"'."\n".'  data-key="mira_pk_1">');
});

it('prints consent, autocapture, debug and a nonce', function (): void {
    $tag = ScriptTag::render('mira_pk_1', consent: 'analytics', autocapture: ['forms' => false, 'spa' => true, 'outbound' => false], debug: true, nonce: 'n"1');

    expect($tag)->toStartWith('<script nonce="n&quot;1">')
        ->and($tag)->toContain('<script async nonce="n&quot;1" src=')
        ->and($tag)->toEndWith("  data-key=\"mira_pk_1\"\n  data-consent=\"analytics\"\n  data-outbound=\"false\"\n  data-forms=\"false\"\n  data-debug=\"true\"></script>");
});

it('turns off all autocapture', function (): void {
    expect(ScriptTag::render('mira_pk_1', autocapture: false))->toContain('data-autocapture="false"');
});

it('prints nothing without a key', function (): void {
    expect(ScriptTag::render('  '))->toBe('');
});

it('refuses to print a secret key', function (): void {
    ScriptTag::render('mira_sk_live_123');
})->throws(MiraError::class, 'refused to print a secret key');

it('refuses unknown options', function (): void {
    expect(fn () => ScriptTag::render('mira_pk_1', consent: 'yes'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ScriptTag::render('mira_pk_1', autocapture: ['clicks' => false]))->toThrow(InvalidArgumentException::class);
});

it('escapes attribute values', function (): void {
    expect(ScriptTag::render('mira_pk_"><script>'))->toContain('data-key="mira_pk_&quot;&gt;&lt;script&gt;"');
});
