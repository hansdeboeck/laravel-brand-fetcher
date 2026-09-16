<?php

declare(strict_types=1);

use HansDeBoeck\BrandFetcher\Net\DomainNormalizer;

beforeEach(function (): void {
    $this->normalizer = new DomainNormalizer();
});

it('haalt schema, www, pad en poort weg', function (string $input, string $expected): void {
    expect($this->normalizer->normalize($input))->toBe($expected);
})->with([
    ['deboeck.dev', 'deboeck.dev'],
    ['WWW.Deboeck.DEV', 'deboeck.dev'],
    ['https://www.acme.be/pad?x=1', 'acme.be'],
    ['acme.be:8080', 'acme.be'],
    ['acme.be.', 'acme.be'],
    ['  acme.be  ', 'acme.be'],
    ['acme.be/../../etc/passwd', 'acme.be'],
]);

it('laat een ander subdomein staan', function (): void {
    // shop.acme.be is een ander merk dan acme.be en mag geen opslag delen.
    expect($this->normalizer->normalize('shop.acme.be'))->toBe('shop.acme.be');
});

it('zet een internationaal domein om naar punycode', function (): void {
    expect($this->normalizer->normalize('munchen.de'))->toBe('munchen.de')
        ->and($this->normalizer->normalize("m\u{fc}nchen.de"))->toBe('xn--mnchen-3ya.de');
});

it('geeft de weergavevorm van een punycode-domein terug', function (): void {
    expect($this->normalizer->unicode('xn--mnchen-3ya.de'))->toBe("m\u{fc}nchen.de")
        ->and($this->normalizer->unicode('acme.be'))->toBeNull();
});

it('weigert alles wat geen publiek domein is', function (string $input): void {
    expect($this->normalizer->normalize($input))->toBeNull();
})->with([
    'localhost',
    '127.0.0.1',
    '0177.0.0.1',
    '2130706433',
    '::1',
    '[::1]',
    'server.local',
    'iets.test',
    'intern.lan',
    'acme',
    'http://user@evil.tld@intern.lan',
    "a\0b.be",
    '-acme.be',
    'acme-.be',
    '',
]);

it('weigert een domein dat te lang is', function (): void {
    expect($this->normalizer->normalize(str_repeat('a', 250) . '.be'))->toBeNull()
        ->and($this->normalizer->normalize(str_repeat('a.', 200) . 'be'))->toBeNull();
});
