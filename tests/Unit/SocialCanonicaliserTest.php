<?php

declare(strict_types=1);

use HansDeBoeck\BrandFetcher\Social\UrlCanonicaliser;

beforeEach(function (): void {
    $this->canonicaliser = new UrlCanonicaliser();
});

it('herkent een profiel en schrijft de url uniform', function (string $input, string $platform, string $expected): void {
    $result = $this->canonicaliser->canonicalise($input);

    expect($result)->not->toBeNull()
        ->and($result['platform']->value)->toBe($platform)
        ->and($result['url'])->toBe($expected);
})->with([
    ['https://www.facebook.com/AcmeBelgie', 'facebook', 'https://facebook.com/AcmeBelgie'],
    ['https://m.facebook.com/AcmeBelgie/', 'facebook', 'https://facebook.com/AcmeBelgie'],
    ['https://nl-nl.facebook.com/AcmeBelgie', 'facebook', 'https://facebook.com/AcmeBelgie'],
    ['https://be.linkedin.com/company/acme-nv', 'linkedin', 'https://linkedin.com/company/acme-nv'],
    ['https://www.linkedin.com/in/hansdeboeck', 'linkedin', 'https://linkedin.com/in/hansdeboeck'],
    ['https://twitter.com/AcmeBE', 'x', 'https://x.com/AcmeBE'],
    ['https://www.youtube.com/@acme', 'youtube', 'https://youtube.com/@acme'],
    ['https://www.youtube.com/channel/UC123', 'youtube', 'https://youtube.com/channel/UC123'],
    ['https://www.instagram.com/acme.belgie/', 'instagram', 'https://instagram.com/acme.belgie'],
    ['https://bsky.app/profile/acme.be', 'bluesky', 'https://bsky.app/profile/acme.be'],
    ['https://open.spotify.com/artist/abc123', 'spotify', 'https://open.spotify.com/artist/abc123'],
    ['https://wa.me/32475123456', 'whatsapp', 'https://wa.me/32475123456'],
]);

it('sluit deelknoppen uit', function (string $url): void {
    expect($this->canonicaliser->canonicalise($url))->toBeNull();
})->with([
    'https://www.facebook.com/sharer/sharer.php?u=https://acme.be',
    'https://www.facebook.com/share.php?u=https://acme.be',
    'https://twitter.com/intent/tweet?text=hoi&url=https://acme.be',
    'https://www.linkedin.com/shareArticle?mini=true&url=https://acme.be',
    'https://wa.me/?text=kijk',
    'https://pinterest.com/pin/create/button/?url=https://acme.be',
    'https://www.youtube.com/watch?v=abc',
    'https://www.instagram.com/p/Cabc123/',
    'https://www.tiktok.com/share',
]);

it('weigert een kaal platformadres zonder profiel', function (string $url): void {
    expect($this->canonicaliser->canonicalise($url))->toBeNull();
})->with([
    'https://www.facebook.com/',
    'https://twitter.com',
    'https://www.linkedin.com/',
]);

it('weigert verkorters en omleiders', function (string $url): void {
    // Waar die heen gaan weten we pas na een verzoek, en dat is een uitgaande
    // verbinding waard noch waardig.
    expect($this->canonicaliser->canonicalise($url))->toBeNull();
})->with([
    'https://t.co/abc123',
    'https://youtu.be/abc123',
    'https://lnkd.in/abc',
    'https://l.facebook.com/l.php?u=https://acme.be',
]);

it('haalt trackingparameters weg zonder de link te weigeren', function (): void {
    $result = $this->canonicaliser->canonicalise('https://x.com/AcmeBE?ref_src=twsrc&utm_source=site');

    expect($result['url'])->toBe('https://x.com/AcmeBE');
});

it('leest het profielnummer van facebook uit de query', function (): void {
    $result = $this->canonicaliser->canonicalise('https://www.facebook.com/profile.php?id=123456789');

    expect($result['url'])->toBe('https://facebook.com/profile.php?id=123456789')
        ->and($result['handle'])->toBe('123456789');

    expect($this->canonicaliser->canonicalise('https://www.facebook.com/profile.php'))->toBeNull();
});

it('houdt de hoofdletters in het pad', function (): void {
    // Op sommige platformen is een handle hoofdlettergevoelig, en wat de site
    // schreef is wat een bezoeker hoort te zien.
    expect($this->canonicaliser->canonicalise('https://x.com/AcmeBE')['url'])->toBe('https://x.com/AcmeBE');
});

it('weigert een gewone pagina op een gewone site', function (): void {
    expect($this->canonicaliser->canonicalise('https://acme.be/over-ons'))->toBeNull();
});
