<?php

declare(strict_types=1);

use HansDeBoeck\BrandFetcher\SiteDetail;
use HansDeBoeck\BrandFetcher\Storage\BrandStore;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    Http::preventStrayRequests();

    $this->store = app(BrandStore::class);

    Http::fake([
        'https://*/apple-touch-icon.png' => Http::response('', 404),
        'https://*/favicon.ico' => Http::response('', 404),
        'https://*/touch.png' => fn () => Http::response(pngFixture(256, 256), 200, ['Content-Type' => 'image/png']),
        'https://*/' => fn () => Http::response(htmlFixture([
            'links' => ['<link rel="apple-touch-icon" sizes="256x256" href="/touch.png">'],
        ])),
    ]);
});

/**
 * Verouder een entry echt.
 *
 * De mtime van detail.json is de klok, niet het veld in het bestand: dat is het
 * enige dat een lokale schijf en s3 allebei gratis kunnen vertellen. Een oude
 * datum in de json zetten verandert dus niets, en dat hoort ook zo.
 */
function verouder(string $domain, int $seconden = 100): void
{
    touch(Storage::disk('local')->path('hans/brand/' . $domain . '/detail.json'), time() - $seconden);
    clearstatcache();
}

function opgehaald(): array
{
    return collect(Http::recorded())
        ->map(fn (array $paar): string => $paar[0]->url())
        ->filter(fn (string $url): bool => str_ends_with($url, '/'))
        ->values()
        ->all();
}

it('haalt op wat nog geen beeld heeft', function (): void {
    // Een detailbestand zonder logo.webp is precies wat "staat in de wacht"
    // betekent, en dat is uit de listing te zien zonder een bestand te openen.
    $this->store->write(new SiteDetail(domain: 'wacht.be', status: SiteDetail::PENDING), null);

    $this->artisan('brand-fetcher:refresh')->assertSuccessful();

    expect($this->store->detail('wacht.be')->status)->toBe(SiteDetail::OK);
});

it('laat staan wat nog niet verlopen is', function (): void {
    $this->store->write(
        new SiteDetail(domain: 'vers.be', status: SiteDetail::OK, fetchedAt: time(), ttl: 2592000, logoBytes: 10),
        'bytes',
    );

    $this->artisan('brand-fetcher:refresh')->assertSuccessful();

    expect(opgehaald())->toBe([]);
});

it('vernieuwt wat verlopen is', function (): void {
    $this->store->write(
        new SiteDetail(domain: 'oud.be', status: SiteDetail::OK, ttl: 60, logoBytes: 10),
        'bytes',
    );

    verouder('oud.be', 3600);

    $this->artisan('brand-fetcher:refresh')->assertSuccessful();

    expect(opgehaald())->toContain('https://oud.be/');
});

it('stopt na het opgegeven aantal', function (): void {
    foreach (['een.be', 'twee.be', 'drie.be', 'vier.be'] as $domain) {
        $this->store->write(new SiteDetail(domain: $domain, status: SiteDetail::PENDING), null);
    }

    $this->artisan('brand-fetcher:refresh', ['--max' => 2])->assertSuccessful();

    expect(opgehaald())->toHaveCount(2);
});

it('doet niets meer als het budget op is', function (): void {
    $this->store->write(new SiteDetail(domain: 'wacht.be', status: SiteDetail::PENDING), null);

    $this->artisan('brand-fetcher:refresh', ['--budget' => 0])->assertSuccessful();

    expect(opgehaald())->toBe([]);
});

it('ververst een domein dat je met de hand opgeeft', function (): void {
    $this->artisan('brand-fetcher:refresh', ['domain' => ['acme.be']])->assertSuccessful();

    expect($this->store->detail('acme.be')?->status)->toBe(SiteDetail::OK);
});

it('verwijdert een domein op verzoek', function (): void {
    // Dit is de knop achter een verzoek van een merkhouder om het logo weg te
    // halen, dus die moet echt werken.
    $this->store->write(new SiteDetail(domain: 'weg.be', status: SiteDetail::OK, logoBytes: 5), 'bytes');

    $this->artisan('brand-fetcher:refresh', ['domain' => ['weg.be'], '--delete' => true])->assertSuccessful();

    expect($this->store->detail('weg.be'))->toBeNull();
    Storage::disk('local')->assertMissing('hans/brand/weg.be/logo.webp');
});

it('onthoudt tussen twee rondes waar het gebleven was', function (): void {
    foreach (['een.be', 'twee.be'] as $domain) {
        $this->store->write(
            new SiteDetail(domain: $domain, status: SiteDetail::OK, ttl: 60, logoBytes: 10),
            'bytes',
        );

        verouder($domain, 3600);
    }

    $this->artisan('brand-fetcher:refresh', ['--max' => 1])->assertSuccessful();

    expect($this->store->readCursor())->toBe('een.be');
});
