<?php

declare(strict_types=1);

use HansDeBoeck\BrandFetcher\Image\ImageTranscoder;

beforeEach(function (): void {
    $this->transcoder = new ImageTranscoder();
    $this->config = config('brand-fetcher');
});

it('levert altijd een vierkante webp van 128 pixels', function (string $bytes): void {
    $result = $this->transcoder->transcode($bytes, 128, $this->config);

    expect($result)->not->toBeNull();

    [$width, $height, $type] = getimagesizefromstring($result->bytes);

    expect($width)->toBe(128)
        ->and($height)->toBe(128)
        ->and($type)->toBe(IMAGETYPE_WEBP);
})->with([
    'png vierkant' => fn () => pngFixture(512, 512),
    'png liggend' => fn () => pngFixture(1200, 630),
    'png klein' => fn () => pngFixture(16, 16),
    'jpeg' => fn () => jpegFixture(400, 400),
    'ico met png-frame' => fn () => icoWithPngFixture(256),
    'ico met dib-frame' => fn () => icoFixture([[48, 48, 24, dibFrameFixture(48, 48, 24)]]),
]);

/** De kleur en de doorzichtigheid van een pixel in de uitvoer. */
function kleurOp(string $bytes, int $x, int $y): array
{
    $colour = imagecolorat(imagecreatefromstring($bytes), $x, $y);

    return [($colour >> 16) & 0xFF, ($colour >> 8) & 0xFF, $colour & 0xFF, ($colour >> 24) & 0x7F];
}

it('vult de lucht met de hoofdkleur van de rand', function (): void {
    // Een liggend beeld met een blauw vlak eromheen wordt een blauwe tegel en
    // geen band die in het niets zweeft.
    $result = $this->transcoder->transcode(bannerFixture(512, 256, [10, 60, 180]), 128, $this->config);

    [$rood, $groen, $blauw, $alfa] = kleurOp($result->bytes, 2, 2);

    expect($alfa)->toBe(0)
        ->and($rood)->toEqualWithDelta(10, 8)
        ->and($groen)->toEqualWithDelta(60, 8)
        ->and($blauw)->toEqualWithDelta(180, 8);
});

it('vult met wit als het logo zelf geen achtergrond heeft', function (): void {
    $result = $this->transcoder->transcode(pngFixture(512, 256), 128, $this->config);

    [$rood, $groen, $blauw, $alfa] = kleurOp($result->bytes, 2, 2);

    expect($alfa)->toBe(0)
        ->and(min($rood, $groen, $blauw))->toBeGreaterThan(247);
});

it('behoudt de transparantie rond het logo als pad op transparent staat', function (): void {
    $config = $this->config;
    $config['pad'] = 'transparent';

    $result = $this->transcoder->transcode(pngFixture(1200, 630), 128, $config);
    $image = imagecreatefromstring($result->bytes);

    expect((imagecolorat($image, 2, 2) >> 24) & 0x7F)->toBe(127)
        ->and((imagecolorat($image, 64, 64) >> 24) & 0x7F)->toBe(0);
});

/*
| De harde score beloont een bron met alfa, want die is vrijwel altijd een echt
| logo. Dat signaal gaat over de bron en niet over wat wij afleveren: werd het
| op de uitvoer gemeten, dan viel het met een dekkende vulling voor iedereen weg.
*/
it('meldt de doorzichtigheid van de bron en niet van de opgevulde uitvoer', function (): void {
    $doorzichtig = $this->transcoder->transcode(pngFixture(512, 256), 128, $this->config);
    $dekkend = $this->transcoder->transcode(bannerFixture(512, 256, [10, 60, 180]), 128, $this->config);

    expect($doorzichtig->hasAlpha)->toBeTrue()
        ->and($dekkend->hasAlpha)->toBeFalse();
});

it('schaalt een kleine bron niet op', function (): void {
    $result = $this->transcoder->transcode(pngFixture(16, 16), 128, $this->config);
    $image = imagecreatefromstring($result->bytes);

    $merk = 0;

    for ($y = 0; $y < 128; $y++) {
        for ($x = 0; $x < 128; $x++) {
            // Alles wat niet de witte vulling is, hoort bij het beeldmerk.
            if (((imagecolorat($image, $x, $y) >> 8) & 0xFF) < 200) {
                $merk++;
            }
        }
    }

    // Opschalen zou het hele vlak vullen; passend plaatsen houdt het klein.
    expect($merk)->toBeLessThan(600);
});

it('snijdt de lucht rond een logo weg', function (): void {
    $result = $this->transcoder->transcode(pngFixture(180, 180, padding: 40), 128, $this->config);

    expect($result->trimmed)->toBeTrue();
});

it('meet een ico op het beste frame en niet op wat php beweert', function (): void {
    $ico = icoFixture([
        [256, 256, 32, pngFixture(256, 256)],
        [16, 16, 32, dibFrameFixture(16, 16, 32)],
    ]);

    // getimagesizefromstring geeft hier 16x16; dat zou elke ico ten onrechte
    // afstraffen bij het scoren.
    expect(ImageTranscoder::measure($ico, 128)[0])->toBe(256);
});

it('weigert een bron die te groot is zonder hem te decoderen', function (): void {
    $config = $this->config;
    $config['max_source_edge'] = 100;

    expect($this->transcoder->transcode(pngFixture(512, 512), 128, $config))->toBeNull();
});

it('geeft null bij bytes die geen beeld zijn', function (string $bytes): void {
    expect($this->transcoder->transcode($bytes, 128, $this->config))->toBeNull();
})->with([
    'rommel' => 'dit is geen beeld',
    'leeg' => '',
    'volledig doorzichtig' => fn () => emptyPngFixture(64),
]);

it('kiest de kleinste van lossy en lossless', function (): void {
    // Een vlak logo is lossless een stuk kleiner; een foto juist niet.
    $vlak = $this->transcoder->transcode(pngFixture(256, 256), 128, $this->config);
    $foto = $this->transcoder->transcode(jpegFixture(256, 256), 128, $this->config);

    expect($vlak->lossless)->toBeTrue()
        ->and($foto->lossless)->toBeFalse();
});
