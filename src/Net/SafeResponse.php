<?php

declare(strict_types=1);

namespace HansDeBoeck\BrandFetcher\Net;

/** Wat er van een bewaakte ophaling terugkomt. */
final class SafeResponse
{
    private function __construct(
        public readonly bool $ok,
        public readonly int $status = 0,
        public readonly string $body = '',
        public readonly ?string $finalUrl = null,
        public readonly ?string $contentType = null,
        public readonly bool $truncated = false,
        public readonly ?string $error = null,
    ) {}

    public static function success(
        int $status,
        string $body,
        string $finalUrl,
        ?string $contentType,
        bool $truncated,
    ): self {
        return new self(
            ok: true,
            status: $status,
            body: $body,
            finalUrl: $finalUrl,
            contentType: $contentType,
            truncated: $truncated,
        );
    }

    public static function failure(string $error, int $status = 0, ?string $finalUrl = null): self
    {
        return new self(ok: false, status: $status, finalUrl: $finalUrl, error: $error);
    }
}
