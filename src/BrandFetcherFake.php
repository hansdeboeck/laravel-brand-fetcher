<?php

declare(strict_types=1);

namespace HansDeBoeck\BrandFetcher;

use HansDeBoeck\BrandFetcher\Contracts\FetchesBrands;
use HansDeBoeck\BrandFetcher\Social\SocialProfile;

/**
 * Vervangt de ophaler in tests van de app die dit package gebruikt.
 *
 * Implementeert het contract en erft niet van BrandFetcher: die vraagt een echte
 * opslag en een echte http-laag in zijn constructor, en precies die twee wil je
 * in een test niet hoeven optuigen.
 */
class BrandFetcherFake implements FetchesBrands
{
    /** @var list<array{method: string, domain: string}> */
    public array $calls = [];

    /** @param array<string, array<string, mixed>> $results */
    public function __construct(
        private readonly array $results = [],
        private readonly bool $found = true,
    ) {}

    public function logo(string $domain, int $size = 128, bool $refresh = false): LogoResult
    {
        $this->calls[] = ['method' => 'logo', 'domain' => $domain];

        $row = $this->results[$domain] ?? [];
        $found = (bool) ($row['found'] ?? $this->found);
        $bytes = (string) ($row['bytes'] ?? self::pixel());

        return new LogoResult(
            domain: $domain,
            found: $found,
            status: (string) ($row['status'] ?? ($found ? SiteDetail::OK : SiteDetail::MONOGRAM)),
            size: $size,
            etag: $found ? sha1($bytes) : null,
            bytes: strlen($bytes),
            monogram: (bool) ($row['monogram'] ?? ! $found),
            source: (string) ($row['source'] ?? ($found ? 'apple-touch-icon' : 'monogram')),
            fetchedAt: (int) ($row['fetched_at'] ?? time()),
            ttl: (int) ($row['ttl'] ?? 2592000),
            maxAge: (int) ($row['max_age'] ?? 86400),
            reader: static fn (): string => $bytes,
        );
    }

    public function profile(string $domain, bool $refresh = false): ProfileResult
    {
        $this->calls[] = ['method' => 'profile', 'domain' => $domain];

        $row = $this->results[$domain] ?? [];
        $profiles = [];

        foreach ((array) ($row['profiles'] ?? []) as $platform => $url) {
            $profiles[(string) $platform] = new SocialProfile(
                platform: (string) $platform,
                url: (string) $url,
                handle: basename((string) parse_url((string) $url, PHP_URL_PATH)),
                source: 'jsonld',
            );
        }

        return new ProfileResult(
            domain: $domain,
            found: (bool) ($row['found'] ?? $this->found),
            status: (string) ($row['status'] ?? SiteDetail::OK),
            profiles: $profiles,
            name: $row['name'] ?? null,
            fetchedAt: (int) ($row['fetched_at'] ?? time()),
            ttl: (int) ($row['ttl'] ?? 2592000),
            maxAge: (int) ($row['max_age'] ?? 86400),
        );
    }

    public function refresh(string $domain): ?SiteDetail
    {
        $this->calls[] = ['method' => 'refresh', 'domain' => $domain];

        return new SiteDetail(domain: $domain, status: SiteDetail::OK, fetchedAt: time(), ttl: 2592000);
    }

    public function cached(string $domain): ?LogoResult
    {
        return isset($this->results[$domain]) ? $this->logo($domain) : null;
    }

    /** Een geldige webp van een pixel, zodat een test er echt beeld uit krijgt. */
    public static function pixel(int $size = 128): string
    {
        $image = imagecreatetruecolor($size, $size);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefilledrectangle($image, 0, 0, $size - 1, $size - 1, imagecolorallocatealpha($image, 0, 0, 0, 127));

        ob_start();
        imagewebp($image, null, 82);

        return (string) ob_get_clean();
    }
}
