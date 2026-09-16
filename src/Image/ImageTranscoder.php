<?php

declare(strict_types=1);

namespace HansDeBoeck\BrandFetcher\Image;

use GdImage;

/**
 * Zet de bytes van een gevonden beeld om naar een vierkante webp.
 *
 * Vierkant maken gebeurt door te passen binnen het vierkant, met de lucht
 * eromheen doorzichtig. Nooit uitrekken en nooit het beeldmerk bijsnijden: een
 * merk hoort te blijven wat het is, en dat is hier geen smaakkwestie.
 */
final class ImageTranscoder
{
    /** Hoeveel punten we per zijde bekijken om de kleur van de rand te bepalen. */
    private const EDGE_SAMPLES = 128;

    /** Tot hier telt een randpixel als dekkend; daarboven is het geen achtergrond. */
    private const EDGE_OPAQUE = 10;

    /** Zoveel van de rand moet dezelfde kleur dragen voordat het er een is. */
    private const EDGE_MAJORITY = 0.8;

    /** Hoeveel een randpixel per kanaal mag afwijken. Een jpeg ruist. */
    private const EDGE_TOLERANCE = 12;

    public function __construct(private readonly Trimmer $trimmer = new Trimmer()) {}

    /** Kan deze installatie uberhaupt webp schrijven? */
    public static function supported(): bool
    {
        return function_exists('imagewebp') && function_exists('imagecreatefromstring');
    }

    /**
     * De werkelijke afmetingen van de bytes, zodat een kandidaat te scoren is.
     *
     * Niet zomaar getimagesizefromstring: voor een .ico geeft die de maat van
     * een willekeurig frame en niet van het beste. De favicon van deboeck.dev
     * heeft frames tot 256 pixels en php meldt er 16 bij 16. Wie daarop scoort,
     * straft elke ico af voor iets wat er niet aan de hand is.
     *
     * @return array{0: int, 1: int, 2: int}|null breedte, hoogte, type
     */
    public static function measure(string $bytes, int $preferred = 128): ?array
    {
        $info = @getimagesizefromstring($bytes);

        if ($info === false) {
            return null;
        }

        [$width, $height, $type] = $info;

        if ($type === IMAGETYPE_ICO) {
            $frames = IcoReader::frames($bytes);

            if ($frames === []) {
                return null;
            }

            $best = null;

            foreach ($frames as $frame) {
                $edge = max($frame['width'], $frame['height']);
                $score = $edge >= $preferred ? 1000 - ($edge - $preferred) : 1000 - ($preferred - $edge) * 4;

                if ($best === null || $score > $best[0]) {
                    $best = [$score, $frame['width'], $frame['height']];
                }
            }

            return [$best[1], $best[2], $type];
        }

        return [$width, $height, $type];
    }

    /** @param array<string, mixed> $config */
    public function transcode(string $bytes, int $size, array $config): ?TranscodeResult
    {
        /*
        | De afmetingen lezen voor we decoderen. Dat is de poort tegen een beeld
        | dat klein op de lijn is en enorm in het geheugen: een truecolor-beeld
        | kost breedte maal hoogte maal vier bytes, dus een og:image van
        | 8000 bij 8000 is 256 MB, en dat is een fatale fout die geen enkele
        | try/catch nog opvangt.
        */
        $info = self::measure($bytes, $size);

        if ($info === null) {
            return null;
        }

        [$width, $height, $type] = $info;

        if ($width < 1 || $height < 1) {
            return null;
        }

        if (max($width, $height) > (int) ($config['max_source_edge'] ?? 6000)) {
            return null;
        }

        if ($width * $height > (int) ($config['max_source_pixels'] ?? 16777216)) {
            return null;
        }

        $source = $this->decode($bytes, $type, $size);

        if (! $source instanceof GdImage) {
            return null;
        }

        // Paletbeelden hebben geen alfakanaal dat je kunt beschrijven, en
        // imagecolorat geeft er een palet-index in plaats van een kleur.
        if (! imageistruecolor($source) && ! imagepalettetotruecolor($source)) {
            return null;
        }

        imagealphablending($source, false);
        imagesavealpha($source, true);

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);

        /*
        | Allebei voor het trimmen, en dat is geen detail. De rand die we straks
        | verlengen is juist wat de trimmer weghaalt: daarna staat daar het
        | beeldmerk zelf. En of de bron doorzichtigheid had, is een eigenschap
        | van de bron; de uitvoer kan die na het opvullen niet meer tonen.
        */
        $fill = $this->padColour($source, $config);
        $sourceHasAlpha = $this->hasAlpha($source);

        $trimmed = $this->trimmer->trim($source, $config);

        if (! $trimmed instanceof GdImage) {
            return null;
        }

        $wasTrimmed = imagesx($trimmed) !== $sourceWidth || imagesy($trimmed) !== $sourceHeight;
        $source = $trimmed;

        $innerWidth = imagesx($source);
        $innerHeight = imagesy($source);

        if ($innerWidth < 8 || $innerHeight < 8) {
            // Een trackingpixel of een lege png: geen logo.
            return null;
        }

        // Passen, en nooit opschalen: een favicon van 16 pixels uitvergroten
        // naar 128 levert een wazige vlek op die niemand wil.
        $scale = min($size / $innerWidth, $size / $innerHeight, 1.0);
        $targetWidth = max(1, (int) round($innerWidth * $scale));
        $targetHeight = max(1, (int) round($innerHeight * $scale));

        $canvas = imagecreatetruecolor($size, $size);

        /*
        | Deze drie regels moeten in deze volgorde. Een vers truecolor-canvas is
        | ondoorzichtig zwart; met blending aan zou de doorzichtige vulling
        | daarmee mengen en krijgt het logo een donkere rand.
        */
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefilledrectangle($canvas, 0, 0, $size - 1, $size - 1, $fill === null
            ? imagecolorallocatealpha($canvas, 0, 0, 0, 127)
            : imagecolorallocate($canvas, $fill[0], $fill[1], $fill[2]));

        if ($fill !== null) {
            /*
            | Nu wel blenden: een logo met eigen doorzichtigheid hoort op deze
            | kleur samengesteld te worden en er geen gaten in te slaan. En het
            | alfakanaal mag uit de uitvoer, want er valt niets meer door te zien.
            */
            imagealphablending($canvas, true);
            imagesavealpha($canvas, false);
        }

        imagecopyresampled(
            $canvas,
            $source,
            intdiv($size - $targetWidth, 2),
            intdiv($size - $targetHeight, 2),
            0,
            0,
            $targetWidth,
            $targetHeight,
            $innerWidth,
            $innerHeight,
        );

        [$encoded, $lossless] = $this->encode($canvas, $config);

        $result = new TranscodeResult(
            bytes: $encoded,
            sourceWidth: $sourceWidth,
            sourceHeight: $sourceHeight,
            sourceRatio: max($sourceWidth, $sourceHeight) / max(1, min($sourceWidth, $sourceHeight)),
            hasAlpha: $sourceHasAlpha,
            trimmed: $wasTrimmed,
            lossless: $lossless,
        );

        unset($source, $canvas);

        return $result;
    }

    private function decode(string $bytes, int $type, int $size): ?GdImage
    {
        // Gd kan geen ico, terwijl /favicon.ico de vaakst voorkomende bron is.
        if ($type === IMAGETYPE_ICO) {
            $frame = IcoReader::best($bytes, $size);

            return $frame['image'] ?? null;
        }

        // Een geanimeerde webp laat libwebp niet toe en gd geeft dan false; dat
        // is geen fout maar een kandidaat die afvalt.
        $image = @imagecreatefromstring($bytes);

        return $image instanceof GdImage ? $image : null;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{0: string, 1: bool}
     */
    private function encode(GdImage $image, array $config): array
    {
        $quality = (int) ($config['quality'] ?? 82);
        $mode = (string) ($config['webp_mode'] ?? 'auto');

        if ($mode === 'lossless') {
            return [$this->toWebp($image, IMG_WEBP_LOSSLESS), true];
        }

        if ($mode === 'lossy') {
            return [$this->toWebp($image, $quality), false];
        }

        /*
        | Beide coderen en de kleinste houden. Gemeten op een vlak logo: 228
        | bytes lossless tegen 878 lossy. Op een foto is het andersom. Bij 128
        | pixels kost dat allebei enkele milliseconden, dus meten is hier
        | goedkoper dan gokken.
        */
        $lossy = $this->toWebp($image, $quality);
        $lossless = $this->toWebp($image, IMG_WEBP_LOSSLESS);

        return strlen($lossless) <= strlen($lossy) ? [$lossless, true] : [$lossy, false];
    }

    /** imagewebp schrijft naar de uitvoer, dus dat moet een buffer in. */
    private function toWebp(GdImage $image, int $quality): string
    {
        ob_start();
        imagewebp($image, null, $quality);

        return (string) ob_get_clean();
    }

    /**
     * Waarmee de lucht rond het logo opgevuld wordt, of null voor doorzichtig.
     *
     * @param  array<string, mixed>  $config
     * @return array{0: int, 1: int, 2: int}|null
     */
    private function padColour(GdImage $source, array $config): ?array
    {
        if ((string) ($config['pad'] ?? 'edge') === 'transparent') {
            return null;
        }

        // Wit als de rand geen hoofdkleur heeft: dat is de neutrale keuze, en
        // een kleur uit het beeldmerk zelf zou het logo laten verdwijnen.
        return $this->edgeColour($source) ?? [255, 255, 255];
    }

    /**
     * De hoofdkleur langs de vier randen van het beeld, of null als er geen is.
     *
     * Een achtergrond is pas een achtergrond als hij de rand ook echt beheerst.
     * Vandaar twee drempels: het grootste deel van de rand moet dekkend zijn en
     * het grootste deel moet dicht bij dezelfde kleur liggen. Een logo dat op
     * transparant staat zakt door de eerste, een foto of een verloop door de
     * tweede, en allebei eindigen ze dus op wit.
     *
     * De mediaan en niet het gemiddelde: een jpeg ruist een paar eenheden per
     * kanaal, en de pixels van een beeldmerk dat de rand raakt mogen de kleur
     * niet meetrekken.
     *
     * @return array{0: int, 1: int, 2: int}|null
     */
    private function edgeColour(GdImage $source): ?array
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $step = max(1, intdiv(max($width, $height), self::EDGE_SAMPLES));

        $samples = 0;
        $pixels = [];

        $take = function (int $x, int $y) use ($source, &$samples, &$pixels): void {
            $samples++;
            $colour = imagecolorat($source, $x, $y);

            if ((($colour >> 24) & 0x7F) <= self::EDGE_OPAQUE) {
                $pixels[] = [($colour >> 16) & 0xFF, ($colour >> 8) & 0xFF, $colour & 0xFF];
            }
        };

        for ($x = 0; $x < $width; $x += $step) {
            $take($x, 0);
            $take($x, $height - 1);
        }

        for ($y = 0; $y < $height; $y += $step) {
            $take(0, $y);
            $take($width - 1, $y);
        }

        if ($samples === 0 || count($pixels) < $samples * self::EDGE_MAJORITY) {
            return null;
        }

        $middle = intdiv(count($pixels), 2);
        $colour = [];

        foreach ([0, 1, 2] as $channel) {
            $values = array_column($pixels, $channel);
            sort($values);
            $colour[$channel] = $values[$middle];
        }

        $near = 0;

        foreach ($pixels as $pixel) {
            if (abs($pixel[0] - $colour[0]) <= self::EDGE_TOLERANCE
                && abs($pixel[1] - $colour[1]) <= self::EDGE_TOLERANCE
                && abs($pixel[2] - $colour[2]) <= self::EDGE_TOLERANCE) {
                $near++;
            }
        }

        return $near >= $samples * self::EDGE_MAJORITY ? $colour : null;
    }

    private function hasAlpha(GdImage $image): bool
    {
        $width = imagesx($image);
        $height = imagesy($image);

        // Steekproef langs een raster: elke pixel bekijken kost tijd zonder dat
        // het antwoord verandert, want een logo met alfa heeft die aan de rand.
        $step = max(1, intdiv(min($width, $height), 16));

        for ($y = 0; $y < $height; $y += $step) {
            for ($x = 0; $x < $width; $x += $step) {
                if (((imagecolorat($image, $x, $y) >> 24) & 0x7F) > 0) {
                    return true;
                }
            }
        }

        return false;
    }
}
