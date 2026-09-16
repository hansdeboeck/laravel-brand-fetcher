<?php

declare(strict_types=1);

use HansDeBoeck\BrandFetcher\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/*
| Alle beeldfixtures worden hier in php gemaakt en staan dus niet als binair
| bestand in de repo. Dat is niet alleen netter in een diff: het maakt ook
| precies zichtbaar welke eigenschap een test bedoelt te raken.
*/

/** Een png met een vorm erin, eventueel met lucht eromheen. */
function pngFixture(int $width, int $height, int $padding = 0, bool $transparent = true): string
{
    $image = imagecreatetruecolor($width, $height);
    imagealphablending($image, false);
    imagesavealpha($image, true);

    $background = $transparent
        ? imagecolorallocatealpha($image, 0, 0, 0, 127)
        : imagecolorallocate($image, 255, 255, 255);

    imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $background);
    imagealphablending($image, true);
    imagefilledellipse(
        $image,
        intdiv($width, 2),
        intdiv($height, 2),
        max(1, $width - 2 * $padding),
        max(1, $height - 2 * $padding),
        imagecolorallocate($image, 200, 30, 40),
    );

    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

/** Een volledig doorzichtige png: wel een beeld, geen logo. */
function emptyPngFixture(int $size = 64): string
{
    $image = imagecreatetruecolor($size, $size);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefilledrectangle($image, 0, 0, $size - 1, $size - 1, imagecolorallocatealpha($image, 0, 0, 0, 127));

    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

function jpegFixture(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 255, 255, 255));
    imagefilledellipse($image, intdiv($width, 2), intdiv($height, 2), intdiv($width, 2), intdiv($height, 2), imagecolorallocate($image, 30, 80, 200));

    ob_start();
    imagejpeg($image, null, 90);

    return (string) ob_get_clean();
}

/** Een dib-frame voor in een ico, met de eigenaardigheden van dat formaat. */
function dibFrameFixture(int $width, int $height, int $bpp, array $rgb = [200, 30, 40], bool $maskBorder = false): string
{
    $header = pack('VVlvvVVllVV', 40, $width, $height * 2, 1, $bpp, 0, 0, 0, 0, 0, 0);

    $palette = '';

    if ($bpp === 8) {
        $palette .= pack('CCCC', 0, 0, 0, 0);
        $palette .= pack('CCCC', $rgb[2], $rgb[1], $rgb[0], 0);

        for ($i = 2; $i < 256; $i++) {
            $palette .= pack('CCCC', 0, 0, 0, 0);
        }
    }

    $rowBytes = intdiv($width * $bpp + 31, 32) * 4;
    $pixels = '';

    // Een dib staat van onder naar boven.
    for ($y = $height - 1; $y >= 0; $y--) {
        $row = '';

        for ($x = 0; $x < $width; $x++) {
            $row .= match ($bpp) {
                32 => pack('CCCC', $rgb[2], $rgb[1], $rgb[0], 255),
                24 => pack('CCC', $rgb[2], $rgb[1], $rgb[0]),
                default => chr(1),
            };
        }

        $pixels .= str_pad($row, $rowBytes, "\0");
    }

    $maskRowBytes = intdiv($width + 31, 32) * 4;
    $mask = '';

    for ($y = $height - 1; $y >= 0; $y--) {
        $bits = array_fill(0, $width, 0);

        if ($maskBorder) {
            if ($y === 0 || $y === $height - 1) {
                $bits = array_fill(0, $width, 1);
            } else {
                $bits[0] = 1;
                $bits[$width - 1] = 1;
            }
        }

        $row = '';
        $byte = 0;

        for ($x = 0; $x < $width; $x++) {
            $byte |= $bits[$x] << (7 - ($x % 8));

            if ($x % 8 === 7) {
                $row .= chr($byte);
                $byte = 0;
            }
        }

        if ($width % 8 !== 0) {
            $row .= chr($byte);
        }

        $mask .= str_pad($row, $maskRowBytes, "\0");
    }

    return $header . $palette . $pixels . $mask;
}

/**
 * Een ico met de opgegeven frames.
 *
 * @param  list<array{0: int, 1: int, 2: int, 3: string}>  $frames breedte, hoogte, bpp, inhoud
 */
function icoFixture(array $frames): string
{
    $header = pack('vvv', 0, 1, count($frames));
    $offset = 6 + count($frames) * 16;
    $directory = '';
    $payloads = '';

    foreach ($frames as [$width, $height, $bpp, $payload]) {
        $directory .= pack('CCCCvvVV', $width % 256, $height % 256, 0, 0, 1, $bpp, strlen($payload), $offset);
        $offset += strlen($payload);
        $payloads .= $payload;
    }

    return $header . $directory . $payloads;
}

/** Een ico met een png erin, wat moderne sites vaak doen. */
function icoWithPngFixture(int $size = 256): string
{
    return icoFixture([[$size, $size, 32, pngFixture($size, $size)]]);
}

/**
 * Een voorpagina. Alles is optioneel, zodat elke test precies het stukje
 * markup kan neerzetten dat hij bedoelt te raken.
 *
 * @param  array{links?: list<string>, metas?: list<string>, jsonld?: array<mixed>, anchors?: list<string>, footer?: list<string>, head?: string}  $options
 */
function htmlFixture(array $options = []): string
{
    $links = implode("\n", $options['links'] ?? []);
    $metas = implode("\n", $options['metas'] ?? []);
    $anchors = implode("\n", array_map(static fn (string $url): string => '<a href="' . $url . '">volg</a>', $options['anchors'] ?? []));
    $footer = implode("\n", array_map(static fn (string $url): string => '<a href="' . $url . '">volg</a>', $options['footer'] ?? []));

    $jsonld = isset($options['jsonld'])
        ? '<script type="application/ld+json">' . json_encode($options['jsonld']) . '</script>'
        : '';

    $head = $options['head'] ?? '';

    return <<<HTML
    <!doctype html>
    <html lang="nl">
    <head>
    <meta charset="utf-8">
    <title>Voorbeeld</title>
    {$links}
    {$metas}
    {$jsonld}
    {$head}
    </head>
    <body>
    <main>{$anchors}</main>
    <footer class="voettekst">{$footer}</footer>
    </body>
    </html>
    HTML;
}

/** Een liggend beeld met een effen achtergrond en een licht merk erin. */
function bannerFixture(int $width, int $height, array $rgb): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]));
    imagefilledellipse(
        $image,
        intdiv($width, 2),
        intdiv($height, 2),
        intdiv($width, 2),
        intdiv($height, 2),
        imagecolorallocate($image, 255, 255, 255),
    );

    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

/** Een webmanifest met iconen erin. */
function manifestFixture(array $icons): string
{
    return (string) json_encode(['name' => 'Voorbeeld', 'icons' => $icons]);
}
