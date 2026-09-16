<?php

declare(strict_types=1);

use HansDeBoeck\BrandFetcher\SiteDetail;
use HansDeBoeck\BrandFetcher\Storage\BrandStore;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    $this->store = app(BrandStore::class);
});

it('schrijft het beeld en de details op de afgesproken plek', function (): void {
    $this->store->write(new SiteDetail(domain: 'acme.be', status: SiteDetail::OK, fetchedAt: time(), ttl: 100), 'beeldbytes');

    Storage::disk('local')->assertExists('hans/brand/acme.be/logo.webp');
    Storage::disk('local')->assertExists('hans/brand/acme.be/detail.json');
});

it('volgt de ingestelde map', function (): void {
    config()->set('brand-fetcher.storage.folder', 'ergens/anders');

    app()->forgetInstance(BrandStore::class);
    app(BrandStore::class)->write(new SiteDetail(domain: 'acme.be'), 'bytes');

    Storage::disk('local')->assertExists('ergens/anders/acme.be/logo.webp');
});

it('weigert een domeinnaam die de padcontrole niet haalt', function (string $domain): void {
    expect(fn () => $this->store->directory($domain))->toThrow(InvalidArgumentException::class);
})->with([
    '../etc/passwd',
    'a/b',
    'a\\b',
    "a\0b",
    'localhost',
    '',
]);

it('behandelt een entry zonder detailbestand als afwezig', function (): void {
    Storage::disk('local')->put('hans/brand/acme.be/logo.webp', 'bytes');

    expect($this->store->detail('acme.be'))->toBeNull();
});

it('behandelt een onleesbaar detailbestand als afwezig', function (): void {
    Storage::disk('local')->put('hans/brand/acme.be/detail.json', 'dit is geen json');

    expect($this->store->detail('acme.be'))->toBeNull();
});

it('negeert een detailbestand van een andere versie', function (): void {
    // Zo verlopen oude vormen vanzelf: geen migraties nodig.
    Storage::disk('local')->put('hans/brand/acme.be/detail.json', json_encode(['v' => 99, 'domain' => 'acme.be']));

    expect($this->store->detail('acme.be'))->toBeNull();
});

it('leest de klok uit de mtime van het detailbestand', function (): void {
    $this->store->write(new SiteDetail(domain: 'acme.be', status: SiteDetail::OK, fetchedAt: 1, ttl: 100), 'bytes');

    // In het bestand staat 1, maar de mtime is nu, en die wint.
    expect($this->store->detail('acme.be')->fetchedAt)->toBeGreaterThan(1000000);
});

it('geeft geen url terug op een disk die er geen kent', function (): void {
    // De lokale adapter verzint anders /storage/..., en dat wijst naar een
    // andere map dan waar onze bestanden staan.
    expect($this->store->url('acme.be'))->toBeNull();
});

it('loopt het archief af met de mtime erbij', function (): void {
    $this->store->write(new SiteDetail(domain: 'een.be', status: SiteDetail::OK), 'bytes');
    $this->store->write(new SiteDetail(domain: 'twee.be', status: SiteDetail::PENDING), null);

    $entries = iterator_to_array($this->store->walk());

    expect($entries)->toHaveCount(2)
        ->and($entries[0]['domain'])->toBe('een.be')
        ->and($entries[0]['hasLogo'])->toBeTrue()
        ->and($entries[1]['domain'])->toBe('twee.be')
        ->and($entries[1]['hasLogo'])->toBeFalse();
});

it('onthoudt waar de vorige ronde stopte', function (): void {
    expect($this->store->readCursor())->toBeNull();

    $this->store->writeCursor('acme.be');

    expect($this->store->readCursor())->toBe('acme.be');
});

it('verwijdert een domein volledig', function (): void {
    $this->store->write(new SiteDetail(domain: 'acme.be', status: SiteDetail::OK), 'bytes');
    $this->store->forget('acme.be');

    Storage::disk('local')->assertMissing('hans/brand/acme.be/logo.webp');
    expect($this->store->detail('acme.be'))->toBeNull();
});

it('bewaart een internationaal domein als punycode', function (): void {
    app(\HansDeBoeck\BrandFetcher\BrandFetcher::class);

    $this->store->write(new SiteDetail(
        domain: 'xn--mnchen-3ya.de',
        domainUnicode: "m\u{fc}nchen.de",
        status: SiteDetail::OK,
    ), 'bytes');

    // Punycode is pure ascii, en dus veilig als mapnaam en als s3-sleutel.
    Storage::disk('local')->assertExists('hans/brand/xn--mnchen-3ya.de/detail.json');

    expect($this->store->detail('xn--mnchen-3ya.de')->domainUnicode)->toBe("m\u{fc}nchen.de");
});
