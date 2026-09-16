<?php

declare(strict_types=1);

use HansDeBoeck\BrandFetcher\Discovery\CandidateScorer;
use HansDeBoeck\BrandFetcher\Discovery\IconCandidate;

function candidate(string $source, ?int $declared, ?string $mime, int $width, int $height, bool $alpha = true, ?float $vorm = null): IconCandidate
{
    return (new IconCandidate(
        url: 'https://acme.be/beeld',
        source: $source,
        declaredSize: $declared,
        declaredRatio: $vorm,
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
    $svg = (new IconCandidate(
        url: 'https://acme.be/logo.svg',
        source: 'link-icon',
        mime: 'image/svg+xml',
    ))->measured(512, 512, true);

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

it('zet een sociale avatar boven een kleine favicon en onder een echt app-icoon', function (): void {
    $facebook = $this->scorer->hard(candidate('facebook', 480, 'image/png', 480, 480, false));
    $linkedin = $this->scorer->hard(candidate('linkedin', 200, 'image/jpeg', 200, 200, false));
    $favicon = $this->scorer->hard(candidate('favicon.ico', null, null, 32, 32));
    $apple = $this->scorer->hard(candidate('apple-touch-icon', 180, 'image/png', 180, 180));

    expect($facebook)->toBeGreaterThan($favicon)
        ->and($linkedin)->toBeGreaterThan($favicon)
        ->and($apple)->toBeGreaterThan($facebook)
        ->and($apple)->toBeGreaterThan($linkedin);
});

/*
| De wacht op de gewichten van de sociale bronnen.
|
| Komt zo'n avatar op of boven good_enough_score, dan stopt de downloadlus bij
| hem en krijgen de eigen iconen van de site geen kans meer om gemeten te
| worden. Ze mogen dus meedingen, maar nooit afbreken. Hier staat de zwaarste
| variant die elk van de twee kan opleveren: de grootste uitsnede, en met alfa
| omdat het trimmen van een niet-vierkant logo transparante randen achterlaat.
*/
it('laat een sociale avatar de zoektocht nooit afbreken', function (string $bron, int $maat, string $mime): void {
    expect($this->scorer->hard(candidate($bron, $maat, $mime, $maat, $maat, true)))
        ->toBeLessThan((int) config('brand-fetcher.good_enough_score'));
})->with([
    'facebook' => ['facebook', 480, 'image/png'],
    'linkedin' => ['linkedin', 400, 'image/jpeg'],
]);

/*
| Een bewering over de maat is goedkoop, maar een bewering over de vorm is er
| een in het eigen nadeel. Zegt een manifest zelf dat zijn icoon liggend is,
| dan verliest het zijn voorsprong in de volgorde en worden de avatars van de
| bedrijfspagina eerst gedownload: die zijn per definitie vierkant.
*/
it('zet een bron die zelf zegt niet vierkant te zijn onder de socials', function (): void {
    $liggend = candidate('manifest', 512, 'image/png', 512, 256, vorm: 2.0);
    $facebook = candidate('facebook', 480, 'image/png', 480, 480, false);
    $linkedin = candidate('linkedin', 200, 'image/jpeg', 200, 200, false);

    expect($this->scorer->paper($liggend))->toBeLessThan($this->scorer->paper($facebook))
        ->and($this->scorer->paper($liggend))->toBeLessThan($this->scorer->paper($linkedin));
});

it('laat een vierkante bewering zijn voorsprong houden', function (): void {
    $vierkant = candidate('manifest', 512, 'image/png', 512, 512, vorm: 1.0);
    $facebook = candidate('facebook', 480, 'image/png', 480, 480, false);

    expect($this->scorer->paper($vierkant))->toBeGreaterThan($this->scorer->paper($facebook));
});

it('rekent een pixel scheef nog als vierkant', function (): void {
    // "512x511" bestaat, en dat is geen liggend beeld maar een afronding.
    $bijna = candidate('manifest', 512, 'image/png', 512, 511, vorm: 512 / 511);
    $precies = candidate('manifest', 512, 'image/png', 512, 512, vorm: 1.0);

    expect($this->scorer->paper($bijna))->toBe($this->scorer->paper($precies));
});
