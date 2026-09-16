<?php

declare(strict_types=1);

namespace HansDeBoeck\BrandFetcher\Image;

use GdImage;

/**
 * Snijdt de lucht rond een logo weg.
 *
 * Waarom niet imagecropauto: die functie heeft twee eigenschappen die hier de
 * verkeerde kant op vallen. Met drempel 0 snijdt ze nooit, want gd vergelijkt
 * met kleiner-dan en dan matcht niets. En ze vergelijkt kleur en doorzichtigheid
 * samen tegen een referentiekleur, waardoor een doorzichtig-witte rand niet
 * herkend wordt als je doorzichtig-zwart als referentie geeft. Beide zijn hier
 * uitgeprobeerd; vandaar deze eigen scan.
 *
 * De kaders worden gezocht op een verkleinde kopie. Dat scheelt op een beeld van
 * duizend pixels breed het grootste deel van de tijd, en preciezer dan een
 * proxypixel hoeft het niet: we schalen daarna toch naar 128.
 */
final class Trimmer
{
    private const PROXY_EDGE = 256;

    /** Onder deze waarde geldt een pixel als doorzichtig. */
    private const ALPHA_TRANSPARENT = 120;

    /**
     * @param  array<string, mixed>  $config
     * @return GdImage|null null als het beeld helemaal uit achtergrond bestaat
     */
    public function trim(GdImage $source, array $config): ?GdImage
    {
        if (($config['trim'] ?? true) === false) {
            return $source;
        }

        $width = imagesx($source);
        $height = imagesy($source);

        $factor = max(1.0, max($width, $height) / self::PROXY_EDGE);
        $proxyWidth = max(1, (int) round($width / $factor));
        $proxyHeight = max(1, (int) round($height / $factor));

        $proxy = imagecreatetruecolor($proxyWidth, $proxyHeight);
        imagealphablending($proxy, false);
        imagesavealpha($proxy, true);
        imagecopyresampled($proxy, $source, 0, 0, 0, 0, $proxyWidth, $proxyHeight, $width, $height);

        $corner = imagecolorat($proxy, 0, 0);
        $backgroundAlpha = ($corner >> 24) & 0x7F;
        $backgroundRgb = $corner & 0xFFFFFF;
        $tolerance = max(0, (int) ($config['trim_threshold'] ?? 8));

        $minX = $proxyWidth;
        $minY = $proxyHeight;
        $maxX = -1;
        $maxY = -1;

        for ($y = 0; $y < $proxyHeight; $y++) {
            for ($x = 0; $x < $proxyWidth; $x++) {
                $color = imagecolorat($proxy, $x, $y);
                $alpha = ($color >> 24) & 0x7F;

                if ($backgroundAlpha >= self::ALPHA_TRANSPARENT) {
                    /*
                    | De achtergrond is doorzichtig, dus kijk alleen naar alfa.
                    | De kleur onder een doorzichtige pixel is willekeurig: de
                    | ene encoder zet er zwart neer, de andere de laatste kleur.
                    | Daarop vergelijken zou hier de mist in gaan.
                    */
                    $isBackground = $alpha >= self::ALPHA_TRANSPARENT;
                } else {
                    $isBackground = abs($alpha - $backgroundAlpha) <= 4
                        && abs((($color >> 16) & 0xFF) - (($backgroundRgb >> 16) & 0xFF)) <= $tolerance
                        && abs((($color >> 8) & 0xFF) - (($backgroundRgb >> 8) & 0xFF)) <= $tolerance
                        && abs(($color & 0xFF) - ($backgroundRgb & 0xFF)) <= $tolerance;
                }

                if (! $isBackground) {
                    $minX = min($minX, $x);
                    $maxX = max($maxX, $x);
                    $minY = min($minY, $y);
                    $maxY = max($maxY, $y);
                }
            }
        }

        unset($proxy);

        if ($maxX < 0) {
            /*
            | Elke pixel is achtergrond. Was die achtergrond doorzichtig, dan is
            | dit een leeg beeld en valt er niets te halen. Was ze een kleur, dan
            | is dit een effen vlak: mager als logo, maar wel iets, en dus geen
            | reden om de kandidaat weg te gooien.
            */
            return $backgroundAlpha >= self::ALPHA_TRANSPARENT ? null : $source;
        }

        // Terug naar de maten van het origineel, met een proxypixel marge zodat
        // er zeker niets van het beeldmerk afgaat.
        $x0 = max(0, (int) floor(($minX - 1) * $factor));
        $y0 = max(0, (int) floor(($minY - 1) * $factor));
        $x1 = min($width - 1, (int) ceil(($maxX + 1) * $factor));
        $y1 = min($height - 1, (int) ceil(($maxY + 1) * $factor));

        if ($x0 === 0 && $y0 === 0 && $x1 === $width - 1 && $y1 === $height - 1) {
            return $source;
        }

        $cropped = imagecrop($source, [
            'x' => $x0,
            'y' => $y0,
            'width' => $x1 - $x0 + 1,
            'height' => $y1 - $y0 + 1,
        ]);

        return $cropped instanceof GdImage ? $cropped : $source;
    }
}
