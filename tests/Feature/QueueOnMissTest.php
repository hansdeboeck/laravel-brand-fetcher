<?php

declare(strict_types=1);

use HansDeBoeck\BrandFetcher\BrandFetcher;
use HansDeBoeck\BrandFetcher\Jobs\RefreshBrandJob;
use HansDeBoeck\BrandFetcher\SiteDetail;
use HansDeBoeck\BrandFetcher\Storage\BrandStore;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
| Een domein dat in de wacht komt, gaat ook op de queue.
|
| Met on_miss op monogram krijgt de bezoeker meteen een monogram en doet
| brand-fetcher:refresh het echte werk. Die ronde kan uren duren. Ligt er een
| werker klaar, dan staat het logo er binnen seconden, en dat scheelt precies
| de tijd waarin iedereen naar een letter zit te kijken.
*/

beforeEach(function (): void {
    Storage::fake('local');
    Http::preventStrayRequests();

    config()->set('brand-fetcher.on_miss', 'monogram');
    config()->set('queue.default', 'database');

    Queue::fake();
});

/** Een site met een echt logo, voor de opdracht die daarna draait. */
function fakeSiteVoorDeQueue(): void
{
    Http::fake([
        'https://acme.be/apple-touch-icon.png' => Http::response('', 404),
        'https://acme.be/favicon.ico' => Http::response('', 404),
        'https://acme.be/touch.png' => Http::response(pngFixture(180, 180), 200, ['Content-Type' => 'image/png']),
        'https://acme.be/' => Http::response(htmlFixture([
            'links' => ['<link rel="apple-touch-icon" sizes="180x180" href="/touch.png">'],
        ])),
    ]);
}

it('zet een onbekend domein op de queue en laat de bezoeker niet wachten', function (): void {
    $result = app(BrandFetcher::class)->logo('acme.be');

    expect($result->status)->toBe(SiteDetail::PENDING)
        ->and($result->monogram)->toBeTrue();

    Http::assertNothingSent();
    Queue::assertPushed(RefreshBrandJob::class, fn (RefreshBrandJob $job): bool => $job->domain === 'acme.be');
});

it('laat de queue met rust als er geen echte wachtrij is', function (string $verbinding): void {
    config()->set('queue.default', $verbinding);

    app(BrandFetcher::class)->logo('acme.be');

    Queue::assertNothingPushed();
})->with(['sync', 'null']);

it('laat de queue met rust als de instelling uit staat', function (): void {
    config()->set('brand-fetcher.queue', false);

    expect(app(BrandFetcher::class)->logo('acme.be')->monogram)->toBeTrue();

    Queue::assertNothingPushed();
});

/*
| Zonder monogram komt er geen bestand in de opslag, dus elke volgende opvraging
| loopt opnieuw langs de wacht. De afkoelperiode houdt de rij schoon: een beeld
| hangt in een img-tag en wordt per paginaweergave opgevraagd.
*/
it('zet hetzelfde domein niet twee keer op de rij', function (): void {
    config()->set('brand-fetcher.monogram', false);

    app(BrandFetcher::class)->logo('acme.be');
    app(BrandFetcher::class)->logo('acme.be');

    Queue::assertPushed(RefreshBrandJob::class, 1);
});

/*
| Dit is de melding waar deze reeks om begonnen is: wie alleen het beeld
| opvraagt, kreeg na de eerste keer nooit meer iets in gang gezet. Het monogram
| bleef staan tot de verversopdracht er toevallig langskwam.
*/
it('vraagt opnieuw om het echte logo zodra de afkoelperiode voorbij is', function (): void {
    app(BrandFetcher::class)->logo('acme.be');

    $this->travel(6)->minutes();

    app(BrandFetcher::class)->logo('acme.be');

    Http::assertNothingSent();
    Queue::assertPushed(RefreshBrandJob::class, 2);
});

it('ververst een logo waarvan de verlooptijd om is', function (): void {
    app(BrandStore::class)->write(
        new SiteDetail(domain: 'acme.be', status: SiteDetail::OK, ttl: 60, logoBytes: 10),
        'bytes',
    );

    verouder('acme.be', 3600);

    $logo = app(BrandFetcher::class)->logo('acme.be');

    // Het oude logo gaat gewoon de deur uit; de bezoeker wacht nergens op.
    expect($logo->found)->toBeTrue()
        ->and($logo->status)->toBe(SiteDetail::OK);

    Http::assertNothingSent();
    Queue::assertPushed(RefreshBrandJob::class, 1);
});

it('laat een vers logo met rust', function (): void {
    app(BrandStore::class)->write(
        new SiteDetail(domain: 'acme.be', status: SiteDetail::OK, ttl: 2592000, logoBytes: 10),
        'bytes',
    );

    expect(app(BrandFetcher::class)->logo('acme.be')->found)->toBeTrue();

    Queue::assertNothingPushed();
});

it('laat een fout die nog niet verlopen is met rust', function (): void {
    // Een domein dat blijft falen mag de rij niet vullen: na een mislukte
    // poging staat er een verlooptijd van minstens een dag.
    app(BrandStore::class)->write(
        new SiteDetail(domain: 'acme.be', status: SiteDetail::ERROR, ttl: 86400, logoBytes: 10, monogram: true),
        'bytes',
    );

    expect(app(BrandFetcher::class)->logo('acme.be')->status)->toBe(SiteDetail::ERROR);

    Queue::assertNothingPushed();
});

it('zet een domein dat via de json binnenkomt ook op de rij', function (): void {
    fakeSiteVoorDeQueue();

    app(BrandFetcher::class)->profile('acme.be');

    Queue::assertPushed(RefreshBrandJob::class, 1);

    // En het beeld erna maakt er geen tweede: die opdracht ligt er al.
    app(BrandFetcher::class)->logo('acme.be');

    Queue::assertPushed(RefreshBrandJob::class, 1);
});

it('zet de bewaking uit met een afkoelperiode van nul', function (): void {
    config()->set('brand-fetcher.queue_cooldown', 0);

    app(BrandFetcher::class)->logo('acme.be');
    app(BrandFetcher::class)->logo('acme.be');

    Queue::assertPushed(RefreshBrandJob::class, 2);
});

it('zet het domein toch op de rij als de cache niets onthoudt', function (): void {
    // De bewaking faalt open: een cache die niets bewaart of eruit ligt mag geen
    // logo tegenhouden.
    config()->set('cache.stores.niets', ['driver' => 'null']);
    config()->set('cache.default', 'niets');

    app(BrandFetcher::class)->logo('acme.be');
    app(BrandFetcher::class)->logo('acme.be');

    Queue::assertPushed(RefreshBrandJob::class, 2);
});

it('haalt met die opdracht het echte logo op', function (): void {
    fakeSiteVoorDeQueue();

    (new RefreshBrandJob('acme.be'))->handle(app(BrandFetcher::class));

    $logo = app(BrandFetcher::class)->logo('acme.be');

    expect($logo->status)->toBe(SiteDetail::OK)
        ->and($logo->source)->toBe('apple-touch-icon')
        ->and($logo->monogram)->toBeFalse();
});
