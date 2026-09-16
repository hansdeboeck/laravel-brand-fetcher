<?php

declare(strict_types=1);

use HansDeBoeck\BrandFetcher\SiteDetail;
use HansDeBoeck\BrandFetcher\Social\SocialProfile;

it('gaat verliesvrij heen en weer', function (): void {
    $detail = new SiteDetail(
        domain: 'acme.be',
        domainUnicode: null,
        status: SiteDetail::OK,
        fetchedAt: 1789000000,
        ttl: 2592000,
        finalUrl: 'https://www.acme.be/',
        attempts: 1,
        logoBytes: 1844,
        logoSha1: 'abc123',
        logoSize: 128,
        source: 'apple-touch-icon',
        sourceUrl: 'https://www.acme.be/touch.png',
        sourceWidth: 180,
        sourceHeight: 180,
        sourceRatio: 1.0,
        hasAlpha: true,
        trimmed: true,
        profiles: [new SocialProfile('facebook', 'https://facebook.com/Acme', 'Acme', 'jsonld')],
        name: 'Acme NV',
    );

    $terug = SiteDetail::fromDetailArray($detail->toDetailArray(), 1789000000);

    expect($terug->toDetailArray())->toBe($detail->toDetailArray());
});

it('geeft null bij een onbekende versie', function (): void {
    expect(SiteDetail::fromDetailArray(['v' => 99, 'domain' => 'acme.be']))->toBeNull()
        ->and(SiteDetail::fromDetailArray([]))->toBeNull();
});

it('bevat geen objecten in de opgeslagen vorm', function (): void {
    // Wat op een schijf terechtkomt moet over jaren nog leesbaar zijn, en een
    // geserialiseerd object is dat niet.
    $detail = new SiteDetail(
        domain: 'acme.be',
        profiles: [new SocialProfile('x', 'https://x.com/Acme', 'Acme', 'meta')],
    );

    $encoded = json_encode($detail->toDetailArray());
    $decoded = json_decode((string) $encoded, true);

    array_walk_recursive($decoded, function ($value): void {
        expect(is_object($value))->toBeFalse();
    });

    expect($encoded)->not->toBeFalse();
});

it('rekent de verlooptijd uit de klok', function (): void {
    $detail = new SiteDetail(domain: 'acme.be', fetchedAt: 1000, ttl: 100);

    expect($detail->expiresAt())->toBe(1100)
        ->and($detail->isStale(1099))->toBeFalse()
        ->and($detail->isStale(1100))->toBeTrue();
});

it('herkent een domein dat nog in de wacht staat', function (): void {
    $wacht = new SiteDetail(domain: 'acme.be', status: SiteDetail::PENDING, fetchedAt: time(), ttl: 0);

    expect($wacht->isPending())->toBeTrue()
        ->and($wacht->isStale())->toBeTrue()
        ->and($wacht->hasLogo())->toBeFalse();
});
