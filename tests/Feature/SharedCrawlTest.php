<?php

declare(strict_types=1);

use HansDeBoeck\BrandFetcher\BrandFetcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
| Hier hangt de hele opzet aan: het logo en de sociale profielen komen uit
| dezelfde voorpagina. Gaat dat stuk, dan bezoeken we andermans site twee keer
| voor iets wat een keer kon, en dat merkt niemand aan de uitkomst.
*/

beforeEach(function (): void {
    Storage::fake('local');
    Http::preventStrayRequests();
    config()->set('brand-fetcher.on_miss', 'fetch');

    Http::fake([
        'https://acme.be/apple-touch-icon.png' => Http::response('', 404),
        'https://acme.be/favicon.ico' => Http::response('', 404),
        'https://acme.be/touch.png' => Http::response(pngFixture(256, 256), 200, ['Content-Type' => 'image/png']),
        'https://acme.be/' => Http::response(htmlFixture([
            'links' => ['<link rel="apple-touch-icon" sizes="256x256" href="/touch.png">'],
            'jsonld' => ['@type' => 'Organization', 'sameAs' => ['https://www.facebook.com/Acme']],
        ])),
    ]);
});

function voorpaginas(): int
{
    return collect(Http::recorded())
        ->filter(fn (array $paar): bool => $paar[0]->url() === 'https://acme.be/')
        ->count();
}

it('haalt de voorpagina een keer op voor beide antwoorden', function (): void {
    $fetcher = app(BrandFetcher::class);

    $fetcher->profile('acme.be');
    $fetcher->logo('acme.be');

    expect(voorpaginas())->toBe(1);
});

it('doet dat ook in de omgekeerde volgorde', function (): void {
    $fetcher = app(BrandFetcher::class);

    $fetcher->logo('acme.be');
    $fetcher->profile('acme.be');

    expect(voorpaginas())->toBe(1);
});

it('warmt de profielen op als iemand het beeld opvraagt', function (): void {
    app(BrandFetcher::class)->logo('acme.be');

    // Een vers verzoek, alsof een andere bezoeker de json opvraagt: dat mag
    // niets meer kosten, want alles staat al in detail.json.
    app()->forgetInstance(BrandFetcher::class);

    $profiel = app(BrandFetcher::class)->profile('acme.be');

    expect($profiel->for('facebook')->url)->toBe('https://facebook.com/Acme')
        ->and(voorpaginas())->toBe(1);
});

it('haalt geen beelden op als alleen de profielen gevraagd worden', function (): void {
    app(BrandFetcher::class)->profile('acme.be');

    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'touch.png'));
});
