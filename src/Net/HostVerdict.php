<?php

declare(strict_types=1);

namespace HansDeBoeck\BrandFetcher\Net;

/**
 * De uitslag van de hostcontrole voor een url.
 *
 * De opgeloste adressen reizen mee zodat de verbinding daarna gepind kan
 * worden op precies wat hier goedgekeurd is. Zonder dat blijft er een venster
 * open tussen de controle en het verbinden waarin dns iets anders kan zeggen.
 */
final class HostVerdict
{
    private function __construct(
        public readonly bool $allowed,
        public readonly ?string $reason = null,
        public readonly ?string $host = null,
        public readonly ?int $port = null,
        /** @var list<string> */
        public readonly array $addresses = [],
    ) {}

    /** @param list<string> $addresses */
    public static function allow(string $host, int $port, array $addresses): self
    {
        return new self(allowed: true, host: $host, port: $port, addresses: $addresses);
    }

    public static function deny(string $reason, ?string $host = null): self
    {
        return new self(allowed: false, reason: $reason, host: $host);
    }
}
