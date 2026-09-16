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

it('behoudt de transparantie rond het logo', function (): void {
    $result = $this->transcoder->transcode(pngFixture(1200, 630), 128, $this->config);
    $image = imagecreatefromstring($result->bytes);

    expect((imagecolorat($image, 2, 2) >> 24) & 0x7F)->toBe(127)
        ->and((imagecolorat($image, 64, 64) >> 24) & 0x7F)->toBe(0);
});

it('schaalt een kleine bron niet op', function (): void {
    $result = $this->transcoder->transcode(pngFixture(16, 16), 128, $this->config);
    $image = imagecreatefromstring($result->bytes);

    $opaque = 0;

    for ($y = 0; $y < 128; $y++) {
        for ($x = 0; $x < 128; $x++) {
            if ((((imagecolorat($image, $x, $y) >> 24) & 0x7F)) < 64) {
                $opaque++;
            }
        }
    }

    // Opschalen zou het hele vlak vullen; passend plaatsen houdt het klein.
    expect($opaque)->toBeLessThan(600);
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
