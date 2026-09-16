<?php

declare(strict_types=1);

namespace HansDeBoeck\BrandFetcher\Image;

use GdImage;

/**
 * De terugval als er geen logo te vinden is: de beginletter van het domein,
 * wit op een gekleurde schijf.
 *
 * De kleur volgt uit het domein zelf, zodat dezelfde site altijd dezelfde tint
 * krijgt. Anders zou een verversing het beeld laten verspringen op elke pagina
 * waar het staat, en dat is precies het soort onrust waar een lijst met logo's
 * onrustig van wordt.
 *
 * De ingebouwde letters van gd gaan tot negen bij vijftien pixels en zijn niet
 * te schalen. Daarom een echte font, en daarom een eerlijke mislukking als die
 * ontbreekt: een wazige, uitvergrote bitmapletter die als "het logo" van een
 * klant op een site belandt, is erger dan geen beeld.
 */
final class MonogramRenderer
{
    private const FALLBACK_LETTER = '?';

    /** @param array<string, mixed> $config */
    public function render(string $domain, int $size, array $config): ?string
    {
        $font = $this->fontPath($config);

        if ($font === null) {
            return null;
        }

        $letter = $this->letter($domain);

        $canvas = imagecreatetruecolor($size, $size);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefilledrectangle($canvas, 0, 0, $size - 1, $size - 1, imagecolorallocatealpha($canvas, 0, 0, 0, 127));

        [$red, $green, $blue] = $this->tint($domain);

        imagealphablending($canvas, true);
        imagefilledellipse($canvas, intdiv($size, 2), intdiv($size, 2), $size, $size, imagecolorallocate($canvas, $red, $green, $blue));

        if (! $this->drawLetter($canvas, $letter, $size, $font)) {
            return null;
        }

        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);

        ob_start();
        imagewebp($canvas, null, (int) ($config['quality'] ?? 82));
        $bytes = (string) ob_get_clean();

        unset($canvas);

        return $bytes === '' ? null : $bytes;
    }

    /** De eerste letter of cijfer van het eerste label. */
    public function letter(string $domain): string
    {
        $label = explode('.', $domain)[0];

        // Punycode is geen naam maar een codering; daar valt geen letter uit te
        // halen die iemand herkent.
        if (str_starts_with($label, 'xn--')) {
            $unicode = idn_to_utf8($domain, IDNA_NONTRANSITIONAL_TO_UNICODE, INTL_IDNA_VARIANT_UTS46);

            if (is_string($unicode)) {
                $label = explode('.', $unicode)[0];
            }
        }

        $clean = preg_replace('/[^\p{L}\p{N}]/u', '', $label);

        if (! is_string($clean) || $clean === '') {
            return self::FALLBACK_LETTER;
        }

        return mb_strtoupper(mb_substr($clean, 0, 1), 'UTF-8');
    }

    /**
     * Een tint uit het domein. Vaste verzadiging en helderheid, alleen de
     * kleurhoek wisselt: zo blijft wit erop altijd leesbaar en vallen twee
     * monogrammen naast elkaar niet uit de toon.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    public function tint(string $domain): array
    {
        return $this->hsvToRgb(crc32($domain) % 360, 0.55, 0.62);
    }

    /** @param array<string, mixed> $config */
    private function fontPath(array $config): ?string
    {
        $configured = $config['font_path'] ?? null;

        $path = is_string($configured) && $configured !== ''
            ? $configured
            : __DIR__ . '/../../resources/fonts/monogram.ttf';

        if (! is_file($path) || ! is_readable($path) || ! function_exists('imagettftext')) {
            return null;
        }

        return $path;
    }

    private function drawLetter(GdImage $canvas, string $letter, int $size, string $font): bool
    {
        $fontSize = $size * 0.46;

        $box = @imagettfbbox($fontSize, 0, $font, $letter);

        // Een teken dat niet in de subset zit, heeft geen vorm. Dan liever het
        // vraagteken dan een leeg vlak.
        if ($box === false || ($box[2] - $box[0]) === 0) {
            $letter = self::FALLBACK_LETTER;
            $box = @imagettfbbox($fontSize, 0, $font, $letter);
        }

        if ($box === false) {
            return false;
        }

        /*
        | imagettftext zet de tekst op de BASISLIJN en niet linksboven. In de
        | kadergegevens is box[7] de stijghoogte (negatief) en box[1] de
        | daalhoogte, dus het midden volgt uit die twee en niet uit de hoogte
        | van het canvas.
        */
        $textWidth = $box[2] - $box[0];
        $textHeight = $box[1] - $box[7];

        $x = intdiv($size - $textWidth, 2) - $box[0];
        $y = intdiv($size - $textHeight, 2) + $textHeight - $box[1];

        $white = imagecolorallocate($canvas, 255, 255, 255);

        return @imagettftext($canvas, $fontSize, 0, $x, $y, $white, $font, $letter) !== false;
    }

    /** @return array{0: int, 1: int, 2: int} */
    private function hsvToRgb(float $hue, float $saturation, float $value): array
    {
        $sector = $hue / 60;
        $chroma = $value * $saturation;
        $second = $chroma * (1 - abs(fmod($sector, 2) - 1));
        $match = $value - $chroma;

        [$r, $g, $b] = match ((int) floor($sector) % 6) {
            0 => [$chroma, $second, 0.0],
            1 => [$second, $chroma, 0.0],
            2 => [0.0, $chroma, $second],
            3 => [0.0, $second, $chroma],
            4 => [$second, 0.0, $chroma],
            default => [$chroma, 0.0, $second],
        };

        return [
            (int) round(($r + $match) * 255),
            (int) round(($g + $match) * 255),
            (int) round(($b + $match) * 255),
        ];
    }
}
