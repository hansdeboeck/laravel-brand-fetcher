<?php

declare(strict_types=1);

namespace HansDeBoeck\BrandFetcher\Discovery;

/**
 * Bepaalt welke kandidaat het logo wordt.
 *
 * In twee fasen, en dat is met opzet. De papieren score gebruikt wat de pagina
 * beweert en bepaalt alleen in welke volgorde we downloaden. De harde score
 * gebruikt de gemeten afmetingen en bepaalt de keuze. Dat onderscheid is nodig
 * omdat een sizes-attribuut een verklaring is en geen meting: sites leveren met
 * grote regelmaat een bestand van zestien pixels met sizes="32x32" erbij.
 */
final class CandidateScorer
{
    /**
     * Manifest en apple-touch-icon wegen het zwaarst omdat die per spec vierkant
     * zijn en groot genoeg. Een og:image weegt licht: dat is een socialekaart,
     * bijna altijd liggend en vaak een foto met tekst erin.
     */
    private const SOURCE_WEIGHT = [
        'manifest' => 100,
        'apple-touch-icon' => 95,
        'jsonld' => 85,
        'link-icon' => 80,
        /*
        | Een bedrijfspagina die de site zelf aanwijst weegt even zwaar als een
        | link rel=icon: het is een verklaring van de eigenaar, en net als die
        | link moet hij het op de meting nog waarmaken.
        */
        'linkedin' => 80,
        'tile-image' => 68,
        /*
        | Facebook levert een veel groter en schoner bestand dan linkedin en
        | wint dus al op de meting. Dit gewicht compenseert dat, zodat beide
        | sociale bronnen in dezelfde band uitkomen: boven een kleine favicon,
        | onder een echt app-icoon, en met een plafond van 188 net onder
        | good_enough_score, zodat ze de zoektocht nooit afbreken voordat de
        | eigen iconen van de site gemeten zijn.
        */
        'facebook' => 58,
        'apple-touch-icon-implied' => 55,
        'favicon.ico' => 45,
        'og:image' => 30,
        'twitter:image' => 25,
        'mask-icon' => 5,
    ];

    /** Wat een kandidaat er bovenop kan krijgen zodra hij gemeten is. */
    public const MAX_MEASURED_BONUS = 85;

    public function paper(IconCandidate $candidate): int
    {
        return (self::SOURCE_WEIGHT[$candidate->source] ?? 40)
            + $this->mimeBonus($candidate)
            + $this->sizeBonus($candidate->declaredSize)
            + $this->declaredRatioPenalty($candidate->declaredRatio)
            + ($candidate->maskable ? -8 : 0);
    }

    public function hard(IconCandidate $candidate): int
    {
        $width = $candidate->measuredWidth ?? 0;
        $height = $candidate->measuredHeight ?? 0;

        if ($width < 1 || $height < 1) {
            return -1000;
        }

        return (self::SOURCE_WEIGHT[$candidate->source] ?? 40)
            + $this->mimeBonus($candidate)
            + $this->sizeBonus(max($width, $height))
            + $this->ratioBonus($width, $height)
            + ($candidate->hasAlpha ? 15 : 0)
            + ($candidate->maskable ? -8 : 0);
    }

    /**
     * Wat een kandidaat kwijtspeelt omdat hij zelf zegt niet vierkant te zijn.
     *
     * Een bewering over de maat is goedkoop, maar een bewering over de vorm is
     * er een in het eigen nadeel, en die is dus wel te geloven: een manifest
     * dat "512x256" opgeeft, zegt eerlijk dat er geen vierkant logo in zit.
     *
     * De aftrek is zo gekozen dat zo'n icoon in de volgorde onder de avatar van
     * een bedrijfspagina zakt, want die is per definitie vierkant. De socials
     * worden dan eerst gedownload. Blijkt het bestand tegen zijn eigen bewering
     * in toch vierkant, dan is er niets verloren: de harde score kijkt alleen
     * naar wat gemeten is.
     */
    private function declaredRatioPenalty(?float $ratio): int
    {
        // Een pixel scheef is nog vierkant genoeg; "512x511" bestaat.
        if ($ratio === null || $ratio <= 1.02) {
            return 0;
        }

        return -50;
    }

    /** Hier zit de voorkeur voor vierkant. */
    private function ratioBonus(int $width, int $height): int
    {
        $ratio = max($width, $height) / max(1, min($width, $height));

        return match (true) {
            $ratio <= 1.02 => 60,
            $ratio <= 1.20 => 35,
            $ratio <= 1.60 => 5,
            $ratio <= 2.50 => -30,
            // Een banner. Bijna zeker de socialekaart en niet het merk.
            default => -70,
        };
    }

    private function mimeBonus(IconCandidate $candidate): int
    {
        if ($candidate->isSvg()) {
            // Gd kan svg niet rasteriseren. Deze straf is zo groot dat een svg
            // rekenkundig nooit kan winnen, maar de url wordt wel bewaard.
            return -1000;
        }

        return match ($candidate->mime) {
            'image/png', 'image/webp' => 10,
            'image/gif' => -5,
            'image/jpeg', 'image/jpg' => -12,
            default => $this->mimeFromExtension($candidate->url),
        };
    }

    private function mimeFromExtension(string $url): int
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        return match (true) {
            str_ends_with($path, '.png'), str_ends_with($path, '.webp') => 10,
            str_ends_with($path, '.gif') => -5,
            str_ends_with($path, '.jpg'), str_ends_with($path, '.jpeg') => -12,
            default => 0,
        };
    }

    /** De zoete plek ligt net boven de uitvoermaat: genoeg om te verkleinen. */
    private function sizeBonus(?int $edge): int
    {
        if ($edge === null || $edge < 1) {
            return 0;
        }

        return match (true) {
            $edge >= 512 => 38,
            $edge >= 256 => 45,
            $edge >= 180 => 40,
            $edge >= 128 => 34,
            $edge >= 64 => 18,
            $edge >= 32 => 4,
            default => -25,
        };
    }
}
