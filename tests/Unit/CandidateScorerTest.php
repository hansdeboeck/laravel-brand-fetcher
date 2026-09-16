<?php

declare(strict_types=1);

use HansDeBoeck\BrandFetcher\Discovery\CandidateScorer;
use HansDeBoeck\BrandFetcher\Discovery\IconCandidate;

function candidate(string $source, ?int $declared, ?string $mime, int $width, int $height, bool $alpha = true): IconCandidate
{
    return (new IconCandidate(
        url: 'https://acme.be/beeld',
        source: $source,
        declaredSize: $declared,
        mime: $mime,
    ))->measured($width, $height, $alpha);
}

beforeEach(function (): void {
    $this->scorer = new CandidateScorer();
});

it('geeft een vierkante bron voorrang op een banner', function (): void {
    $vierkant = candidate('og:image', 512, 'image/png', 512, 512);
    $banner = candidate('og:image', 1200, 'image/jpeg', 1200, 630, false);

    expect($this->scorer->hard($vierkant))->toBeGreaterThan($this->scorer->hard($banner));
});

it('rangschikt manifest boven apple-touch-icon boven favicon', function (): void {
    $manifest = $this->scorer->hard(candidate('manifest', 512, 'image/png', 512, 512));
    $apple = $this->scorer->hard(candidate('apple-touch-icon', 180, 'image/png', 180, 180));
    $favicon = $this->scorer->hard(candidate('favicon.ico', null, null, 32, 32));

    expect($manifest)->toBeGreaterThan($apple)
        ->and($apple)->toBeGreaterThan($favicon);
});

it('gebruikt de gemeten afmetingen en niet het sizes-attribuut', function (): void {
    // Een bestand van zestien pixels dat sizes="256x256" claimt, komt vaker voor
    // dan je zou willen. Op papier wint hij; gemeten verliest hij.
    $liegt = candidate('link-icon', 256, 'image/png', 16, 16);
    $eerlijk = candidate('link-icon', 180, 'image/png', 180, 180);

    expect($this->scorer->paper($liegt))->toBeGreaterThan($this->scorer->paper($eerlijk))
        ->and($this->scorer->hard($liegt))->toBeLessThan($this->scorer->hard($eerlijk));
});

it('straft svg zo zwaar dat die nooit kan winnen', function (): void {
    $svg = (new IconCandidate('https://acme.be/logo.svg', 'link-icon', null, 'image/svg+xml'))->measured(512, 512, true);

    expect($this->scorer->hard($svg))->toBeLessThan(0);
});

it('beloont transparantie', function (): void {
    $met = candidate('link-icon', 256, 'image/png', 256, 256, alpha: true);
    $zonder = candidate('link-icon', 256, 'image/png', 256, 256, alpha: false);

    expect($this->scorer->hard($met))->toBeGreaterThan($this->scorer->hard($zonder));
});

it('haalt een vierkante bron van 256 boven de drempel voor goed genoeg', function (): void {
    // Dit is wat de zoektocht laat stoppen; zakt deze waarde, dan downloadt de
    // ophaler onnodig verder.
    expect($this->scorer->hard(candidate('apple-touch-icon', 256, 'image/png', 256, 256)))
        ->toBeGreaterThanOrEqual(config('brand-fetcher.good_enough_score'));
});
