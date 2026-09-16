<?php

declare(strict_types=1);

use HansDeBoeck\BrandFetcher\Net\UrlGuard;

/** Een resolver die precies teruggeeft wat de test nodig heeft. */
function guard(array $map = []): UrlGuard
{
    return new UrlGuard([], fn (string $host): array => $map[$host] ?? ['93.184.216.34']);
}

it('laat een publiek adres door en geeft de pins terug', function (): void {
    $verdict = guard(['acme.be' => ['93.184.216.34']])->check('https://acme.be/');

    expect($verdict->allowed)->toBeTrue()
        ->and($verdict->host)->toBe('acme.be')
        ->and($verdict->port)->toBe(443)
        ->and($verdict->addresses)->toBe(['93.184.216.34']);
});

it('weigert een host die naar een prive-adres wijst', function (string $ip): void {
    $verdict = guard(['intern.acme.be' => [$ip]])->check('https://intern.acme.be/');

    expect($verdict->allowed)->toBeFalse()
        ->and($verdict->reason)->toBe('blocked_host');
})->with([
    '10.0.0.1',
    '172.16.0.1',
    '192.168.1.1',
    '127.0.0.1',
    '0.0.0.0',
    // Het adres waar een cloudserver zijn eigen sleutels vandaan haalt.
    '169.254.169.254',
    // Carrier-grade nat: dit bereik laat filter_var gewoon door.
    '100.64.0.1',
    '192.0.2.5',
    '198.18.0.1',
    '203.0.113.9',
    '224.0.0.1',
    '::1',
    'fe80::1',
    'fc00::1',
    // Een ipv4-adres vermomd als ipv6.
    '::ffff:127.0.0.1',
    '::ffff:10.0.0.1',
]);

it('weigert zodra een van de adressen prive is', function (): void {
    // Welk adres curl uiteindelijk pakt ligt niet bij ons, dus een halve
    // treffer is hier een hele weigering.
    $verdict = guard(['half.acme.be' => ['93.184.216.34', '192.168.1.1']])->check('https://half.acme.be/');

    expect($verdict->allowed)->toBeFalse();
});

it('weigert een afwijkende poort', function (): void {
    expect(guard()->check('https://acme.be:8443/')->reason)->toBe('bad_port');
});

it('weigert een ander schema dan https', function (string $url): void {
    expect(guard()->check($url)->reason)->toBe('bad_scheme');
})->with([
    'http://acme.be/',
    'ftp://acme.be/',
    'file:///etc/passwd',
    'javascript:alert(1)',
]);

it('weigert gebruikersinfo in de url', function (): void {
    expect(guard()->check('https://user:pw@acme.be/')->reason)->toBe('userinfo_not_allowed');
});

it('weigert een host zonder adressen', function (): void {
    expect(guard(['leeg.acme.be' => []])->check('https://leeg.acme.be/')->reason)->toBe('dns_failed');
});
