<?php

declare(strict_types=1);

namespace HansDeBoeck\BrandFetcher\Net;

/**
 * Brengt wat een afnemer stuurt terug tot een kale hostnaam, of weigert het.
 *
 * Dit is een allowlist en geen schoonmaak. Het verschil is wezenlijk: bij
 * schoonmaken vraag je je af welke aanvallen je moet wegpoetsen en vergeet je
 * er altijd een. Hier moet de uitvoer aan HOST_PATTERN voldoen, en alles wat
 * daar niet in past bestaat voor ons niet. Dat sluit in een keer localhost,
 * 127.0.0.1, 0177.0.0.1, 2130706433, een poort, een pad en een nulbyte uit.
 *
 * De uitvoer van normalize() is ook de mapnaam in de opslag. Daarom mag er
 * nooit een slash, een punt aan de rand of twee punten na elkaar in kunnen.
 */
final class DomainNormalizer
{
    /**
     * Alleen a-z, 0-9, koppelteken en punt. Minstens twee labels, elk label
     * hoogstens 63 tekens en niet beginnend of eindigend op een koppelteken,
     * en het laatste label alfabetisch: dat laatste sluit elk ipv4-adres uit.
     */
    public const HOST_PATTERN = '/^(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

    /**
     * Achtervoegsels die per definitie niet op het publieke internet staan.
     * Een van deze zou betekenen dat we iets binnen het netwerk gaan ophalen.
     */
    private const RESERVED_TLDS = [
        'local', 'localhost', 'internal', 'test', 'invalid',
        'example', 'onion', 'home', 'lan', 'intranet', 'corp', 'private',
    ];

    /** Geeft de genormaliseerde hostnaam, of null als het geen domein is. */
    public function normalize(string $input): ?string
    {
        $value = trim($input);

        if ($value === '' || strlen($value) > 400 || str_contains($value, "\0")) {
            return null;
        }

        /*
        | parse_url faalt op een kale hostnaam, dus eerst een schema ervoor.
        | Meteen de reden dat dit werkt tegen "user@evil.tld@intern.lan": wat
        | parse_url als host teruggeeft is wat een browser ook zou bezoeken.
        */
        if (! preg_match('#^[a-z][a-z0-9+.\-]*://#i', $value)) {
            $value = 'https://' . $value;
        }

        $host = parse_url($value, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        $host = rtrim(strtolower($host), '.');

        /*
        | Internationale domeinen naar punycode. De non-transitional variant van
        | UTS46 is wat browsers vandaag doen: de Duitse ringel-s blijft daar de
        | ringel-s in plaats van stil "ss" te worden.
        */
        if (preg_match('/[^\x20-\x7E]/', $host)) {
            $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);

            if ($ascii === false) {
                return null;
            }

            $host = strtolower($ascii);
        }

        /*
        | Enkel de leidende www eraf, nooit een ander subdomein: shop.acme.be is
        | een ander merk dan acme.be en hoort geen opslag te delen.
        */
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        // Expliciet, ook al vangt de regex het al: een ip is nooit een domein.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return null;
        }

        if (! preg_match(self::HOST_PATTERN, $host)) {
            return null;
        }

        $tld = substr($host, (int) strrpos($host, '.') + 1);

        if (in_array($tld, self::RESERVED_TLDS, true)) {
            return null;
        }

        return $host;
    }

    /**
     * De weergavevorm van een punycode-domein, of null als die gelijk is aan
     * de opgeslagen vorm. In de opslag staat altijd punycode: dat is pure
     * ascii en dus veilig als mapnaam en als s3-sleutel, en het vermijdt dat
     * macOS en Linux dezelfde naam verschillend normaliseren.
     */
    public function unicode(string $domain): ?string
    {
        if (! str_contains($domain, 'xn--')) {
            return null;
        }

        $unicode = idn_to_utf8($domain, IDNA_NONTRANSITIONAL_TO_UNICODE, INTL_IDNA_VARIANT_UTS46);

        return ($unicode === false || $unicode === $domain) ? null : $unicode;
    }

    /** Het eerste label, waar het monogram zijn letter uit haalt. */
    public function label(string $domain): string
    {
        return explode('.', $domain)[0];
    }
}
