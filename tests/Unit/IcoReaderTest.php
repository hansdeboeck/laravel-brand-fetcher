<?php

declare(strict_types=1);

use HansDeBoeck\BrandFetcher\Image\IcoReader;

it('leest een dib-frame van elke bitdiepte die we ondersteunen', function (int $bpp, int $size): void {
    $result = IcoReader::best(icoFixture([[$size, $size, $bpp, dibFrameFixture($size, $size, $bpp)]]), 128);

    expect($result)->not->toBeNull()
        ->and($result['width'])->toBe($size)
        ->and($result['height'])->toBe($size);
})->with([
    [32, 32],
    [24, 48],
    [8, 16],
]);

it('leest een png-frame in een ico', function (): void {
    $result = IcoReader::best(icoWithPngFixture(256), 128);

    expect($result)->not->toBeNull()
        ->and($result['width'])->toBe(256);
});

it('kiest het frame dat het dichtst bij de gevraagde maat ligt', function (): void {
    $ico = icoFixture([
        [16, 16, 32, dibFrameFixture(16, 16, 32)],
        [64, 64, 32, dibFrameFixture(64, 64, 32)],
        [32, 32, 32, dibFrameFixture(32, 32, 32)],
    ]);

    // Te klein weegt zwaarder dan te groot, dus 64 wint van 32 en 16.
    expect(IcoReader::best($ico, 128)['width'])->toBe(64);
});

it('kiest een groot png-frame boven een klein dib-frame', function (): void {
    $ico = icoFixture([
        [32, 32, 32, dibFrameFixture(32, 32, 32)],
        [256, 256, 32, pngFixture(256, 256)],
    ]);

    expect(IcoReader::best($ico, 128)['width'])->toBe(256);
});

it('past het masker toe als het alfakanaal helemaal nul is', function (): void {
    // De klassieke valkuil: oude iconen bedoelen met alfa nul juist "dekkend",
    // en zetten de doorzichtigheid in het masker.
    $ico = icoFixture([[16, 16, 8, dibFrameFixture(16, 16, 8, [250, 200, 10], maskBorder: true)]]);
    $image = IcoReader::best($ico, 128)['image'];

    $corner = (imagecolorat($image, 0, 0) >> 24) & 0x7F;
    $middle = (imagecolorat($image, 8, 8) >> 24) & 0x7F;

    expect($corner)->toBe(127)
        ->and($middle)->toBe(0);
});

it('geeft null bij alles wat geen leesbare ico is', function (string $bytes): void {
    expect(IcoReader::best($bytes, 128))->toBeNull();
})->with([
    'leeg' => '',
    'te kort' => "\x00\x01",
    'een png' => fn () => pngFixture(8, 8),
    'afgekapt' => fn () => substr(icoFixture([[32, 32, 32, dibFrameFixture(32, 32, 32)]]), 0, 40),
]);

it('slaat een ingang over die buiten het bestand wijst', function (): void {
    $ico = icoFixture([[32, 32, 32, dibFrameFixture(32, 32, 32)]]);

    // De offset van het eerste frame naar voorbij het einde schuiven.
    $broken = substr($ico, 0, 18) . pack('V', strlen($ico) + 500) . substr($ico, 22);

    expect(IcoReader::frames($broken))->toBe([]);
});
