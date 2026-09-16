<?php

declare(strict_types=1);

namespace HansDeBoeck\BrandFetcher\Crawl;

use DOMDocument;
use DOMXPath;
use HansDeBoeck\BrandFetcher\Net\Budget;
use HansDeBoeck\BrandFetcher\Net\SafeHttp;
use HansDeBoeck\BrandFetcher\Net\Url;

/** Haalt de voorpagina van een domein op en maakt er een SiteCrawl van. */
final class SiteCrawler
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly SafeHttp $http,
        private readonly array $config = [],
    ) {}

    public function crawl(string $domain, Budget $budget): SiteCrawl
    {
        $scheme = ($this->config['allow_http'] ?? false) ? 'http' : 'https';
        $requestUrl = $scheme . '://' . $domain . '/';

        $response = $this->fetch($requestUrl, $budget);

        /*
        | Een apex zonder eigen record komt voor: sommige domeinen bestaan
        | alleen met www ervoor. Dat is geen exotisch geval maar dagelijkse kost,
        | dus een tweede poging is het waard. Wel alleen als het eerste verzoek
        | struikelde over de naam, niet als de site gewoon een fout gaf.
        */
        if (! $response->ok && in_array($response->error, ['dns_failed', 'unreachable'], true)) {
            $wwwUrl = $scheme . '://www.' . $domain . '/';
            $retry = $this->fetch($wwwUrl, $budget);

            if ($retry->ok) {
                $response = $retry;
                $requestUrl = $wwwUrl;
            }
        }

        if (! $response->ok) {
            return SiteCrawl::failed($domain, $requestUrl, (string) $response->error);
        }

        $finalUrl = $response->finalUrl ?? $requestUrl;
        $xpath = $this->parse($response->body);

        if ($xpath === null) {
            return SiteCrawl::failed($domain, $requestUrl, 'unparsable_html');
        }

        // Een base href geldt voor alles wat relatief is, dus ook voor het
        // manifest. Eerst de basis bepalen en die daarna overal gebruiken.
        $baseUrl = $this->baseUrl($xpath, $finalUrl);

        return new SiteCrawl(
            domain: $domain,
            requestUrl: $requestUrl,
            finalUrl: $finalUrl,
            baseUrl: $baseUrl,
            html: $response->body,
            xpath: $xpath,
            entities: JsonLd::entities($xpath),
            manifestUrl: $this->manifestUrl($xpath, $baseUrl),
        );
    }

    private function fetch(string $url, Budget $budget)
    {
        /*
        | Geen vroegstop op </head>: de sociale links staan bijna altijd in de
        | voettekst. Daarom lezen tot de bytegrens of tot </body>, wat eerder
        | komt. Wie alleen het logo zoekt betaalt dus iets meer, en krijgt de
        | profielen er gratis bij.
        */
        $stopAt = ($this->config['social'] ?? true) ? '</body>' : '</head>';

        return $this->http->get(
            $url,
            $budget,
            (float) ($this->config['html_timeout'] ?? 3),
            (int) ($this->config['html_max_bytes'] ?? 524288),
            $stopAt,
            // Van een voorpagina is het eerste stuk genoeg: daar staat de head
            // in, en de voettekst halen we mee zolang de grens het toelaat.
            allowPartial: true,
        );
    }

    private function parse(string $html): ?DOMXPath
    {
        if (trim($html) === '') {
            return null;
        }

        $document = new DOMDocument();

        $previous = libxml_use_internal_errors(true);

        /*
        | LIBXML_NONET is hier geen extraatje. Zonder die vlag mag libxml zelf
        | een externe dtd ophalen waar de pagina naar wijst, en dan doet onze
        | parser buiten ons om een verzoek naar een adres dat iemand anders
        | koos. Dat is precies het gat dat de rest van deze code dichthoudt.
        */
        $loaded = $document->loadHTML(
            $this->withCharset($html),
            LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET | LIBXML_COMPACT,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $loaded ? new DOMXPath($document) : null;
    }

    /**
     * Afgekapte html parseert prima, maar zonder codering leest DOMDocument
     * alles als latin-1 en verandert een bedrijfsnaam met een accent in
     * onzin. Een charset vooraan zetten is goedkoper dan achteraf repareren.
     */
    private function withCharset(string $html): string
    {
        if (preg_match('/<meta[^>]+charset/i', substr($html, 0, 2048))) {
            return $html;
        }

        return '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $html;
    }

    private function baseUrl(DOMXPath $xpath, string $finalUrl): string
    {
        $nodes = $xpath->query('//base[@href]');

        if ($nodes !== false && $nodes->length > 0) {
            $href = trim((string) $nodes->item(0)?->attributes?->getNamedItem('href')?->nodeValue);

            if ($href !== '' && $absolute = Url::absolutise($href, $finalUrl)) {
                return $absolute;
            }
        }

        return $finalUrl;
    }

    private function manifestUrl(DOMXPath $xpath, string $baseUrl): ?string
    {
        foreach ($xpath->query('//link[@rel][@href]') ?: [] as $node) {
            $rel = strtolower(trim((string) $node->attributes?->getNamedItem('rel')?->nodeValue));

            if (! in_array('manifest', preg_split('/\s+/', $rel, -1, PREG_SPLIT_NO_EMPTY) ?: [], true)) {
                continue;
            }

            $href = (string) $node->attributes?->getNamedItem('href')?->nodeValue;

            if ($absolute = Url::absolutise($href, $baseUrl)) {
                return $absolute;
            }
        }

        return null;
    }
}
