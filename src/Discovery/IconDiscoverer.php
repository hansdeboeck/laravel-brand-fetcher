<?php

declare(strict_types=1);

namespace HansDeBoeck\BrandFetcher\Discovery;

use DOMNode;
use HansDeBoeck\BrandFetcher\Crawl\SiteCrawl;
use HansDeBoeck\BrandFetcher\Net\Budget;
use HansDeBoeck\BrandFetcher\Net\SafeHttp;
use HansDeBoeck\BrandFetcher\Net\Url;
use HansDeBoeck\BrandFetcher\Social\SocialProfile;

/** Verzamelt alles wat op deze pagina een logo zou kunnen zijn. */
final class IconDiscoverer
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly SafeHttp $http,
        private readonly CandidateScorer $scorer = new CandidateScorer(),
        private readonly array $config = [],
        private readonly ?AvatarDiscoverer $avatars = null,
    ) {}

    /**
     * @param  array<string, SocialProfile>  $profiles  wat SocialDiscoverer vond
     * @return list<IconCandidate> op papieren score, de belofterijkste eerst
     */
    public function discover(SiteCrawl $crawl, Budget $budget, array $profiles = []): array
    {
        if (! $crawl->ok()) {
            return [];
        }

        $candidates = [];

        foreach ($this->fromLinks($crawl) as $candidate) {
            $candidates[] = $candidate;
        }

        foreach ($this->fromMeta($crawl) as $candidate) {
            $candidates[] = $candidate;
        }

        foreach ($this->fromJsonLd($crawl) as $candidate) {
            $candidates[] = $candidate;
        }

        foreach ($this->fromManifest($crawl, $budget) as $candidate) {
            $candidates[] = $candidate;
        }

        foreach ($this->implicit($crawl) as $candidate) {
            $candidates[] = $candidate;
        }

        foreach ($this->avatars?->discover($profiles, $budget) ?? [] as $candidate) {
            $candidates[] = $candidate;
        }

        $candidates = $this->dedupe($candidates);

        usort($candidates, fn (IconCandidate $a, IconCandidate $b): int => $this->scorer->paper($b) <=> $this->scorer->paper($a));

        return $candidates;
    }

    /** @return list<IconCandidate> */
    private function fromLinks(SiteCrawl $crawl): array
    {
        $found = [];

        foreach ($crawl->xpath?->query('//link[@rel][@href]') ?: [] as $node) {
            /** @var DOMNode $node */
            // rel is een lijst van woorden: "shortcut icon" telt ook als icon.
            $rels = preg_split(
                '/\s+/',
                strtolower(trim((string) $node->attributes?->getNamedItem('rel')?->nodeValue)),
                -1,
                PREG_SPLIT_NO_EMPTY,
            ) ?: [];

            $source = match (true) {
                in_array('apple-touch-icon', $rels, true),
                in_array('apple-touch-icon-precomposed', $rels, true) => 'apple-touch-icon',
                in_array('mask-icon', $rels, true), in_array('fluid-icon', $rels, true) => 'mask-icon',
                in_array('icon', $rels, true), in_array('shortcut', $rels, true) => 'link-icon',
                default => null,
            };

            if ($source === null) {
                continue;
            }

            $url = Url::absolutise((string) $node->attributes?->getNamedItem('href')?->nodeValue, (string) $crawl->baseUrl);

            if ($url === null) {
                continue;
            }

            $found[] = new IconCandidate(
                url: $url,
                source: $source,
                declaredSize: $this->largestSize((string) $node->attributes?->getNamedItem('sizes')?->nodeValue),
                mime: $this->mime((string) $node->attributes?->getNamedItem('type')?->nodeValue),
            );
        }

        return $found;
    }

    /** @return list<IconCandidate> */
    private function fromMeta(SiteCrawl $crawl): array
    {
        $map = [
            'og:image' => 'og:image',
            'og:image:url' => 'og:image',
            'og:image:secure_url' => 'og:image',
            'twitter:image' => 'twitter:image',
            'twitter:image:src' => 'twitter:image',
            'msapplication-TileImage' => 'tile-image',
        ];

        $found = [];
        $width = null;

        foreach ($crawl->xpath?->query('//meta[@content]') ?: [] as $node) {
            /** @var DOMNode $node */
            $key = (string) ($node->attributes?->getNamedItem('property')?->nodeValue
                ?? $node->attributes?->getNamedItem('name')?->nodeValue);

            $content = (string) $node->attributes?->getNamedItem('content')?->nodeValue;

            if ($key === 'og:image:width') {
                $width = (int) $content ?: null;

                continue;
            }

            $source = $map[$key] ?? ($map[strtolower($key)] ?? null);

            if ($source === null) {
                continue;
            }

            $url = Url::absolutise($content, (string) $crawl->baseUrl);

            if ($url === null) {
                continue;
            }

            $found[] = new IconCandidate(
                url: $url,
                source: $source,
                declaredSize: $source === 'og:image' ? $width : null,
            );
        }

        return $found;
    }

    /** @return list<IconCandidate> */
    private function fromJsonLd(SiteCrawl $crawl): array
    {
        $found = [];

        foreach ($crawl->entities as $entity) {
            $logo = $entity['logo'] ?? null;

            // logo mag een string zijn of een ImageObject met eigen afmetingen.
            $url = is_string($logo) ? $logo : (is_array($logo) && is_string($logo['url'] ?? null) ? $logo['url'] : null);

            if ($url === null) {
                continue;
            }

            $declared = null;

            if (is_array($logo)) {
                $declared = max((int) ($logo['width'] ?? 0), (int) ($logo['height'] ?? 0)) ?: null;
            }

            $absolute = Url::absolutise($url, (string) $crawl->baseUrl);

            if ($absolute !== null) {
                $found[] = new IconCandidate(url: $absolute, source: 'jsonld', declaredSize: $declared);
            }
        }

        return $found;
    }

    /** @return list<IconCandidate> */
    private function fromManifest(SiteCrawl $crawl, Budget $budget): array
    {
        if ($crawl->manifestUrl === null || ! $budget->allows(1.0)) {
            return [];
        }

        $response = $this->http->get(
            $crawl->manifestUrl,
            $budget,
            (float) ($this->config['asset_timeout'] ?? 3),
            (int) ($this->config['manifest_max_bytes'] ?? 65536),
        );

        if (! $response->ok) {
            return [];
        }

        // Het contenttype negeren we: veel sites serveren een manifest als
        // tekst, en of het json is merken we vanzelf.
        $manifest = json_decode($response->body, true);

        if (! is_array($manifest)) {
            return [];
        }

        $found = [];

        foreach ((array) ($manifest['icons'] ?? []) as $icon) {
            if (! is_array($icon) || ! is_string($icon['src'] ?? null)) {
                continue;
            }

            $purpose = strtolower((string) ($icon['purpose'] ?? 'any'));

            /*
            | Een monochroom icoon is per spec een silhouet dat het toestel zelf
            | inkleurt, dus als logo waardeloos. Maskable wordt aan de randen
            | afgesneden maar is altijd vierkant en groot: houden, met aftrek.
            */
            if (str_contains($purpose, 'monochrome') && ! str_contains($purpose, 'any')) {
                continue;
            }

            // De bron van een manifest-icoon is relatief aan het MANIFEST en
            // niet aan de pagina. Bij /assets/site.webmanifest met src "icon.png"
            // is dat dus /assets/icon.png.
            $url = Url::absolutise($icon['src'], $crawl->manifestUrl);

            if ($url === null) {
                continue;
            }

            $found[] = new IconCandidate(
                url: $url,
                source: 'manifest',
                declaredSize: $this->largestSize((string) ($icon['sizes'] ?? '')),
                mime: $this->mime((string) ($icon['type'] ?? '')),
                maskable: str_contains($purpose, 'maskable'),
            );
        }

        return $found;
    }

    /**
     * De twee paden die zowat elke server kent. Ze scoren laag en komen dus pas
     * aan de beurt als de pagina zelf niets beters aanwees, maar ze zijn het
     * vangnet voor de vele sites die helemaal niets in hun head zetten.
     *
     * @return list<IconCandidate>
     */
    private function implicit(SiteCrawl $crawl): array
    {
        $origin = Url::origin((string) $crawl->finalUrl);

        if ($origin === null) {
            return [];
        }

        return [
            new IconCandidate(url: $origin . '/apple-touch-icon.png', source: 'apple-touch-icon-implied', mime: 'image/png'),
            new IconCandidate(url: $origin . '/favicon.ico', source: 'favicon.ico'),
        ];
    }

    /**
     * Dezelfde url uit twee bronnen: de zwaarste bron wint, en de grootste
     * beweerde maat van de twee reist mee.
     *
     * @param  list<IconCandidate>  $candidates
     * @return list<IconCandidate>
     */
    private function dedupe(array $candidates): array
    {
        /** @var array<string, IconCandidate> $unique */
        $unique = [];

        foreach ($candidates as $candidate) {
            $key = Url::dedupeKey($candidate->url);
            $existing = $unique[$key] ?? null;

            if ($existing === null) {
                $unique[$key] = $candidate;

                continue;
            }

            $winner = $this->scorer->paper($candidate) > $this->scorer->paper($existing) ? $candidate : $existing;

            $unique[$key] = new IconCandidate(
                url: $winner->url,
                source: $winner->source,
                declaredSize: max($candidate->declaredSize ?? 0, $existing->declaredSize ?? 0) ?: null,
                mime: $winner->mime ?? ($candidate->mime ?? $existing->mime),
                maskable: $winner->maskable,
            );
        }

        return array_values($unique);
    }

    /** sizes kan meerdere maten dragen: "32x32 16x16". De grootste telt. */
    private function largestSize(string $sizes): ?int
    {
        $sizes = strtolower(trim($sizes));

        // "any" betekent schaalbaar, en dat is altijd een svg.
        if ($sizes === '' || $sizes === 'any') {
            return null;
        }

        $largest = 0;

        foreach (preg_split('/\s+/', $sizes, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $pair) {
            if (preg_match('/^(\d+)x(\d+)$/', $pair, $match)) {
                $largest = max($largest, (int) $match[1], (int) $match[2]);
            }
        }

        return $largest > 0 ? $largest : null;
    }

    private function mime(string $type): ?string
    {
        $type = strtolower(trim(explode(';', $type)[0]));

        return $type === '' ? null : $type;
    }
}
