<?php

declare(strict_types=1);

namespace HansDeBoeck\BrandFetcher\Crawl;

use DOMNode;
use DOMXPath;

/**
 * Vist de knopen uit de ld+json-blokken die iets over het merk zeggen.
 *
 * Dat is de beste bron die er is: wat hier staat heeft de eigenaar van de site
 * bewust en machineleesbaar opgeschreven, terwijl een link in de voettekst
 * altijd een gok blijft.
 *
 * Niet alleen Organization. Een eenmanszaak of een persoonlijk merk beschrijft
 * zichzelf als Person, en dan staan de sociale profielen daar. Wie alleen naar
 * Organization kijkt, mist precies die sites.
 */
final class JsonLd
{
    /** Verder dan dit graven we niet in een @graph of een genest object. */
    private const MAX_DEPTH = 6;

    /** @return list<array<string, mixed>> */
    public static function entities(DOMXPath $xpath): array
    {
        $found = [];

        foreach ($xpath->query('//script[@type="application/ld+json"]') ?: [] as $node) {
            /** @var DOMNode $node */
            $data = json_decode((string) $node->textContent, true);

            if (! is_array($data)) {
                continue;
            }

            self::collect($data, $found, 0);
        }

        return $found;
    }

    /**
     * @param  array<mixed>  $data
     * @param  list<array<string, mixed>>  $found
     */
    private static function collect(array $data, array &$found, int $depth): void
    {
        if ($depth > self::MAX_DEPTH || count($found) > 20) {
            return;
        }

        // Een lijst van knopen, of een @graph: doorlopen en elk stuk bekijken.
        if (array_is_list($data)) {
            foreach ($data as $item) {
                if (is_array($item)) {
                    self::collect($item, $found, $depth + 1);
                }
            }

            return;
        }

        if (isset($data['@graph']) && is_array($data['@graph'])) {
            self::collect($data['@graph'], $found, $depth + 1);
        }

        if (self::describesBrand($data['@type'] ?? null)) {
            $found[] = $data;
        }

        // Een Organization hangt vaak onder publisher, author of brand.
        foreach (['publisher', 'author', 'brand', 'parentOrganization', 'sourceOrganization'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                self::collect($data[$key], $found, $depth + 1);
            }
        }
    }

    /**
     * Elk subtype van Organization telt mee: LocalBusiness, Store, NGO en de
     * tientallen andere die schema.org eronder hangt. Person en WebSite ook,
     * want die dragen in de praktijk net zo vaak de sameAs.
     */
    private static function describesBrand(mixed $type): bool
    {
        foreach ((array) $type as $value) {
            if (! is_string($value)) {
                continue;
            }

            $value = strtolower($value);

            if (str_contains($value, 'organization') || str_contains($value, 'business')
                || str_contains($value, 'service')
                || in_array($value, ['person', 'store', 'website', 'corporation', 'ngo', 'airline', 'restaurant', 'shop'], true)) {
                return true;
            }
        }

        return false;
    }
}
