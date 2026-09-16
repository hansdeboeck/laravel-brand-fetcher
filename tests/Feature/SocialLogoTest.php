<?php

declare(strict_types=1);

use HansDeBoeck\BrandFetcher\BrandFetcher;
use HansDeBoeck\BrandFetcher\SiteDetail;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
| De avatar van een bedrijfspagina als logobron.
|
| Het gaat hier niet om de gelukkige gevallen maar om de twee manieren waarop
| je een grijze placeholder in je merkmap krijgt: facebook levert een poppetje
| voor pagina's zonder profielfoto, linkedin een vierkantje op een ander
| mediadomein. Allebei met status 200, dus ze zien er geslaagd uit.
*/

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('brand-fetcher.on_miss', 'fetch');
});

/*
| De url van linkedin is ondertekend: zonder de exacte t= geeft licdn een 403.
| In de html staat hij met &amp; erin, en die entiteiten moeten er dus uit
| voordat we hem gebruiken. Daarom staan hier twee vormen van dezelfde url.
*/
const LICDN = 'https://media.licdn.com/dms/image/v2/D4E/company-logo_200_200/0/17?e=21&v=beta&t=abc';
const LICDN_HTML = 'https://media.licdn.com/dms/image/v2/D4E/company-logo_200_200/0/17?e=21&amp;v=beta&amp;t=abc';
const FBCDN = 'https://scontent-bru2-1.xx.fbcdn.net/v/t39/1_n.png?stp=dst-png_s480x480&_nc_cat=1';

/** Het antwoord van graph: json met de maten, de url en de silhouetvlag. */
function graphJson(array $overrides = []): string
{
    return (string) json_encode(['data' => $overrides + [
        'height' => 480,
        'width' => 480,
        'is_silhouette' => false,
        'url' => FBCDN,
    ]]);
}

function linkedInPagina(string $image): string
{
    return '<!doctype html><html><head><meta property="og:image" content="' . $image
        . '"><title>Acme</title></head><body>de rest doet er niet toe</body></html>';
}

/**
 * Een site die zelf niets bruikbaars heeft, met de sociale profielen die de
 * test nodig heeft. Wat er verder in $extra staat, overschrijft de fakes.
 */
function fakeSociaal(array $sameAs, array $extra = [], array $paginaOpties = []): void
{
    Http::fake(array_merge([
        'https://acme.be/apple-touch-icon.png' => Http::response('', 404),
        'https://acme.be/favicon.ico' => Http::response('', 404),
        'https://acme.be/' => Http::response(htmlFixture($paginaOpties + [
            'jsonld' => ['@type' => 'Organization', 'sameAs' => $sameAs],
        ])),
        // Deze twee moeten op een ster eindigen: Http::fake vergelijkt de hele
        // url inclusief querystring, en daar zitten de handtekeningen in.
        'https://graph.facebook.com/*' => Http::response(graphJson(), 200, ['Content-Type' => 'application/json']),
        'https://scontent-bru2-1.xx.fbcdn.net/*' => Http::response(pngFixture(480, 480), 200, ['Content-Type' => 'image/png']),
        'https://www.linkedin.com/company/acme-nv/' => Http::response(linkedInPagina(LICDN_HTML)),
        'https://media.licdn.com/*' => Http::response(jpegFixture(200, 200), 200, ['Content-Type' => 'image/jpeg']),
    ], $extra));
}

function verzochten(string $fragment): int
{
    return collect(Http::recorded())
        ->filter(fn (array $paar): bool => str_contains($paar[0]->url(), $fragment))
        ->count();
}

it('gebruikt de avatar van de facebook-bedrijfspagina', function (): void {
    fakeSociaal(['https://www.facebook.com/Acme']);

    $logo = app(BrandFetcher::class)->logo('acme.be');

    expect($logo->source)->toBe('facebook')
        ->and($logo->status)->toBe(SiteDetail::OK)
        ->and($logo->sourceWidth)->toBe(480)
        ->and(getimagesizefromstring($logo->contents())[0])->toBe(128);
});

it('gebruikt de avatar van de linkedin-bedrijfspagina', function (): void {
    fakeSociaal(['https://be.linkedin.com/company/acme-nv']);

    $logo = app(BrandFetcher::class)->logo('acme.be');

    expect($logo->source)->toBe('linkedin')
        ->and($logo->status)->toBe(SiteDetail::OK)
        ->and($logo->sourceWidth)->toBe(200);
});

it('neemt de ondertekende url van linkedin over zoals hij bedoeld is', function (): void {
    fakeSociaal(['https://be.linkedin.com/company/acme-nv']);

    app(BrandFetcher::class)->logo('acme.be');

    // Niet de vorm met &amp; erin: die geeft bij licdn een 403.
    Http::assertSent(fn ($verzoek): bool => $verzoek->url() === LICDN);
});

it('weigert het poppetje van een facebook-pagina zonder profielfoto', function (): void {
    fakeSociaal(['https://www.facebook.com/Acme'], [
        'https://graph.facebook.com/*' => Http::response(graphJson(['is_silhouette' => true])),
    ]);

    expect(app(BrandFetcher::class)->logo('acme.be')->status)->toBe(SiteDetail::MONOGRAM);
    expect(verzochten('fbcdn.net'))->toBe(0);
});

it('weigert een facebook-beeld dat niet op het cdn van facebook staat', function (): void {
    fakeSociaal(['https://www.facebook.com/Acme'], [
        'https://graph.facebook.com/*' => Http::response(graphJson(['url' => 'https://kwaadaardig.example/logo.png'])),
    ]);

    expect(app(BrandFetcher::class)->logo('acme.be')->status)->toBe(SiteDetail::MONOGRAM);
    expect(verzochten('kwaadaardig.example'))->toBe(0);
});

it('valt terug op een monogram als graph de pagina niet kent', function (): void {
    fakeSociaal(['https://www.facebook.com/Acme'], [
        'https://graph.facebook.com/*' => Http::response(['error' => ['message' => 'does not exist']], 400),
    ]);

    expect(app(BrandFetcher::class)->logo('acme.be')->status)->toBe(SiteDetail::MONOGRAM);
});

it('weigert het grijze vierkantje van linkedin op static.licdn.com', function (): void {
    fakeSociaal(['https://be.linkedin.com/company/acme-nv'], [
        'https://www.linkedin.com/company/acme-nv/' => Http::response(
            linkedInPagina('https://static.licdn.com/aero-v1/sc/h/cs8pjfgyw96g44ln9r7tct85f'),
        ),
    ]);

    expect(app(BrandFetcher::class)->logo('acme.be')->status)->toBe(SiteDetail::MONOGRAM);
    expect(verzochten('static.licdn.com'))->toBe(0);
});

/*
| Linkedin weigert datacenters met status 999, en dat is precies wat een
| schoolpagina teruggeeft. Die code is hier niet na te bootsen: de psr-7 van
| guzzle weigert alles boven 599. Het maakt voor de afhandeling niet uit,
| want SafeHttp maakt van elke status buiten 2xx en 3xx hetzelfde:
| een misser met http_<status> erbij.
*/
it('valt terug op een monogram als linkedin ons wegstuurt', function (): void {
    fakeSociaal(['https://be.linkedin.com/company/acme-nv'], [
        'https://www.linkedin.com/company/acme-nv/' => Http::response('', 403),
    ]);

    $logo = app(BrandFetcher::class)->logo('acme.be');

    expect($logo->status)->toBe(SiteDetail::MONOGRAM)
        ->and($logo->error)->toBe('no_usable_candidate')
        ->and(verzochten('licdn.com'))->toBe(0);
});

it('valt terug op een monogram als er geen og:image in de kop staat', function (): void {
    fakeSociaal(['https://be.linkedin.com/company/acme-nv'], [
        'https://www.linkedin.com/company/acme-nv/' => Http::response('<html><head><title>Acme</title></head></html>'),
    ]);

    expect(app(BrandFetcher::class)->logo('acme.be')->status)->toBe(SiteDetail::MONOGRAM);
});

it('probeert een persoonlijk profiel of een schoolpagina niet', function (string $url): void {
    fakeSociaal([$url]);

    app(BrandFetcher::class)->logo('acme.be');

    expect(verzochten('linkedin.com'))->toBe(0);
})->with([
    'persoon' => 'https://www.linkedin.com/in/hansdeboeck',
    'school' => 'https://www.linkedin.com/school/ku-leuven',
]);

it('gebruikt ook een showcase-pagina', function (): void {
    fakeSociaal(['https://www.linkedin.com/showcase/acme-nv'], [
        'https://www.linkedin.com/showcase/acme-nv/' => Http::response(linkedInPagina(LICDN_HTML)),
    ]);

    expect(app(BrandFetcher::class)->logo('acme.be')->source)->toBe('linkedin');
});

it('wint van een kleine favicon', function (): void {
    fakeSociaal(['https://www.facebook.com/Acme'], [
        'https://acme.be/favicon.ico' => Http::response(icoWithPngFixture(32), 200),
    ]);

    expect(app(BrandFetcher::class)->logo('acme.be')->source)->toBe('facebook');
});

it('verliest van een echt apple-touch-icon van de site zelf', function (): void {
    fakeSociaal(
        ['https://www.facebook.com/Acme', 'https://be.linkedin.com/company/acme-nv'],
        ['https://acme.be/touch.png' => Http::response(pngFixture(256, 256), 200, ['Content-Type' => 'image/png'])],
        ['links' => ['<link rel="apple-touch-icon" sizes="256x256" href="/touch.png">']],
    );

    expect(app(BrandFetcher::class)->logo('acme.be')->source)->toBe('apple-touch-icon');
});

/*
| De kandidatenlijst wordt twee keer gelezen, voor de keuze en voor de svg-url.
| Werd hij ook twee keer verzameld, dan ging elke bron die zelf het net op gaat
| dubbel op pad.
*/
it('benadert elke sociale bron hoogstens een keer', function (): void {
    fakeSociaal(['https://www.facebook.com/Acme', 'https://be.linkedin.com/company/acme-nv']);

    app(BrandFetcher::class)->logo('acme.be');

    expect(verzochten('graph.facebook.com'))->toBe(1)
        ->and(verzochten('www.linkedin.com'))->toBe(1);
});

it('laat de instelling een bron helemaal uitzetten', function (string $sleutel, string $host, array $sameAs): void {
    config()->set('brand-fetcher.' . $sleutel, false);
    fakeSociaal($sameAs);

    app(BrandFetcher::class)->logo('acme.be');

    expect(verzochten($host))->toBe(0);
})->with([
    'facebook' => ['facebook_logo', 'graph.facebook.com', ['https://www.facebook.com/Acme']],
    'linkedin' => ['linkedin_logo', 'www.linkedin.com', ['https://be.linkedin.com/company/acme-nv']],
]);

it('gaat niet op pad als alleen de profielen gevraagd worden', function (): void {
    fakeSociaal(['https://www.facebook.com/Acme', 'https://be.linkedin.com/company/acme-nv']);

    app(BrandFetcher::class)->profile('acme.be');

    expect(verzochten('graph.facebook.com'))->toBe(0)
        ->and(verzochten('www.linkedin.com'))->toBe(0);
});
