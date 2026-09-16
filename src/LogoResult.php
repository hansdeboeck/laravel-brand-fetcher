<?php

declare(strict_types=1);

namespace HansDeBoeck\BrandFetcher;

use ArrayAccess;
use Closure;
use JsonSerializable;
use LogicException;

/**
 * Wat logo() teruggeeft.
 *
 * De bytes komen er lui uit: een afnemer die een 304 krijgt hoeft het bestand
 * nooit te lezen, en op s3 is dat het verschil tussen nul en een heenreis per
 * verzoek. Alles wat de app nodig heeft om de koppen te zetten staat hier, zodat
 * de app zelf nooit in de opslag hoeft te kijken.
 */
final class LogoResult implements ArrayAccess, JsonSerializable
{
    /** @var (Closure(): ?string)|null */
    private $reader;

    /** @var (Closure(): mixed)|null */
    private $streamer;

    /**
     * @param  (Closure(): ?string)|null  $reader
     * @param  (Closure(): mixed)|null  $streamer
     */
    public function __construct(
        public readonly string $domain,
        public readonly ?string $domainUnicode = null,
        public readonly bool $found = false,
        public readonly string $status = SiteDetail::ERROR,
        public readonly int $size = 0,
        public readonly ?string $etag = null,
        public readonly ?int $bytes = null,
        public readonly bool $monogram = false,
        public readonly ?string $source = null,
        public readonly ?string $sourceUrl = null,
        public readonly ?int $sourceWidth = null,
        public readonly ?int $sourceHeight = null,
        public readonly ?float $sourceRatio = null,
        public readonly ?string $svgUrl = null,
        public readonly ?string $error = null,
        public readonly int $fetchedAt = 0,
        public readonly int $ttl = 0,
        public readonly int $maxAge = 0,
        ?Closure $reader = null,
        ?Closure $streamer = null,
    ) {
        $this->reader = $reader;
        $this->streamer = $streamer;
    }

    public static function invalid(string $domain, string $error): self
    {
        return new self(domain: $domain, status: SiteDetail::ERROR, error: $error);
    }

    /** De rauwe webp-bytes, of null als het bestand er niet (meer) is. */
    public function contents(): ?string
    {
        return $this->reader === null ? null : ($this->reader)();
    }

    /** @return resource|null */
    public function stream()
    {
        return $this->streamer === null ? null : ($this->streamer)();
    }

    public function expiresAt(): int
    {
        return $this->fetchedAt + $this->ttl;
    }

    public function age(): int
    {
        return max(0, time() - $this->fetchedAt);
    }

    public function isStale(): bool
    {
        return time() >= $this->expiresAt();
    }

    /**
     * De koppen die bij dit antwoord horen.
     *
     * Bewust geen immutable: deze url is niet inhoudsgeadresseerd. Morgen kan er
     * een ander logo achter zitten, en bij een verzoek tot verwijdering moet het
     * weg kunnen. Met immutable zou een bezoeker het oude beeld blijven zien,
     * ook na een harde herlaadactie.
     *
     * @return array<string, string>
     */
    public function cacheHeaders(): array
    {
        $headers = [
            'Cache-Control' => sprintf('public, max-age=%d', $this->maxAge),
        ];

        if ($this->etag !== null) {
            $headers['ETag'] = '"' . $this->etag . '"';
        }

        if ($this->fetchedAt > 0) {
            $headers['Last-Modified'] = gmdate('D, d M Y H:i:s \G\M\T', $this->fetchedAt);
        }

        return $headers;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'domain' => $this->domain,
            'found' => $this->found,
            'status' => $this->status,
            'size' => $this->size,
            'bytes' => $this->bytes,
            'monogram' => $this->monogram,
            'source' => $this->source,
            'source_url' => $this->sourceUrl,
            'error' => $this->error,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->toArray());
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->toArray()[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('LogoResult is immutable.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('LogoResult is immutable.');
    }
}
