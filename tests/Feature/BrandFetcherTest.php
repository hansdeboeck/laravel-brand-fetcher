<?php

declare(strict_types=1);

use HansDeBoeck\BrandFetcher\BrandFetcher;
use HansDeBoeck\BrandFetcher\SiteDetail;
use HansDeBoeck\BrandFetcher\Storage\BrandStore;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    Http::preventStrayRequests();

    // De meeste tests hier gaan over het ophalen zelf, dus die willen dat het
    // ter plaatse gebeurt in plaats van in de wachtrij te belanden.
    config()->set('brand-fetcher.on_miss', 'fetch');
});

function fetcher(): BrandFetcher
{
    return app(BrandFetcher::class);
}

/*
| Volledige urls als sleutel, en niet "acme.be": Http::fake zet er zelf een
| sterretje voor, dus een kaal domein matcht alleen een url die daarop eindigt
| en nooit https://acme.be/ met zijn slash.
*/
function fakeSite(array $options = [], array $extra = []): void
{
    Http::fake(array_merge($extra, [
        'https://acme.be/apple-touch-icon.png' => Http::response('', 404),
        'https://acme.be/favicon.ico' => Http::response('', 404),
        'https://acme.be/' => Http::response(htmlFixture($options)),
    ]));
}

it('haalt een logo op en schrijft het beeld met de details', function (): void {
    fakeSite(
        ['links' => ['<link rel="apple-touch-icon" sizes="180x180" href="/touch.png">']],
        ['https://acme.be/touch.png' => Http::response(pngFixture(180, 180), 200, ['Content-Type' => 'image/png'])],
    );

    $result = fetcher()->logo('acme.be');

    expect($result->found)->toBeTrue()
        ->and($result->status)->toBe(SiteDetail::OK)
        ->and($result->source)->toBe('apple-touch-icon')
        ->and($result->monogram)->toBeFalse();

    [$width, $height, $type] = getimagesizefromstring((string) $result->contents());

    expect($width)->toBe(128)->and($height)->toBe(128)->and($type)->toBe(IMAGETYPE_WEBP);

    Storage::disk('local')->assertExists('hans/brand/acme.be/logo.webp');
    Storage::disk('local')->assertExists('hans/brand/acme.be/detail.json');
});

it('kiest een vierkante bron boven een liggende', function (): void {
    fakeSite([
        'links' => ['<link rel="icon" sizes="256x256" href="/vierkant.png">'],
        'metas' => ['<meta property="og:image" content="/banner.png">'],
    ], [
        'https://acme.be/vierkant.png' => Http::response(pngFixture(256, 256), 200, ['Content-Type' => 'image/png']),
        'https://acme.be/banner.png' => Http::response(pngFixture(1200, 630), 200, ['Content-Type' => 'image/png']),
    ]);

    expect(fetcher()->logo('acme.be')->sourceRatio)->toBe(1.0);
});

it('valt terug op een monogram als er niets bruikbaars staat', function (): void {
    fakeSite();

    $result = fetcher()->logo('acme.be');

    expect($result->found)->toBeTrue()
        ->and($result->status)->toBe(SiteDetail::MONOGRAM)
        ->and($result->monogram)->toBeTrue()
        ->and($result->source)->toBe('monogram');

    // Ook een terugval is een echt beeld: een img-tag hoort geen kruisje te tonen.
    expect(getimagesizefromstring((string) $result->contents())[0])->toBe(128);
});

it('doet geen enkel verzoek als er al een bestand staat', function (): void {
    fakeSite(
        ['links' => ['<link rel="apple-touch-icon" href="/touch.png">']],
        ['https://acme.be/touch.png' => Http::response(pngFixture(180, 180), 200, ['Content-Type' => 'image/png'])],
    );

    fetcher()->logo('acme.be');
    $verzonden = count(Http::recorded());

    fetcher()->logo('acme.be');

    expect(count(Http::recorded()))->toBe($verzonden);
});

it('zet bij een onbekend domein meteen een monogram klaar in plaats van te wachten', function (): void {
    // Dit is de stand op gedeelde hosting: geen enkel verzoek mag op een crawl
    // wachten, dus het echte werk gaat naar de verversopdracht.
    config()->set('brand-fetcher.on_miss', 'monogram');

    $result = fetcher()->logo('acme.be');

    expect($result->status)->toBe(SiteDetail::PENDING)
        ->and($result->monogram)->toBeTrue()
        ->and($result->maxAge)->toBe(config('brand-fetcher.pending_max_age'));

    Http::assertNothingSent();

    // En de opdracht kan het oppakken, want de entry is meteen verlopen.
    expect(app(BrandStore::class)->detail('acme.be')->isStale())->toBeTrue();
});

it('weigert iets dat geen domein is zonder ook maar een verzoek te doen', function (string $input): void {
    Http::fake();

    $result = fetcher()->logo($input);

    expect($result->found)->toBeFalse()
        ->and($result->error)->toBe('invalid_domain');

    Http::assertNothingSent();
})->with(['localhost', '127.0.0.1', 'geendomein', '', 'server.local']);

it('houdt zich aan het maximum aantal downloads', function (): void {
    config()->set('brand-fetcher.max_downloads', 2);
    config()->set('brand-fetcher.good_enough_score', 9999);

    fakeSite([
        'links' => [
            '<link rel="icon" sizes="64x64" href="/een.png">',
            '<link rel="icon" sizes="64x64" href="/twee.png">',
            '<link rel="icon" sizes="64x64" href="/drie.png">',
        ],
    ], [
        // Elk verzoek een vers antwoord: een gedeeld nepantwoord levert bij de
        // tweede lezing een dichtgevallen stroom op, en dat meet iets anders
        // dan wat deze test bedoelt.
        'https://acme.be/*.png' => fn () => Http::response(pngFixture(64, 64), 200, ['Content-Type' => 'image/png']),
    ]);

    fetcher()->logo('acme.be');

    $beelden = collect(Http::recorded())->filter(fn ($paar): bool => str_ends_with($paar[0]->url(), '.png'));

    expect($beelden)->toHaveCount(2);
});

it('stopt met zoeken zodra een kandidaat goed genoeg is', function (): void {
    fakeSite([
        'links' => [
            '<link rel="apple-touch-icon" sizes="256x256" href="/goed.png">',
            '<link rel="icon" sizes="64x64" href="/minder.png">',
        ],
    ], [
        'https://acme.be/goed.png' => Http::response(pngFixture(256, 256), 200, ['Content-Type' => 'image/png']),
        'https://acme.be/minder.png' => Http::response(pngFixture(64, 64), 200, ['Content-Type' => 'image/png']),
    ]);

    fetcher()->logo('acme.be');

    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'minder.png'));
});

it('levert een monogram als de site niet te bereiken is', function (): void {
    Http::fake(['*' => Http::response('', 503)]);

    $result = fetcher()->logo('acme.be');

    expect($result->status)->toBe(SiteDetail::ERROR)
        ->and($result->monogram)->toBeTrue()
        ->and($result->error)->toBe('http_503');
});

it('probeert een mislukte ophaling niet bij elk verzoek opnieuw', function (): void {
    Http::fake(['*' => Http::response('', 503)]);

    fetcher()->logo('acme.be');
    $eerste = count(Http::recorded());

    fetcher()->logo('acme.be');

    expect(count(Http::recorded()))->toBe($eerste);
});

it('leest een ico als de site alleen een favicon heeft', function (): void {
    Http::fake([
        'https://acme.be/' => Http::response(htmlFixture()),
        'https://acme.be/apple-touch-icon.png' => Http::response('', 404),
        'https://acme.be/favicon.ico' => Http::response(icoWithPngFixture(128), 200, ['Content-Type' => 'image/x-icon']),
    ]);

    $result = fetcher()->logo('acme.be');

    expect($result->status)->toBe(SiteDetail::OK)
        ->and($result->source)->toBe('favicon.ico');
});

/*
| De kandidatenlijst wordt twee keer gelezen, voor de keuze en voor de svg-url,
| maar hij hoort maar een keer verzameld te worden. Gebeurt dat niet, dan gaat
| elke bron die er zelf het net voor op moet ook twee keer op pad, en het
| manifest is daar de oudste van.
*/
it('haalt het manifest maar een keer op', function (): void {
    fakeSite(
        ['links' => ['<link rel="manifest" href="/site.webmanifest">']],
        [
            'https://acme.be/site.webmanifest' => Http::response(
                manifestFixture([['src' => '/icon.png', 'sizes' => '512x512', 'type' => 'image/png']]),
            ),
            'https://acme.be/icon.png' => Http::response(pngFixture(512, 512), 200, ['Content-Type' => 'image/png']),
        ],
    );

    expect(fetcher()->logo('acme.be')->source)->toBe('manifest');

    expect(collect(Http::recorded())
        ->filter(fn (array $paar): bool => str_contains($paar[0]->url(), 'webmanifest'))
        ->count())->toBe(1);
});
