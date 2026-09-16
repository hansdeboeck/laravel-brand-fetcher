<?php

declare(strict_types=1);

namespace HansDeBoeck\BrandFetcher;

use ArrayAccess;
use HansDeBoeck\BrandFetcher\Social\SocialProfile;
use JsonSerializable;
use LogicException;

/**
 * Wat profile() teruggeeft: de sociale profielen van een domein.
 *
 * Deze komen uit dezelfde voorpagina die ook het logo oplevert, dus een verzoek
 * om het beeld warmt dit op en omgekeerd. Een lege lijst is een geldig antwoord:
 * dat betekent dat we gekeken hebben en er niets stond.
 */
final class ProfileResult implements ArrayAccess, JsonSerializable
{
    /** @param array<string, SocialProfile> $profiles gesleuteld op platform */
    public function __construct(
        public readonly string $domain,
        public readonly ?string $domainUnicode = null,
        public readonly bool $found = false,
        public readonly string $status = SiteDetail::ERROR,
        public readonly array $profiles = [],
        public readonly ?string $name = null,
        public readonly ?string $error = null,
        public readonly int $fetchedAt = 0,
        public readonly int $ttl = 0,
        public readonly int $maxAge = 0,
    ) {}

    public static function invalid(string $domain, string $error): self
    {
        return new self(domain: $domain, status: SiteDetail::ERROR, error: $error);
    }

    public function for(string $platform): ?SocialProfile
    {
        return $this->profiles[$platform] ?? null;
    }

    public function has(string $platform): bool
    {
        return isset($this->profiles[$platform]);
    }

    /** @return array<string, string> platform naar url */
    public function urls(): array
    {
        return array_map(static fn (SocialProfile $p): string => $p->url, $this->profiles);
    }

    public function expiresAt(): int
    {
        return $this->fetchedAt + $this->ttl;
    }

    public function isStale(): bool
    {
        return time() >= $this->expiresAt();
    }

    /** @return array<string, string> */
    public function cacheHeaders(): array
    {
        $headers = [
            'Cache-Control' => sprintf('public, max-age=%d', $this->maxAge),
        ];

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
            'name' => $this->name,
            'profiles' => array_values(array_map(
                static fn (SocialProfile $p): array => $p->toArray(),
                $this->profiles,
            )),
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
        throw new LogicException('ProfileResult is immutable.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('ProfileResult is immutable.');
    }
}
