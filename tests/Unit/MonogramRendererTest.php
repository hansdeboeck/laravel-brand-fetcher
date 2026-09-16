<?php

declare(strict_types=1);

use HansDeBoeck\BrandFetcher\Image\MonogramRenderer;

beforeEach(function (): void {
    $this->renderer = new MonogramRenderer();
    $this->config = config('brand-fetcher');
});

/*
| Een monogram is een vierkant en geen schijf.
|
| Alles wat dit package aflevert vult zijn vlak tot in de hoeken. Een ronde
| terugval valt uit de toon in een lijst met vierkante logo's, en de
| doorzichtige hoeken die erbij horen vallen weg op een donkere achtergrond.
*/
it('vult het hele vierkant, tot in de hoeken', function (): void {
    $bytes = $this->renderer->render('acme.be', 128, $this->config);
    [$rood, $groen, $blauw] = $this->renderer->tint('acme.be');

    [$breedte, $hoogte, $type] = getimagesizefromstring($bytes);

    expect($breedte)->toBe(128)
        ->and($hoogte)->toBe(128)
        ->and($type)->toBe(IMAGETYPE_WEBP);

    $image = imagecreatefromstring($bytes);

    foreach ([[0, 0], [127, 0], [0, 127], [127, 127]] as [$x, $y]) {
        $kleur = imagecolorat($image, $x, $y);

        expect(($kleur >> 24) & 0x7F)->toBe(0)
            ->and(($kleur >> 16) & 0xFF)->toEqualWithDelta($rood, 8)
            ->and(($kleur >> 8) & 0xFF)->toEqualWithDelta($groen, 8)
            ->and($kleur & 0xFF)->toEqualWithDelta($blauw, 8);
    }
});

it('zet de letter wit in het midden', function (): void {
    $bytes = $this->renderer->render('acme.be', 128, $this->config);
    $image = imagecreatefromstring($bytes);

    $wit = 0;

    for ($y = 0; $y < 128; $y++) {
        for ($x = 0; $x < 128; $x++) {
            if ((imagecolorat($image, $x, $y) & 0xFF) > 230 && ((imagecolorat($image, $x, $y) >> 16) & 0xFF) > 230) {
                $wit++;
            }
        }
    }

    // Een A op 46 procent van de zijde: genoeg pixels om er te staan, en lang
    // niet genoeg om het vlak te vullen.
    expect($wit)->toBeGreaterThan(200)
        ->and($wit)->toBeLessThan(4000);
});
