<?php

declare(strict_types=1);

namespace HansDeBoeck\BrandFetcher\Crawl;

use DOMXPath;

/**
 * Het gedeelde tussenresultaat van een bezoek aan de voorpagina.
 *
 * Hier hangt de hele opzet aan: de icoonkandidaten en de sociale profielen
 * komen allebei uit dezelfde pagina, dus wie het beeld opvraagt warmt de json
 * op en omgekeerd. Een bezoek aan andermans site bedient twee eindpunten.
 *
 * Dit object gaat nooit de cache of de opslag in: het draagt een DOMXPath en
 * tot een halve megabyte html. Alleen wat eruit afgeleid is wordt bewaard.
 */
final class SiteCrawl
{
    /** @param list<array<string, mixed>> $entities knopen uit de json-ld die iets over het merk zeggen */
    public function __construct(
        public readonly string $domain,
        public readonly string $requestUrl,
        public readonly ?string $finalUrl = null,
        public readonly ?string $baseUrl = null,
        public readonly string $html = '',
        public readonly ?DOMXPath $xpath = null,
        public readonly array $entities = [],
        public readonly ?string $manifestUrl = null,
        public readonly ?string $error = null,
    ) {}

    public function ok(): bool
    {
        return $this->error === null && $this->xpath !== null;
    }

    public static function failed(string $domain, string $requestUrl, string $error): self
    {
        return new self(domain: $domain, requestUrl: $requestUrl, error: $error);
    }
}
