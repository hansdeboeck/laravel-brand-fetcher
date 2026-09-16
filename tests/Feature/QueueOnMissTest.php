<?php

declare(strict_types=1);

use HansDeBoeck\BrandFetcher\BrandFetcher;
use HansDeBoeck\BrandFetcher\Jobs\RefreshBrandJob;
use HansDeBoeck\BrandFetcher\SiteDetail;
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
| Zonder monogram komt er geen bestand in de opslag, dus elke volgende
| opvraging loopt opnieuw langs de wacht. De opdracht ligt er dan al.
*/
it('zet hetzelfde domein niet twee keer op de rij', function (): void {
    config()->set('brand-fetcher.monogram', false);

    app(BrandFetcher::class)->logo('acme.be');
    app(BrandFetcher::class)->logo('acme.be');

    Queue::assertPushed(RefreshBrandJob::class, 1);
});

it('haalt met die opdracht het echte logo op', function (): void {
    fakeSiteVoorDeQueue();

    (new RefreshBrandJob('acme.be'))->handle(app(BrandFetcher::class));

    $logo = app(BrandFetcher::class)->logo('acme.be');

    expect($logo->status)->toBe(SiteDetail::OK)
        ->and($logo->source)->toBe('apple-touch-icon')
        ->and($logo->monogram)->toBeFalse();
});
