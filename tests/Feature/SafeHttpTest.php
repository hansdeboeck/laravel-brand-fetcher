<?php

declare(strict_types=1);

use HansDeBoeck\BrandFetcher\Net\Budget;
use HansDeBoeck\BrandFetcher\Net\SafeHttp;
use Illuminate\Support\Facades\Http;

function safeHttp(array $overrides = []): SafeHttp
{
    config()->set($overrides);

    return app(SafeHttp::class);
}

function budget(int $ms = 4000): Budget
{
    return new Budget($ms);
}

it('haalt een pagina op', function (): void {
    Http::fake(['acme.be/*' => Http::response('<html>hallo</html>')]);

    $response = safeHttp()->get('https://acme.be/', budget(), 3.0, 65536);

    expect($response->ok)->toBeTrue()
        ->and($response->body)->toContain('hallo');
});

it('volgt een omleiding en controleert elke stap opnieuw', function (): void {
    Http::fake([
        'acme.be/' => Http::response('', 301, ['Location' => 'https://www.acme.be/nieuw']),
        'www.acme.be/nieuw' => Http::response('<html>aangekomen</html>'),
    ]);

    $response = safeHttp()->get('https://acme.be/', budget(), 3.0, 65536);

    expect($response->ok)->toBeTrue()
        ->and($response->body)->toContain('aangekomen')
        ->and($response->finalUrl)->toBe('https://www.acme.be/nieuw');
});

it('stopt bij een omleiding naar een geweigerde host', function (): void {
    // De eerste stap is onschuldig, de tweede wijst naar binnen. Guzzle kan een
    // omleiding niet tegenhouden, dus we hoppen zelf en controleren per stap.
    config()->set('brand-fetcher.allow_private_hosts', false);

    Http::fake([
        'acme.be/' => Http::response('', 302, ['Location' => 'http://intern.lan/geheim']),
    ]);

    $response = safeHttp()->get('https://acme.be/', budget(), 3.0, 65536);

    expect($response->ok)->toBeFalse()
        ->and($response->error)->toBe('bad_scheme');
});

it('stopt na het maximum aantal omleidingen', function (): void {
    // Elke stap een ander adres, anders slaat de lus-detectie eerder toe dan
    // de teller die we hier bedoelen te raken.
    $stap = 0;

    Http::fake(function () use (&$stap) {
        $stap++;

        return Http::response('', 302, ['Location' => 'https://acme.be/stap-' . $stap]);
    });

    $response = safeHttp(['brand-fetcher.max_redirects' => 2])->get('https://acme.be/', budget(), 3.0, 65536);

    expect($response->ok)->toBeFalse()
        ->and($response->error)->toBe('too_many_redirects');
});

it('herkent een omleidingslus', function (): void {
    Http::fake([
        'acme.be/een' => Http::response('', 302, ['Location' => 'https://acme.be/twee']),
        'acme.be/twee' => Http::response('', 302, ['Location' => 'https://acme.be/een']),
    ]);

    $response = safeHttp()->get('https://acme.be/een', budget(), 3.0, 65536);

    expect($response->error)->toBe('redirect_loop');
});

it('kapt een lichaam af op de bytegrens', function (): void {
    Http::fake(['acme.be/*' => Http::response(str_repeat('x', 200000))]);

    $response = safeHttp()->get('https://acme.be/', budget(), 3.0, 16384);

    expect(strlen($response->body))->toBeLessThanOrEqual(16384)
        ->and($response->truncated)->toBeTrue();
});

it('weigert vooraf wat te groot aangekondigd wordt', function (): void {
    Http::fake(['acme.be/*' => Http::response('kort', 200, ['Content-Length' => '9999999'])]);

    expect(safeHttp()->get('https://acme.be/', budget(), 3.0, 16384)->error)->toBe('too_large');
});

it('stopt met lezen zodra het gezochte stuk binnen is', function (): void {
    $html = '<html><head><title>x</title></head><body>' . str_repeat('y', 100000) . '</body></html>';

    Http::fake(['acme.be/*' => Http::response($html)]);

    $response = safeHttp()->get('https://acme.be/', budget(), 3.0, 524288, '</head>');

    expect(strlen($response->body))->toBeLessThan(strlen($html));
});

it('geeft een foutcode bij een status die geen antwoord is', function (): void {
    Http::fake(['acme.be/*' => Http::response('weg', 404)]);

    expect(safeHttp()->get('https://acme.be/', budget(), 3.0, 65536)->error)->toBe('http_404');
});

it('doet niets meer als het budget op is', function (): void {
    Http::fake(['acme.be/*' => Http::response('hallo')]);

    $budget = budget(0);

    expect(safeHttp()->get('https://acme.be/', $budget, 3.0, 65536)->error)->toBe('budget_exhausted');

    Http::assertNothingSent();
});

it('stuurt een user-agent mee waarin we te bereiken zijn', function (): void {
    Http::fake(['acme.be/*' => Http::response('hallo')]);

    safeHttp(['brand-fetcher.user_agent' => 'BrandFetcher/1.0 (+https://deboeck.dev/)'])
        ->get('https://acme.be/', budget(), 3.0, 65536);

    Http::assertSent(fn ($request): bool => $request->header('User-Agent')[0] === 'BrandFetcher/1.0 (+https://deboeck.dev/)');
});

it('leest een grote pagina toch als het eerste stuk genoeg is', function (): void {
    // Een voorpagina van meer dan een halve megabyte is geen uitzondering. Die
    // vooraf afwijzen zou betekenen dat we juist de grootste sites overslaan.
    Http::fake(['acme.be/*' => Http::response(str_repeat('x', 900000), 200, ['Content-Length' => '900000'])]);

    $response = safeHttp()->get('https://acme.be/', budget(), 3.0, 16384, allowPartial: true);

    expect($response->ok)->toBeTrue()
        ->and(strlen($response->body))->toBe(16384)
        ->and($response->truncated)->toBeTrue();
});
