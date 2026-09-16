<?php

declare(strict_types=1);

namespace HansDeBoeck\BrandFetcher\Social;

/**
 * Brengt een gevonden link terug tot een profiel-url, of weigert hem.
 *
 * Drie filters, onafhankelijk van elkaar, en een treffer bij een ervan is
 * genoeg om de link te laten vallen: het pad staat op de deellijst van dat
 * platform, de query draagt een deel-lading, of de handle heeft niet de vorm
 * van een handle.
 */
final class UrlCanonicaliser
{
    /**
     * Parameters die een link tot een deelknop maken. Een profiel-url draagt
     * die nooit, en dit vangt ook de varianten die morgen bedacht worden.
     */
    private const SHARE_PARAMS = [
        'u', 'url', 'text', 'title', 'summary', 'mini', 'via', 'description',
        'media', 'body', 'quote', 'source', 'link', 'caption', 'shareurl', 'mini_url',
    ];

    /** Deze mogen weg: het zijn merktekens van de verwijzer, geen deel-lading. */
    private const TRACKING_PARAMS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'fbclid', 'gclid', 'igshid', 'ref', 'ref_src', 'ref_url', 'hl', 'lang',
        'locale', '_ga', 'mibextid', 'si', 'trk', 'originalsubdomain',
    ];

    /** Verkorters en omleiders: waar die heen gaan weten we pas na een verzoek. */
    private const REDIRECTORS = [
        't.co', 'bit.ly', 'lnkd.in', 'youtu.be', 'href.li', 'l.facebook.com',
        'l.instagram.com', 'out.reddit.com', 'tinyurl.com', 'ow.ly', 'buff.ly',
    ];

    /** Voorvoegsels die een host niet van betekenis veranderen. */
    private const STRIPPABLE = '/^(www|m|mobile|web|business|l|[a-z]{2}|[a-z]{2}-[a-z]{2})$/';

    /**
     * @return array{platform: Platform, url: string, handle: string}|null
     */
    public function canonicalise(string $absoluteUrl, ?Platform $only = null): ?array
    {
        $parts = parse_url($absoluteUrl);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return null;
        }

        $host = strtolower(rtrim((string) $parts['host'], '.'));

        if (in_array($host, self::REDIRECTORS, true)) {
            return null;
        }

        $host = $this->stripPrefixes($host);
        $platform = $only ?? $this->platformFor($host);

        if ($platform === null) {
            return null;
        }

        $path = rtrim((string) ($parts['path'] ?? ''), '/');

        // Filter 1: het pad staat op de deellijst van dit platform.
        $lowerPath = strtolower($path) . '/';

        foreach ($platform->sharePaths() as $share) {
            if (str_starts_with($lowerPath, rtrim($share, '/') . '/')) {
                return null;
            }
        }

        // Filter 2: er zit een deel-lading in de query.
        parse_str((string) ($parts['query'] ?? ''), $query);

        foreach (array_keys($query) as $key) {
            if (in_array(strtolower((string) $key), self::SHARE_PARAMS, true)) {
                return null;
            }
        }

        // Filter 3: het pad moet de vorm van een profiel hebben.
        $handle = $this->handle($platform, $path, $query);

        if ($handle === null) {
            return null;
        }

        return [
            'platform' => $platform,
            'url' => $this->rebuild($platform, $host, $path, $query),
            'handle' => $handle,
        ];
    }

    /**
     * De handle. Bijna altijd uit het pad, met een uitzondering: bij facebook
     * staat de naam soms niet in het pad maar in profile.php?id=123, en dan is
     * "profile.php" niet de handle maar ruis.
     *
     * @param  array<string, mixed>  $query
     */
    private function handle(Platform $platform, string $path, array $query): ?string
    {
        if ($platform === Platform::Facebook && str_ends_with(strtolower($path), '/profile.php')) {
            $id = $query['id'] ?? null;

            return is_string($id) && ctype_digit($id) ? $id : null;
        }

        return $platform->handleFrom($path);
    }

    public function platformFor(string $host): ?Platform
    {
        foreach (Platform::withHosts() as $platform) {
            if (in_array($host, $platform->hosts(), true)) {
                return $platform;
            }
        }

        return null;
    }

    /**
     * Haalt landsubdomeinen en mobiele varianten weg, hoogstens twee lagen, en
     * alleen zolang wat overblijft nog geen bekende host is: open.spotify.com
     * en chat.whatsapp.com moeten zichzelf blijven.
     */
    public function stripPrefixes(string $host): string
    {
        for ($i = 0; $i < 2; $i++) {
            if ($this->platformFor($host) !== null) {
                return $host;
            }

            $parts = explode('.', $host);

            if (count($parts) < 3 || ! preg_match(self::STRIPPABLE, $parts[0])) {
                return $host;
            }

            $host = implode('.', array_slice($parts, 1));
        }

        return $host;
    }

    /** @param array<string, mixed> $query */
    private function rebuild(Platform $platform, string $host, string $path, array $query): string
    {
        $host = $platform->canonicalHost() ?? $host;

        /*
        | De query gaat er helemaal af, op een uitzondering na:
        | facebook.com/profile.php?id=123 is zonder dat nummer betekenisloos.
        | Trackingparameters zijn geen reden tot weigeren, alleen tot opruimen.
        */
        $suffix = '';

        if ($platform === Platform::Facebook && str_ends_with(strtolower($path), '/profile.php')) {
            $id = $query['id'] ?? null;

            if (is_string($id) && ctype_digit($id)) {
                $suffix = '?id=' . $id;
            }
        }

        // Het pad blijft zoals het stond: op sommige platformen is een handle
        // hoofdlettergevoelig, en /AcmeBE hoort er anders uit te zien dan /acmebe.
        return 'https://' . $host . ($path === '' ? '/' : $path) . $suffix;
    }

    /** Sleutel om binnen een platform te ontdubbelen, hoofdletterongevoelig. */
    public function dedupeKey(string $url): string
    {
        return strtolower($url);
    }
}
