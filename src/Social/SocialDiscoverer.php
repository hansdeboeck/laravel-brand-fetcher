<?php

declare(strict_types=1);

namespace HansDeBoeck\BrandFetcher\Social;

use DOMElement;
use DOMNode;
use HansDeBoeck\BrandFetcher\Crawl\SiteCrawl;
use HansDeBoeck\BrandFetcher\Net\Url;

/**
 * Haalt de sociale profielen uit de pagina die we toch al ophaalden.
 *
 * De bronnen verschillen sterk in betrouwbaarheid, en dat bepaalt de voorrang.
 * Wat in de json-ld staat heeft de eigenaar bewust opgeschreven; een link in de
 * pagina is afgeleid en rumoerig. Bij tegenstrijdigheid wint dus de verklaring
 * en niet de vondst.
 */
final class SocialDiscoverer
{
    private const WEIGHT_JSONLD = 100;

    private const WEIGHT_REL_ME = 80;

    private const WEIGHT_ANCHOR = 50;

    /** Een anker in de voettekst of in een blok dat "social" heet, weegt zwaarder. */
    private const WEIGHT_ANCHOR_FOOTER = 60;

    private const WEIGHT_META = 40;

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly UrlCanonicaliser $canonicaliser = new UrlCanonicaliser(),
        private readonly array $config = [],
    ) {}

    /**
     * @return array{profiles: array<string, SocialProfile>, name: string|null, rejected: int}
     */
    public function discover(SiteCrawl $crawl): array
    {
        if (! $crawl->ok() || ($this->config['social'] ?? true) === false) {
            return ['profiles' => [], 'name' => null, 'rejected' => 0];
        }

        /** @var array<string, array{weight: int, profile: SocialProfile}> $best */
        $best = [];
        $rejected = 0;

        foreach ($this->candidates($crawl, $rejected) as [$url, $weight, $source, $only]) {
            $canonical = $this->canonicaliser->canonicalise($url, $only);

            if ($canonical === null) {
                $rejected++;

                continue;
            }

            $platform = $canonical['platform']->value;
            $existing = $best[$platform] ?? null;

            // Per platform een profiel: de zwaarste bron wint, en bij gelijke
            // bron wint wie er het eerst stond.
            if ($existing !== null && $existing['weight'] >= $weight) {
                continue;
            }

            $best[$platform] = [
                'weight' => $weight,
                'profile' => new SocialProfile(
                    platform: $platform,
                    url: $canonical['url'],
                    handle: $canonical['handle'],
                    source: $source,
                ),
            ];
        }

        uasort($best, static fn (array $a, array $b): int => $b['weight'] <=> $a['weight']);

        $profiles = array_map(static fn (array $row): SocialProfile => $row['profile'], $best);

        $max = max(1, (int) ($this->config['max_profiles'] ?? 12));

        return [
            'profiles' => array_slice($profiles, 0, $max, true),
            'name' => $this->name($crawl),
            'rejected' => $rejected,
        ];
    }

    /**
     * Alle mogelijke profiel-urls, met hun gewicht en herkomst.
     *
     * @return list<array{0: string, 1: int, 2: string, 3: Platform|null}>
     */
    private function candidates(SiteCrawl $crawl, int &$rejected): array
    {
        $found = [];
        $ownHost = strtolower((string) parse_url((string) $crawl->finalUrl, PHP_URL_HOST));

        // 1. sameAs uit de json-ld: de beste bron die er is.
        foreach ($crawl->entities as $entity) {
            foreach ((array) ($entity['sameAs'] ?? []) as $same) {
                if (is_string($same) && $url = Url::absolutise($same, (string) $crawl->baseUrl)) {
                    $found[] = [$url, self::WEIGHT_JSONLD, 'jsonld', $this->mastodonIfUnknown($url)];
                }
            }
        }

        // 2. rel="me": ook een bewuste verklaring, meestal maar een platform.
        foreach ($crawl->xpath?->query('//link[@rel][@href] | //a[@rel][@href]') ?: [] as $node) {
            $rels = preg_split('/\s+/', strtolower(trim((string) $node->attributes?->getNamedItem('rel')?->nodeValue)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            if (! in_array('me', $rels, true)) {
                continue;
            }

            $url = Url::absolutise((string) $node->attributes?->getNamedItem('href')?->nodeValue, (string) $crawl->baseUrl);

            if ($url !== null) {
                $found[] = [$url, self::WEIGHT_REL_ME, 'rel-me', $this->mastodonIfUnknown($url)];
            }
        }

        // 3. Gewone links in de pagina.
        foreach ($crawl->xpath?->query('//a[@href]') ?: [] as $node) {
            $url = Url::absolutise((string) $node->attributes?->getNamedItem('href')?->nodeValue, (string) $crawl->baseUrl);

            if ($url === null) {
                continue;
            }

            // Een link naar de eigen site is geen sociaal profiel.
            if (strtolower((string) parse_url($url, PHP_URL_HOST)) === $ownHost) {
                continue;
            }

            $found[] = [$url, $this->inSocialBlock($node) ? self::WEIGHT_ANCHOR_FOOTER : self::WEIGHT_ANCHOR, 'anchor', null];
        }

        // 4. twitter:site levert alleen een handle, en alleen voor x.
        foreach ($crawl->xpath?->query('//meta[@name="twitter:site"][@content]') ?: [] as $node) {
            $handle = ltrim(trim((string) $node->attributes?->getNamedItem('content')?->nodeValue), '@');

            // De echte regel van x: een tot vijftien tekens uit letters, cijfers
            // en liggende streepjes. Alles daarbuiten is geen handle.
            if (preg_match('/^[A-Za-z0-9_]{1,15}$/', $handle)) {
                $found[] = ['https://x.com/' . $handle, self::WEIGHT_META, 'meta', Platform::X];
            }
        }

        return $found;
    }

    /**
     * Mastodon draait op elke host, dus die is niet aan een hostnaam te
     * herkennen. Alleen bij een bewuste verklaring gokken we erop, en alleen
     * als het pad de vorm /@naam heeft.
     */
    private function mastodonIfUnknown(string $url): ?Platform
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '' || $this->canonicaliser->platformFor($this->canonicaliser->stripPrefixes($host)) !== null) {
            return null;
        }

        return str_starts_with((string) parse_url($url, PHP_URL_PATH), '/@') ? Platform::Mastodon : null;
    }

    /** Staat dit anker in een voettekst, een kop of een blok dat "social" heet? */
    private function inSocialBlock(DOMNode $node): bool
    {
        $current = $node->parentNode;

        for ($depth = 0; $depth < 6 && $current instanceof DOMNode; $depth++) {
            if ($current instanceof DOMElement) {
                $tag = strtolower($current->tagName);

                if ($tag === 'footer' || $tag === 'header') {
                    return true;
                }

                $marker = strtolower($current->getAttribute('class') . ' ' . $current->getAttribute('id'));

                foreach (['social', 'socials', 'follow', 'sociale', 'volg'] as $needle) {
                    if (str_contains($marker, $needle)) {
                        return true;
                    }
                }
            }

            $current = $current->parentNode;
        }

        return false;
    }

    /** De naam van de organisatie, als die ergens netjes opgeschreven staat. */
    private function name(SiteCrawl $crawl): ?string
    {
        foreach ($crawl->entities as $entity) {
            $name = $entity['name'] ?? null;

            if (is_string($name) && trim($name) !== '') {
                return mb_substr(trim($name), 0, 200);
            }
        }

        foreach ($crawl->xpath?->query('//meta[@property="og:site_name"][@content]') ?: [] as $node) {
            $name = trim((string) $node->attributes?->getNamedItem('content')?->nodeValue);

            if ($name !== '') {
                return mb_substr($name, 0, 200);
            }
        }

        return null;
    }
}
