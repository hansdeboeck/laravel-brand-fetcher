<?php

declare(strict_types=1);

namespace HansDeBoeck\BrandFetcher\Net;

use League\Uri\BaseUri;
use Throwable;

/** Losse url-hulp die zowel de omleidingslus als de kandidaatontdekking nodig heeft. */
final class Url
{
    /**
     * Maakt een verwijzing absoluut tegen een basis-url.
     *
     * Schema's die geen netwerkadres zijn worden hier al geweigerd: League\Uri
     * gooit op een data-url, en javascript- of blob-verwijzingen hebben voor
     * ons sowieso geen betekenis. Null betekent: deze kandidaat bestaat niet.
     */
    public static function absolutise(string $reference, string $base): ?string
    {
        $reference = trim($reference);

        if ($reference === '' || preg_match('/^(data|javascript|about|blob|mailto|tel|file):/i', $reference)) {
            return null;
        }

        try {
            $absolute = (string) BaseUri::from($base)->resolve($reference);
        } catch (Throwable) {
            return null;
        }

        $scheme = strtolower((string) parse_url($absolute, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $absolute : null;
    }

    /**
     * Sleutel om kandidaten te ontdubbelen. Schema en host omlaag, fragment
     * eraf, standaardpoort eraf, maar het PAD blijft zoals het is: paden op
     * een cdn zijn hoofdlettergevoelig.
     */
    public static function dedupeKey(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts)) {
            return $url;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = $parts['port'] ?? null;

        if (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
            $port = null;
        }

        return $scheme . '://' . $host . ($port !== null ? ':' . $port : '')
            . ($parts['path'] ?? '')
            . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    /** De wortel van een host, waar de impliciete paden op hangen. */
    public static function origin(string $url): ?string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));

        return $scheme . '://' . strtolower($parts['host'])
            . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}
