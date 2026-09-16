<?php

declare(strict_types=1);

namespace HansDeBoeck\BrandFetcher;

use HansDeBoeck\BrandFetcher\Social\SocialProfile;

/**
 * Alles wat we over een domein weten, en tegelijk het formaat van detail.json.
 *
 * Dit bestand is vier dingen ineen, en dat is met opzet:
 *
 *  1. de cache van de metadata, zodat een verzoek geen http hoeft te doen
 *  2. de verloopklok, waarbij de mtime van het bestand de autoriteit is
 *  3. de bron van het json-eindpunt met de sociale profielen
 *  4. het geheugen van wat er misging, zodat een dood domein niet bij elke
 *     paginaweergave opnieuw bezocht wordt
 *
 * Er gaat een array in en uit en nooit een object: Laravel 13 zet
 * cache.serializable_classes op false, en op een disk is een geserialiseerd
 * object sowieso een belofte die je over jaren moet blijven nakomen.
 */
final class SiteDetail
{
    /**
     * Verhoog dit zodra de velden wijzigen. Een onbekende versie telt als
     * afwezig en wordt gewoon opnieuw opgehaald; dat scheelt migraties.
     */
    private const DETAIL_VERSION = 1;

    /** Het logo is een echt logo van de site. */
    public const OK = 'ok';

    /** Gecrawld, niets bruikbaars gevonden: het bestand is een monogram. */
    public const MONOGRAM = 'monogram';

    /** Nog niet gecrawld. Er staat een monogram klaar; de verversing doet de rest. */
    public const PENDING = 'pending';

    /** De site was niet te bereiken. Opnieuw proberen na ttl_error. */
    public const ERROR = 'error';

    /** De host kwam de controle niet door. Zelden blijvend, maar wel traag herproberen. */
    public const BLOCKED = 'blocked';

    /**
     * @param  list<SocialProfile>  $profiles
     * @param  list<array{stage: string, url: string|null, reason: string}>  $failures
     */
    public function __construct(
        public readonly string $domain,
        public readonly ?string $domainUnicode = null,
        public readonly string $status = self::PENDING,
        public readonly int $fetchedAt = 0,
        public readonly int $ttl = 0,
        public readonly ?string $finalUrl = null,
        public readonly int $attempts = 0,
        public readonly ?string $error = null,
        public readonly ?int $logoBytes = null,
        public readonly ?string $logoSha1 = null,
        public readonly int $logoSize = 0,
        public readonly bool $monogram = false,
        public readonly ?string $source = null,
        public readonly ?string $sourceUrl = null,
        public readonly ?int $sourceWidth = null,
        public readonly ?int $sourceHeight = null,
        public readonly ?float $sourceRatio = null,
        public readonly bool $hasAlpha = false,
        public readonly bool $trimmed = false,
        public readonly ?string $svgUrl = null,
        public readonly array $profiles = [],
        public readonly ?string $name = null,
        public readonly array $failures = [],
    ) {}

    public function expiresAt(): int
    {
        return $this->fetchedAt + $this->ttl;
    }

    public function isStale(?int $now = null): bool
    {
        return ($now ?? time()) >= $this->expiresAt();
    }

    /** Staat er een bruikbaar bestand, van welke soort dan ook? */
    public function hasFile(): bool
    {
        return $this->logoBytes !== null && $this->logoBytes > 0;
    }

    /** Is dit een echt logo, en dus geen terugval? */
    public function hasLogo(): bool
    {
        return $this->status === self::OK && $this->hasFile();
    }

    /** Wacht dit domein nog op een echte ophaling? */
    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    /** Een kopie met een andere status en verlooptijd, voor de verversing. */
    public function withClock(int $fetchedAt, int $ttl): self
    {
        return new self(
            domain: $this->domain,
            domainUnicode: $this->domainUnicode,
            status: $this->status,
            fetchedAt: $fetchedAt,
            ttl: $ttl,
            finalUrl: $this->finalUrl,
            attempts: $this->attempts,
            error: $this->error,
            logoBytes: $this->logoBytes,
            logoSha1: $this->logoSha1,
            logoSize: $this->logoSize,
            monogram: $this->monogram,
            source: $this->source,
            sourceUrl: $this->sourceUrl,
            sourceWidth: $this->sourceWidth,
            sourceHeight: $this->sourceHeight,
            sourceRatio: $this->sourceRatio,
            hasAlpha: $this->hasAlpha,
            trimmed: $this->trimmed,
            svgUrl: $this->svgUrl,
            profiles: $this->profiles,
            name: $this->name,
            failures: $this->failures,
        );
    }

    /** @return array<string, mixed> */
    public function toDetailArray(): array
    {
        return [
            'v' => self::DETAIL_VERSION,
            'domain' => $this->domain,
            'domain_unicode' => $this->domainUnicode,
            'status' => $this->status,
            'fetched_at' => $this->fetchedAt,
            'ttl' => $this->ttl,
            'expires_at' => $this->expiresAt(),
            'final_url' => $this->finalUrl,
            'attempts' => $this->attempts,
            'error' => $this->error,
            'logo' => $this->hasFile() ? [
                'file' => BrandStorePaths::LOGO_FILE,
                'size' => $this->logoSize,
                'bytes' => $this->logoBytes,
                'sha1' => $this->logoSha1,
                'monogram' => $this->monogram,
                'source' => $this->source,
                'source_url' => $this->sourceUrl,
                'source_width' => $this->sourceWidth,
                'source_height' => $this->sourceHeight,
                'source_ratio' => $this->sourceRatio,
                'has_alpha' => $this->hasAlpha,
                'trimmed' => $this->trimmed,
                'svg_url' => $this->svgUrl,
            ] : null,
            'social' => [
                'name' => $this->name,
                'profiles' => array_map(
                    static fn (SocialProfile $p): array => $p->toDetailArray(),
                    $this->profiles,
                ),
            ],
            'failures' => $this->failures,
        ];
    }

    /**
     * Geeft null bij een entry uit een andere versie van dit formaat, zodat een
     * oud bestand als afwezig telt in plaats van half ingelezen te worden.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromDetailArray(array $data, ?int $mtime = null): ?self
    {
        if (($data['v'] ?? null) !== self::DETAIL_VERSION) {
            return null;
        }

        $domain = $data['domain'] ?? null;

        if (! is_string($domain) || $domain === '') {
            return null;
        }

        $logo = is_array($data['logo'] ?? null) ? $data['logo'] : [];
        $social = is_array($data['social'] ?? null) ? $data['social'] : [];

        $profiles = [];

        foreach ((array) ($social['profiles'] ?? []) as $row) {
            if (is_array($row) && $profile = SocialProfile::fromDetailArray($row)) {
                $profiles[] = $profile;
            }
        }

        return new self(
            domain: $domain,
            domainUnicode: is_string($data['domain_unicode'] ?? null) ? $data['domain_unicode'] : null,
            status: is_string($data['status'] ?? null) ? $data['status'] : self::PENDING,
            // De mtime wint van het veld: dat is het enige dat een lokale schijf
            // en s3 allebei zonder extra kosten kunnen vertellen.
            fetchedAt: $mtime ?? (int) ($data['fetched_at'] ?? 0),
            ttl: (int) ($data['ttl'] ?? 0),
            finalUrl: is_string($data['final_url'] ?? null) ? $data['final_url'] : null,
            attempts: (int) ($data['attempts'] ?? 0),
            error: is_string($data['error'] ?? null) ? $data['error'] : null,
            logoBytes: isset($logo['bytes']) ? (int) $logo['bytes'] : null,
            logoSha1: is_string($logo['sha1'] ?? null) ? $logo['sha1'] : null,
            logoSize: (int) ($logo['size'] ?? 0),
            monogram: (bool) ($logo['monogram'] ?? false),
            source: is_string($logo['source'] ?? null) ? $logo['source'] : null,
            sourceUrl: is_string($logo['source_url'] ?? null) ? $logo['source_url'] : null,
            sourceWidth: isset($logo['source_width']) ? (int) $logo['source_width'] : null,
            sourceHeight: isset($logo['source_height']) ? (int) $logo['source_height'] : null,
            sourceRatio: isset($logo['source_ratio']) ? (float) $logo['source_ratio'] : null,
            hasAlpha: (bool) ($logo['has_alpha'] ?? false),
            trimmed: (bool) ($logo['trimmed'] ?? false),
            svgUrl: is_string($logo['svg_url'] ?? null) ? $logo['svg_url'] : null,
            profiles: $profiles,
            name: is_string($social['name'] ?? null) ? $social['name'] : null,
            failures: array_values(array_filter((array) ($data['failures'] ?? []), 'is_array')),
        );
    }
}
