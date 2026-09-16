<?php

declare(strict_types=1);

namespace HansDeBoeck\BrandFetcher\Image;

use GdImage;

/**
 * Leest een .ico, omdat gd dat niet kan.
 *
 * Dat is geen detail: /favicon.ico is de bron die zowat elke site heeft, en
 * imagecreatefromstring() geeft daar gewoon false op. Zonder deze lezer valt
 * het vangnet onder de hele dienst weg.
 *
 * Een ico is een doosje met frames. Elk frame is ofwel een complete png, ofwel
 * een dib: een bitmap met een aantal eigenaardigheden die hieronder per stuk
 * benoemd staan. Bij twijfel geeft alles hier null terug en valt de kandidaat
 * af: een half gelezen icoon is erger dan geen icoon.
 */
final class IcoReader
{
    /** Meer frames dan dit is geen icoon maar een geprepareerde header. */
    private const MAX_FRAMES = 32;

    /** Het formaat gaat tot 256; daarboven is het bestand stuk. */
    private const MAX_EDGE = 1024;

    /**
     * Het beste frame als gd-beeld, of null.
     *
     * @return array{image: GdImage, width: int, height: int, bpp: int}|null
     */
    public static function best(string $bytes, int $preferred = 128): ?array
    {
        $frames = self::frames($bytes);

        if ($frames === []) {
            return null;
        }

        usort($frames, static fn (array $a, array $b): int => self::rank($b, $preferred) <=> self::rank($a, $preferred));

        // In rangorde proberen: mislukt het beste frame, dan het volgende.
        foreach ($frames as $frame) {
            $payload = substr($bytes, $frame['offset'], $frame['bytes']);

            if (strlen($payload) < 16) {
                continue;
            }

            $image = self::decodeFrame($payload, $frame);

            if ($image instanceof GdImage) {
                return [
                    'image' => $image,
                    'width' => imagesx($image),
                    'height' => imagesy($image),
                    'bpp' => $frame['bpp'],
                ];
            }
        }

        return null;
    }

    /**
     * De frame-index, zonder de pixels te lezen.
     *
     * @return list<array{width: int, height: int, bpp: int, bytes: int, offset: int}>
     */
    public static function frames(string $bytes): array
    {
        if (strlen($bytes) < 22) {
            return [];
        }

        $header = unpack('vreserved/vtype/vcount', substr($bytes, 0, 6));

        if ($header === false || $header['reserved'] !== 0) {
            return [];
        }

        // 1 is een icoon, 2 een muisaanwijzer. Alles daarbuiten is geen ico.
        if (! in_array($header['type'], [1, 2], true)) {
            return [];
        }

        $count = (int) $header['count'];

        if ($count < 1 || $count > self::MAX_FRAMES) {
            return [];
        }

        $total = strlen($bytes);
        $frames = [];

        for ($i = 0; $i < $count; $i++) {
            $entry = substr($bytes, 6 + $i * 16, 16);

            if (strlen($entry) < 16) {
                break;
            }

            $dir = unpack('Cwidth/Cheight/Ccolors/Creserved/vplanes/vbpp/Vbytes/Voffset', $entry);

            if ($dir === false) {
                continue;
            }

            // Een nul in de breedte of hoogte betekent 256: in een byte past
            // 256 niet, en dat is het formaat nooit gaan herzien.
            $width = $dir['width'] === 0 ? 256 : (int) $dir['width'];
            $height = $dir['height'] === 0 ? 256 : (int) $dir['height'];

            $offset = (int) $dir['offset'];
            $size = (int) $dir['bytes'];

            // Een ingang die buiten het bestand wijst is de klassieke stukke
            // favicon. Overslaan, niet de hele lezing opgeven.
            if ($offset < 22 || $size < 16 || $offset + $size > $total) {
                continue;
            }

            if ($width > self::MAX_EDGE || $height > self::MAX_EDGE) {
                continue;
            }

            $frames[] = [
                'width' => $width,
                'height' => $height,
                'bpp' => (int) $dir['bpp'],
                'bytes' => $size,
                'offset' => $offset,
            ];
        }

        return $frames;
    }

    /**
     * Hoe geschikt is dit frame voor de gevraagde maat?
     *
     * Te klein straft vier keer zwaarder dan te groot: van 16 pixels naar 128
     * opschalen is onherstelbaar, van 256 naar 128 terug kost niets.
     */
    private static function rank(array $frame, int $preferred): float
    {
        $edge = max($frame['width'], $frame['height']);

        $score = $edge >= $preferred
            ? 1000 - ($edge - $preferred)
            : 1000 - ($preferred - $edge) * 4;

        return (float) ($score + min($frame['bpp'], 32));
    }

    private static function decodeFrame(string $payload, array $frame): ?GdImage
    {
        /*
        | Begint het frame met de png-signatuur, dan is het een png, wat de
        | ingang in de index ook beweert. Die liegt vaak over de bitdiepte.
        */
        if (str_starts_with($payload, "\x89PNG\r\n\x1a\n")) {
            $image = @imagecreatefromstring($payload);

            if (! $image instanceof GdImage) {
                return null;
            }

            if (! imageistruecolor($image) && ! imagepalettetotruecolor($image)) {
                return null;
            }

            imagealphablending($image, false);
            imagesavealpha($image, true);

            return $image;
        }

        return self::decodeDib($payload);
    }

    private static function decodeDib(string $payload): ?GdImage
    {
        if (strlen($payload) < 40) {
            return null;
        }

        $info = unpack('VheaderSize/Vwidth/lheight/vplanes/vbpp/Vcompression', substr($payload, 0, 20));

        if ($info === false || $info['headerSize'] < 40) {
            return null;
        }

        // Rle en bitfields komen in favicons vrijwel niet voor, en een halve
        // implementatie is erger dan een eerlijke weigering.
        if ($info['compression'] !== 0) {
            return null;
        }

        $bpp = (int) $info['bpp'];

        if (! in_array($bpp, [8, 24, 32], true)) {
            return null;
        }

        $width = (int) $info['width'];

        // De hoogte in de header is DUBBEL: ze telt het beeld en het masker.
        $height = intdiv((int) $info['height'], 2);

        if ($width < 1 || $height < 1 || $width > self::MAX_EDGE || $height > self::MAX_EDGE) {
            return null;
        }

        $paletteCount = $bpp <= 8 ? (1 << $bpp) : 0;

        // Niet hardcoded 40: een v4- of v5-header is 108 of 124 bytes, en dan
        // begint het palet verderop.
        $paletteOffset = (int) $info['headerSize'];
        $palette = [];

        for ($i = 0; $i < $paletteCount; $i++) {
            $quad = substr($payload, $paletteOffset + $i * 4, 4);

            if (strlen($quad) < 4) {
                return null;
            }

            // BGRA en niet RGBA.
            $palette[$i] = [ord($quad[2]), ord($quad[1]), ord($quad[0])];
        }

        $xorOffset = $paletteOffset + $paletteCount * 4;

        // Elke rij is opgevuld tot een veelvoud van vier bytes.
        $rowBytes = intdiv($width * $bpp + 31, 32) * 4;

        if (strlen($payload) < $xorOffset + $rowBytes * $height) {
            return null;
        }

        $andRowBytes = intdiv($width + 31, 32) * 4;
        $andOffset = $xorOffset + $rowBytes * $height;
        $hasMask = strlen($payload) >= $andOffset + $andRowBytes * $height;

        /*
        | De valkuil van 32bpp. Veel oudere iconen laten het alfakanaal helemaal
        | op nul staan en bedoelen dan "overal dekkend", met de doorzichtigheid
        | in het masker. Wie die nul leest als "volledig doorzichtig" krijgt een
        | onzichtbaar logo. Dus eerst kijken of er ergens alfa staat.
        */
        $usesAlpha = false;

        if ($bpp === 32) {
            for ($y = 0; $y < $height && ! $usesAlpha; $y++) {
                $base = $xorOffset + $y * $rowBytes;

                for ($x = 0; $x < $width; $x++) {
                    if (ord($payload[$base + $x * 4 + 3]) !== 0) {
                        $usesAlpha = true;

                        break;
                    }
                }
            }
        }

        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        for ($y = 0; $y < $height; $y++) {
            // Een dib staat van onder naar boven.
            $sourceY = $height - 1 - $y;
            $rowBase = $xorOffset + $sourceY * $rowBytes;
            $maskBase = $andOffset + $sourceY * $andRowBytes;

            for ($x = 0; $x < $width; $x++) {
                if ($bpp === 32) {
                    $offset = $rowBase + $x * 4;
                    $blue = ord($payload[$offset]);
                    $green = ord($payload[$offset + 1]);
                    $red = ord($payload[$offset + 2]);
                    // Gd-alfa loopt van 0 tot 127 en is omgekeerd: 0 is dekkend.
                    $alpha = $usesAlpha ? 127 - intdiv(ord($payload[$offset + 3]) * 127, 255) : 0;
                } elseif ($bpp === 24) {
                    $offset = $rowBase + $x * 3;
                    $blue = ord($payload[$offset]);
                    $green = ord($payload[$offset + 1]);
                    $red = ord($payload[$offset + 2]);
                    $alpha = 0;
                } else {
                    [$red, $green, $blue] = $palette[ord($payload[$rowBase + $x])] ?? [0, 0, 0];
                    $alpha = 0;
                }

                if ($alpha === 0 && $hasMask && ($bpp !== 32 || ! $usesAlpha)) {
                    $bits = ord($payload[$maskBase + ($x >> 3)]);

                    // Een maskerbit van 1 betekent doorzichtig.
                    if (($bits >> (7 - ($x & 7))) & 1) {
                        $alpha = 127;
                    }
                }

                imagesetpixel($image, $x, $y, imagecolorallocatealpha($image, $red, $green, $blue, $alpha));
            }
        }

        return $image;
    }
}
