<?php

declare(strict_types=1);

namespace HansDeBoeck\BrandFetcher\Social;

use ArrayAccess;
use JsonSerializable;
use LogicException;

/** Een sociaal profiel van een domein, zoals het op de voorpagina stond. */
final class SocialProfile implements ArrayAccess, JsonSerializable
{
    public function __construct(
        public readonly string $platform,
        public readonly string $url,
        public readonly ?string $handle = null,
        /** jsonld, rel-me, anchor of meta: waar we het vandaan hebben. */
        public readonly string $source = 'anchor',
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'platform' => $this->platform,
            'url' => $this->url,
            'handle' => $this->handle,
        ];
    }

    /** @return array<string, mixed> */
    public function toDetailArray(): array
    {
        return $this->toArray() + ['source' => $this->source];
    }

    /** @param array<string, mixed> $data */
    public static function fromDetailArray(array $data): ?self
    {
        $platform = $data['platform'] ?? null;
        $url = $data['url'] ?? null;

        if (! is_string($platform) || ! is_string($url) || $platform === '' || $url === '') {
            return null;
        }

        return new self(
            platform: $platform,
            url: $url,
            handle: is_string($data['handle'] ?? null) ? $data['handle'] : null,
            source: is_string($data['source'] ?? null) ? $data['source'] : 'anchor',
        );
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
        throw new LogicException('SocialProfile is immutable.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('SocialProfile is immutable.');
    }
}
