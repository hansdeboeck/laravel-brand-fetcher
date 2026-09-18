<?php

declare(strict_types=1);

use HansDeBoeck\BrandFetcher\BrandFetcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    Http::preventStrayRequests();
});

function fakePage(array $options): void
{
    Http::fake(['https://acme.be/' => Http::response(htmlFixture($options))]);
}

it('crawlt niet opnieuw voor profielen die al gelezen zijn', function (): void {
    // Het beeld staat nog in de wacht, maar de json-vraag is beantwoord: de
    // voorpagina van een ander opnieuw ophalen levert niemand iets op.
    fakePage(['jsonld' => ['@type' => 'Organization', 'sameAs' => ['https://www.facebook.com/Acme']]]);

    app(BrandFetcher::class)->profile('acme.be');

    // Een vers verzoek, alsof een andere bezoeker de json opvraagt: anders
    // verbergt de memo van de crawl wat er gebeurt.
    app()->forgetInstance(BrandFetcher::class);

    expect(app(BrandFetcher::class)->profile('acme.be')->for('facebook')->url)
        ->toBe('https://facebook.com/Acme')
        ->and(count(Http::recorded()))->toBe(1);
});

it('haalt de profielen uit sameAs in de json-ld', function (): void {
    fakePage(['jsonld' => [
        '@context' => 'https://schema.org',
        '@type' => 'Organization',
        'name' => 'Acme NV',
        'sameAs' => [
            'https://www.facebook.com/AcmeBelgie',
            'https://be.linkedin.com/company/acme-nv',
            'https://x.com/AcmeBE',
        ],
    ]]);

    $result = app(BrandFetcher::class)->profile('acme.be');

    expect($result->found)->toBeTrue()
        ->and($result->name)->toBe('Acme NV')
        ->and($result->urls())->toBe([
            'facebook' => 'https://facebook.com/AcmeBelgie',
            'linkedin' => 'https://linkedin.com/company/acme-nv',
            'x' => 'https://x.com/AcmeBE',
        ]);
});

it('laat een verklaring voorgaan op een gevonden link', function (): void {
    fakePage([
        'jsonld' => ['@type' => 'Organization', 'sameAs' => ['https://www.facebook.com/HetEchte']],
        'footer' => ['https://www.facebook.com/IetsAnders'],
    ]);

    expect(app(BrandFetcher::class)->profile('acme.be')->for('facebook')->url)
        ->toBe('https://facebook.com/HetEchte');
});

it('vindt profielen in de voettekst als er geen json-ld is', function (): void {
    fakePage(['footer' => [
        'https://www.instagram.com/acme.belgie/',
        'https://www.youtube.com/@acme',
    ]]);

    expect(app(BrandFetcher::class)->profile('acme.be')->urls())->toBe([
        'instagram' => 'https://instagram.com/acme.belgie',
        'youtube' => 'https://youtube.com/@acme',
    ]);
});

it('laat deelknoppen buiten het antwoord', function (): void {
    fakePage(['footer' => [
        'https://www.facebook.com/sharer/sharer.php?u=https://acme.be/artikel',
        'https://twitter.com/intent/tweet?url=https://acme.be/artikel',
        'https://www.linkedin.com/shareArticle?mini=true&url=https://acme.be',
        'https://wa.me/?text=kijk',
    ]]);

    // Zonder dit filter zou elke site dezelfde vier nutteloze profielen geven.
    expect(app(BrandFetcher::class)->profile('acme.be')->profiles)->toBe([]);
});

it('maakt van twitter:site een profiel als er verder niets is', function (): void {
    fakePage(['metas' => ['<meta name="twitter:site" content="@AcmeBE">']]);

    expect(app(BrandFetcher::class)->profile('acme.be')->for('x')->url)->toBe('https://x.com/AcmeBE');
});

it('laat sameAs voorgaan op twitter:site', function (): void {
    fakePage([
        'metas' => ['<meta name="twitter:site" content="@VanDeKaart">'],
        'jsonld' => ['@type' => 'Organization', 'sameAs' => ['https://x.com/HetEchte']],
    ]);

    expect(app(BrandFetcher::class)->profile('acme.be')->for('x')->url)->toBe('https://x.com/HetEchte');
});

it('negeert een twitter:site die geen handle is', function (): void {
    fakePage(['metas' => ['<meta name="twitter:site" content="https://twitter.com/via/een/pad">']]);

    expect(app(BrandFetcher::class)->profile('acme.be')->has('x'))->toBeFalse();
});

it('herkent mastodon alleen bij een bewuste verklaring', function (): void {
    fakePage(['head' => '<link rel="me" href="https://mastodon.social/@acme">']);

    expect(app(BrandFetcher::class)->profile('acme.be')->for('mastodon')?->url)
        ->toBe('https://mastodon.social/@acme');
});

it('negeert een link naar de eigen site', function (): void {
    fakePage(['footer' => ['https://acme.be/contact', 'https://www.facebook.com/AcmeBelgie']]);

    expect(array_keys(app(BrandFetcher::class)->profile('acme.be')->urls()))->toBe(['facebook']);
});

it('houdt hoogstens een profiel per platform', function (): void {
    fakePage(['footer' => [
        'https://www.facebook.com/Een',
        'https://www.facebook.com/Twee',
        'https://www.facebook.com/Drie',
    ]]);

    expect(app(BrandFetcher::class)->profile('acme.be')->profiles)->toHaveCount(1);
});

it('houdt zich aan het maximum aantal profielen', function (): void {
    config()->set('brand-fetcher.max_profiles', 2);

    fakePage(['footer' => [
        'https://www.facebook.com/Acme',
        'https://x.com/Acme',
        'https://www.instagram.com/Acme',
        'https://github.com/Acme',
    ]]);

    expect(app(BrandFetcher::class)->profile('acme.be')->profiles)->toHaveCount(2);
});

it('geeft een lege lijst en geen fout als er niets te vinden is', function (): void {
    fakePage([]);

    $result = app(BrandFetcher::class)->profile('acme.be');

    expect($result->found)->toBeTrue()
        ->and($result->profiles)->toBe([]);
});

it('weigert iets dat geen domein is', function (): void {
    Http::fake();

    expect(app(BrandFetcher::class)->profile('localhost')->error)->toBe('invalid_domain');

    Http::assertNothingSent();
});

it('laat de profielen een dag bewaren zodra de pagina gezien is', function (): void {
    // De stand van het beeld en die van de profielen zijn twee dingen: het logo
    // kan nog in de wacht staan terwijl dit antwoord al klopt.
    fakePage(['jsonld' => ['@type' => 'Organization', 'sameAs' => ['https://x.com/Acme']]]);

    expect(app(BrandFetcher::class)->profile('acme.be')->maxAge)
        ->toBe(config('brand-fetcher.browser_max_age'));
});

it('laat een onbereikbare site maar kort bewaren', function (): void {
    Http::fake(['*' => Http::response('', 503)]);

    expect(app(BrandFetcher::class)->profile('acme.be')->maxAge)
        ->toBe(config('brand-fetcher.pending_max_age'));
});

it('leest sameAs ook uit een Person', function (): void {
    // Een eenmanszaak of een persoonlijk merk beschrijft zichzelf als Person.
    // Wie alleen naar Organization kijkt, mist precies die sites.
    fakePage(['jsonld' => [
        '@context' => 'https://schema.org',
        '@graph' => [
            ['@type' => 'Person', 'name' => 'Hans De Boeck', 'sameAs' => [
                'https://linkedin.com/in/hansdeboeck',
                'https://x.com/hansdeboeck',
                'https://open.spotify.com/user/hansdeboeck',
            ]],
            ['@type' => 'WebSite', 'name' => 'deboeck.dev'],
        ],
    ]]);

    $result = app(BrandFetcher::class)->profile('acme.be');

    expect($result->name)->toBe('Hans De Boeck')
        ->and(array_keys($result->urls()))->toBe(['linkedin', 'x', 'spotify']);
});

it('leest sameAs uit een ProfessionalService', function (): void {
    fakePage(['jsonld' => [
        '@type' => 'ProfessionalService',
        'name' => 'Acme',
        'sameAs' => ['https://www.pinterest.com/acme'],
    ]]);

    expect(app(BrandFetcher::class)->profile('acme.be')->has('pinterest'))->toBeTrue();
});
