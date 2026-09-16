<?php

declare(strict_types=1);

namespace HansDeBoeck\BrandFetcher;

use HansDeBoeck\BrandFetcher\Contracts\FetchesBrands;
use HansDeBoeck\BrandFetcher\Crawl\SiteCrawl;
use HansDeBoeck\BrandFetcher\Crawl\SiteCrawler;
use HansDeBoeck\BrandFetcher\Discovery\CandidateScorer;
use HansDeBoeck\BrandFetcher\Discovery\IconCandidate;
use HansDeBoeck\BrandFetcher\Discovery\IconDiscoverer;
use HansDeBoeck\BrandFetcher\Image\ImageTranscoder;
use HansDeBoeck\BrandFetcher\Image\MonogramRenderer;
use HansDeBoeck\BrandFetcher\Net\Budget;
use HansDeBoeck\BrandFetcher\Net\DomainNormalizer;
use HansDeBoeck\BrandFetcher\Net\SafeHttp;
use HansDeBoeck\BrandFetcher\Social\SocialDiscoverer;
use HansDeBoeck\BrandFetcher\Social\SocialProfile;
use HansDeBoeck\BrandFetcher\Storage\BrandStore;

/**
 * De gevel van dit package.
 *
 * Twee methodes die allebei op dezelfde voorpagina steunen: logo() levert een
 * vierkante webp, profile() de sociale profielen. Wie ze na elkaar aanroept
 * binnen hetzelfde verzoek, bezoekt de site een keer.
 *
 * Wat er gebeurt bij een misser hangt af van de instelling on_miss, en dat
 * verschilt bewust per methode. Een beeld hangt in een img-tag op een site die
 * niet van ons is en mag daarom nooit traag zijn: bij een onbekend domein komt
 * er meteen een monogram en doet de verversopdracht het echte werk. De json
 * wordt bewust opgevraagd door iemand die op een antwoord zit te wachten, dus
 * daar halen we de pagina wel ter plaatse op: dat is een verzoek, geen reeks.
 */
class BrandFetcher implements FetchesBrands
{
    /** Zoveel voorpagina's houden we hoogstens in het geheugen vast. */
    private const CRAWL_MEMO_LIMIT = 32;

    /** @var array<string, SiteCrawl> */
    private array $crawls = [];

    private readonly SiteCrawler $crawler;

    private readonly IconDiscoverer $discoverer;

    private readonly SocialDiscoverer $social;

    private readonly CandidateScorer $scorer;

    private readonly ImageTranscoder $transcoder;

    private readonly MonogramRenderer $monogram;

    private readonly DomainNormalizer $normalizer;

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly BrandStore $store,
        private readonly SafeHttp $http,
        private readonly array $config = [],
    ) {
        $this->scorer = new CandidateScorer();
        $this->crawler = new SiteCrawler($http, $config);
        $this->discoverer = new IconDiscoverer($http, $this->scorer, $config);
        $this->social = new SocialDiscoverer(config: $config);
        $this->transcoder = new ImageTranscoder();
        $this->monogram = new MonogramRenderer();
        $this->normalizer = new DomainNormalizer();
    }

    public function logo(string $domain, int $size = 128, bool $refresh = false): LogoResult
    {
        $normalized = $this->normalizer->normalize($domain);

        if ($normalized === null) {
            return LogoResult::invalid($domain, 'invalid_domain');
        }

        if ($size !== $this->size()) {
            // Er is een bestand per domein, dus een andere maat vraagt eerst een
            // andere padvorm. Liever een eerlijke fout dan een logo.webp waar
            // stiekem iets anders in zit dan de naam belooft.
            return LogoResult::invalid($normalized, 'unsupported_size');
        }

        if (! ImageTranscoder::supported()) {
            return LogoResult::invalid($normalized, 'webp_unavailable');
        }

        $detail = $refresh ? null : $this->store->detail($normalized);

        /*
        | Staat er een bestand, dan gaat dat eruit, ook als het verlopen is.
        | Verouderd is voor een logo goed genoeg, en het alternatief zou zijn
        | dat een willekeurige bezoeker de rekening van een crawl betaalt.
        */
        if ($detail !== null && $detail->hasFile()) {
            return $this->toLogoResult($detail);
        }

        if ($refresh || ($this->config['on_miss'] ?? 'monogram') === 'fetch') {
            return $this->toLogoResult($this->fetch($normalized, $detail));
        }

        return $this->toLogoResult($this->markPending($normalized, $detail));
    }

    public function profile(string $domain, bool $refresh = false): ProfileResult
    {
        $normalized = $this->normalizer->normalize($domain);

        if ($normalized === null) {
            return ProfileResult::invalid($domain, 'invalid_domain');
        }

        $detail = $refresh ? null : $this->store->detail($normalized);

        // Is er al eens gekeken, dan is dat het antwoord, ook als het oud is.
        if ($detail !== null && $detail->status !== SiteDetail::PENDING) {
            return $this->toProfileResult($detail);
        }

        $budget = new Budget((int) ($this->config['budget_ms'] ?? 4000));
        $crawl = $this->crawl($normalized, $budget);

        if (! $crawl->ok()) {
            $failed = $this->failedDetail($normalized, $crawl, $detail);
            $this->store->write($failed, null);

            return $this->toProfileResult($failed);
        }

        $social = $this->social->discover($crawl);

        // De logo-velden van een eerdere ronde blijven staan: dit verzoek ging
        // over de profielen en heeft over het beeld niets nieuws te melden.
        $merged = $this->mergeSocial($normalized, $crawl, $detail, $social);

        $this->store->write($merged, null);

        return $this->toProfileResult($merged);
    }

    /** Alles opnieuw ophalen. Dit is wat de verversopdracht aanroept. */
    public function refresh(string $domain): ?SiteDetail
    {
        $normalized = $this->normalizer->normalize($domain);

        if ($normalized === null) {
            return null;
        }

        unset($this->crawls[$normalized]);

        return $this->fetch($normalized, $this->store->detail($normalized));
    }

    /** Leest alleen de opslag en doet nooit een verzoek naar buiten. */
    public function cached(string $domain): ?LogoResult
    {
        $normalized = $this->normalizer->normalize($domain);

        if ($normalized === null) {
            return null;
        }

        $detail = $this->store->detail($normalized);

        return $detail === null ? null : $this->toLogoResult($detail);
    }

    public function store(): BrandStore
    {
        return $this->store;
    }

    /**
     * De voorpagina, hoogstens een keer per verzoek per domein.
     *
     * Het geheugen is begrensd omdat deze klasse een singleton is: in een
     * gewoon webverzoek gaat het om een of twee domeinen, maar in een
     * langlopend proces zou een ongelimiteerde lijst html blijven aangroeien.
     */
    private function crawl(string $domain, Budget $budget): SiteCrawl
    {
        if (isset($this->crawls[$domain])) {
            return $this->crawls[$domain];
        }

        if (count($this->crawls) >= self::CRAWL_MEMO_LIMIT) {
            array_shift($this->crawls);
        }

        return $this->crawls[$domain] = $this->crawler->crawl($domain, $budget);
    }

    /** De volledige ronde: crawlen, kiezen, omzetten, wegschrijven. */
    private function fetch(string $domain, ?SiteDetail $previous): SiteDetail
    {
        $budget = new Budget((int) ($this->config['budget_ms'] ?? 4000));
        $crawl = $this->crawl($domain, $budget);

        if (! $crawl->ok()) {
            $detail = $this->failedDetail($domain, $crawl, $previous);

            return $this->store->write($detail, $this->monogramBytes($domain));
        }

        $social = $this->social->discover($crawl);
        $chosen = $this->bestCandidate($crawl, $budget);

        if ($chosen === null) {
            $detail = $this->detail(
                domain: $domain,
                status: SiteDetail::MONOGRAM,
                crawl: $crawl,
                social: $social,
                error: 'no_usable_candidate',
                svgUrl: $this->svgUrl($crawl, $budget),
            );

            return $this->store->write($detail, $this->monogramBytes($domain));
        }

        [$candidate, $transcode] = $chosen;

        $detail = $this->detail(
            domain: $domain,
            status: SiteDetail::OK,
            crawl: $crawl,
            social: $social,
            logoBytes: strlen($transcode->bytes),
            logoSha1: sha1($transcode->bytes),
            source: $candidate->source,
            sourceUrl: $candidate->url,
            sourceWidth: $transcode->sourceWidth,
            sourceHeight: $transcode->sourceHeight,
            sourceRatio: $transcode->sourceRatio,
            hasAlpha: $transcode->hasAlpha,
            trimmed: $transcode->trimmed,
            svgUrl: $this->svgUrl($crawl, $budget),
        );

        return $this->store->write($detail, $transcode->bytes);
    }

    /**
     * Downloadt kandidaten tot er een goed genoeg is.
     *
     * @return array{0: IconCandidate, 1: \HansDeBoeck\BrandFetcher\Image\TranscodeResult}|null
     */
    private function bestCandidate(SiteCrawl $crawl, Budget $budget): ?array
    {
        $candidates = $this->discoverer->discover($crawl, $budget);

        $maxDownloads = max(1, (int) ($this->config['max_downloads'] ?? 3));
        $goodEnough = (int) ($this->config['good_enough_score'] ?? 190);
        $timeout = (float) ($this->config['asset_timeout'] ?? 3);
        $maxBytes = (int) ($this->config['asset_max_bytes'] ?? 2097152);
        $size = $this->size();

        $downloads = 0;
        $best = null;
        $bestScore = PHP_INT_MIN;

        foreach ($candidates as $candidate) {
            if ($candidate->isSvg()) {
                continue;
            }

            if ($downloads >= $maxDownloads || ! $budget->allows($timeout)) {
                break;
            }

            /*
            | De lijst staat op papieren score, dus zodra een kandidaat de beste
            | tot nu toe rekenkundig niet meer kan inhalen, geldt dat ook voor
            | alles wat erachter staat. Dan is doorgaan tijd weggooien.
            */
            if ($best !== null && $this->scorer->paper($candidate) + CandidateScorer::MAX_MEASURED_BONUS < $bestScore) {
                break;
            }

            $response = $this->http->get($candidate->url, $budget, $timeout, $maxBytes);
            $downloads++;

            if (! $response->ok) {
                continue;
            }

            $transcode = $this->transcoder->transcode($response->body, $size, $this->config);

            if ($transcode === null) {
                continue;
            }

            $measured = $candidate->measured($transcode->sourceWidth, $transcode->sourceHeight, $transcode->hasAlpha);
            $score = $this->scorer->hard($measured);

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = [$measured, $transcode];
            }

            if ($score >= $goodEnough) {
                break;
            }
        }

        return $best;
    }

    /** De best scorende svg, alleen om te onthouden. */
    private function svgUrl(SiteCrawl $crawl, Budget $budget): ?string
    {
        foreach ($this->discoverer->discover($crawl, $budget) as $candidate) {
            if ($candidate->isSvg()) {
                return $candidate->url;
            }
        }

        return null;
    }

    private function markPending(string $domain, ?SiteDetail $previous): SiteDetail
    {
        $detail = new SiteDetail(
            domain: $domain,
            domainUnicode: $this->normalizer->unicode($domain),
            status: SiteDetail::PENDING,
            fetchedAt: time(),
            // Geen wachttijd: de verversopdracht mag dit meteen oppakken.
            ttl: 0,
            profiles: $previous?->profiles ?? [],
            name: $previous?->name,
        );

        $bytes = $this->monogramBytes($domain);

        if ($bytes === null) {
            return $this->store->write($detail, null);
        }

        return $this->store->write(
            $this->withLogoBytes($detail, $bytes, monogram: true),
            $bytes,
        );
    }

    private function monogramBytes(string $domain): ?string
    {
        if (($this->config['monogram'] ?? true) === false) {
            return null;
        }

        return $this->monogram->render($domain, $this->size(), $this->config);
    }

    private function withLogoBytes(SiteDetail $detail, string $bytes, bool $monogram): SiteDetail
    {
        return new SiteDetail(
            domain: $detail->domain,
            domainUnicode: $detail->domainUnicode,
            status: $detail->status,
            fetchedAt: $detail->fetchedAt,
            ttl: $detail->ttl,
            finalUrl: $detail->finalUrl,
            attempts: $detail->attempts,
            error: $detail->error,
            logoBytes: strlen($bytes),
            logoSha1: sha1($bytes),
            logoSize: $this->size(),
            monogram: $monogram,
            source: $monogram ? 'monogram' : $detail->source,
            sourceUrl: $detail->sourceUrl,
            sourceWidth: $detail->sourceWidth,
            sourceHeight: $detail->sourceHeight,
            sourceRatio: $detail->sourceRatio,
            hasAlpha: $detail->hasAlpha,
            trimmed: $detail->trimmed,
            svgUrl: $detail->svgUrl,
            profiles: $detail->profiles,
            name: $detail->name,
            failures: $detail->failures,
        );
    }

    /**
     * @param  array{profiles: array<string, SocialProfile>, name: string|null, rejected: int}  $social
     */
    private function detail(
        string $domain,
        string $status,
        SiteCrawl $crawl,
        array $social,
        ?int $logoBytes = null,
        ?string $logoSha1 = null,
        ?string $source = null,
        ?string $sourceUrl = null,
        ?int $sourceWidth = null,
        ?int $sourceHeight = null,
        ?float $sourceRatio = null,
        bool $hasAlpha = false,
        bool $trimmed = false,
        ?string $svgUrl = null,
        ?string $error = null,
    ): SiteDetail {
        $monogram = $status !== SiteDetail::OK;
        $bytes = $logoBytes;
        $sha1 = $logoSha1;

        if ($monogram && $bytes === null) {
            $rendered = $this->monogramBytes($domain);

            if ($rendered !== null) {
                $bytes = strlen($rendered);
                $sha1 = sha1($rendered);
            }
        }

        return new SiteDetail(
            domain: $domain,
            domainUnicode: $this->normalizer->unicode($domain),
            status: $status,
            fetchedAt: time(),
            ttl: $this->ttlFor($status, 0),
            finalUrl: $crawl->finalUrl,
            attempts: 0,
            error: $error,
            logoBytes: $bytes,
            logoSha1: $sha1,
            logoSize: $this->size(),
            monogram: $monogram,
            source: $monogram ? 'monogram' : $source,
            sourceUrl: $sourceUrl,
            sourceWidth: $sourceWidth,
            sourceHeight: $sourceHeight,
            sourceRatio: $sourceRatio,
            hasAlpha: $hasAlpha,
            trimmed: $trimmed,
            svgUrl: $svgUrl,
            profiles: array_values($social['profiles']),
            name: $social['name'],
            failures: [],
        );
    }

    private function failedDetail(string $domain, SiteCrawl $crawl, ?SiteDetail $previous): SiteDetail
    {
        $blocked = in_array($crawl->error, ['blocked_host', 'bad_scheme', 'bad_port', 'userinfo_not_allowed'], true);
        $status = $blocked ? SiteDetail::BLOCKED : SiteDetail::ERROR;

        $attempts = ($previous?->attempts ?? 0) + 1;
        $bytes = $this->monogramBytes($domain);

        return new SiteDetail(
            domain: $domain,
            domainUnicode: $this->normalizer->unicode($domain),
            status: $status,
            fetchedAt: time(),
            ttl: $this->ttlFor($status, $attempts),
            finalUrl: null,
            attempts: $attempts,
            error: $crawl->error,
            logoBytes: $bytes === null ? null : strlen($bytes),
            logoSha1: $bytes === null ? null : sha1($bytes),
            logoSize: $this->size(),
            monogram: $bytes !== null,
            source: $bytes !== null ? 'monogram' : null,
            // Wat we eerder over de profielen wisten blijft staan: een site die
            // vandaag plat ligt, had gisteren nog gewoon een linkedin-pagina.
            profiles: $previous?->profiles ?? [],
            name: $previous?->name,
            failures: [[
                'stage' => 'crawl',
                'url' => $crawl->requestUrl,
                'reason' => (string) $crawl->error,
            ]],
        );
    }

    /**
     * @param  array{profiles: array<string, SocialProfile>, name: string|null, rejected: int}  $social
     */
    private function mergeSocial(string $domain, SiteCrawl $crawl, ?SiteDetail $previous, array $social): SiteDetail
    {
        $status = $previous !== null && $previous->status === SiteDetail::OK
            ? SiteDetail::OK
            : SiteDetail::PENDING;

        return new SiteDetail(
            domain: $domain,
            domainUnicode: $this->normalizer->unicode($domain),
            status: $status,
            fetchedAt: time(),
            // Het beeld is nog niet opgehaald, dus deze entry mag niet als af
            // gelden: de verversopdracht moet er nog langs.
            ttl: $status === SiteDetail::OK ? $this->ttlFor($status, 0) : 0,
            finalUrl: $crawl->finalUrl,
            attempts: 0,
            error: null,
            logoBytes: $previous?->logoBytes,
            logoSha1: $previous?->logoSha1,
            logoSize: $previous?->logoSize ?? $this->size(),
            monogram: $previous?->monogram ?? false,
            source: $previous?->source,
            sourceUrl: $previous?->sourceUrl,
            sourceWidth: $previous?->sourceWidth,
            sourceHeight: $previous?->sourceHeight,
            sourceRatio: $previous?->sourceRatio,
            hasAlpha: $previous?->hasAlpha ?? false,
            trimmed: $previous?->trimmed ?? false,
            svgUrl: $previous?->svgUrl,
            profiles: array_values($social['profiles']),
            name: $social['name'],
            failures: [],
        );
    }

    private function toLogoResult(SiteDetail $detail): LogoResult
    {
        $domain = $detail->domain;

        return new LogoResult(
            domain: $domain,
            domainUnicode: $detail->domainUnicode,
            found: $detail->hasFile(),
            status: $detail->status,
            size: $detail->logoSize,
            etag: $detail->logoSha1,
            bytes: $detail->logoBytes,
            monogram: $detail->monogram,
            source: $detail->source,
            sourceUrl: $detail->sourceUrl,
            sourceWidth: $detail->sourceWidth,
            sourceHeight: $detail->sourceHeight,
            sourceRatio: $detail->sourceRatio,
            svgUrl: $detail->svgUrl,
            error: $detail->error,
            fetchedAt: $detail->fetchedAt,
            ttl: $detail->ttl,
            maxAge: $this->maxAgeFor($detail->status),
            reader: fn (): ?string => $this->store->contents($domain),
            streamer: fn () => $this->store->stream($domain),
        );
    }

    private function toProfileResult(SiteDetail $detail): ProfileResult
    {
        $keyed = [];

        foreach ($detail->profiles as $profile) {
            $keyed[$profile->platform] = $profile;
        }

        return new ProfileResult(
            domain: $detail->domain,
            domainUnicode: $detail->domainUnicode,
            found: $detail->status !== SiteDetail::ERROR && $detail->status !== SiteDetail::BLOCKED,
            status: $detail->status,
            profiles: $keyed,
            name: $detail->name,
            error: $detail->error,
            fetchedAt: $detail->fetchedAt,
            ttl: $detail->ttl,
            maxAge: $this->maxAgeForProfile($detail),
        );
    }

    /**
     * Hoe lang een afnemer de profielen mag bewaren.
     *
     * Dit volgt de crawl en niet de stand van het beeld. Een domein waarvan we
     * de voorpagina gezien hebben, heeft een echt antwoord op deze vraag, ook
     * als het logo zelf nog in de wacht staat: dat zijn twee verschillende
     * dingen en ze horen niet aan dezelfde klok te hangen.
     */
    private function maxAgeForProfile(SiteDetail $detail): int
    {
        $gecrawld = $detail->finalUrl !== null
            && ! in_array($detail->status, [SiteDetail::ERROR, SiteDetail::BLOCKED], true);

        return $gecrawld
            ? (int) ($this->config['browser_max_age'] ?? 86400)
            : (int) ($this->config['pending_max_age'] ?? 300);
    }

    /**
     * De verlooptijd per toestand, nooit korter dan wat een browser toch al
     * bewaart: vaker crawlen dan dat levert niemand iets op en belast alleen
     * de site van een ander.
     */
    private function ttlFor(string $status, int $attempts): int
    {
        $floor = (int) ($this->config['browser_max_age'] ?? 86400);

        $ttl = match ($status) {
            SiteDetail::OK => (int) ($this->config['ttl_ok'] ?? 2592000),
            SiteDetail::MONOGRAM => (int) ($this->config['ttl_monogram'] ?? 604800),
            SiteDetail::BLOCKED => (int) ($this->config['ttl_blocked'] ?? 604800),
            SiteDetail::ERROR => (int) ($this->config['ttl_error'] ?? 86400),
            default => 0,
        };

        // Blijft een domein falen, dan zakt het vanzelf weg naar achteren in
        // plaats van elke dag opnieuw capaciteit op te eten.
        if ($status === SiteDetail::ERROR && $attempts > 1) {
            $ttl = min($ttl * (2 ** ($attempts - 1)), (int) ($this->config['ttl_ok'] ?? 2592000));
        }

        return $status === SiteDetail::PENDING ? 0 : max($ttl, $floor);
    }

    /** Wat een afnemer met dit antwoord mag doen. */
    private function maxAgeFor(string $status): int
    {
        return match ($status) {
            SiteDetail::OK, SiteDetail::MONOGRAM => (int) ($this->config['browser_max_age'] ?? 86400),
            // Dit is nog geen antwoord maar een plaatsvervanger; die hoort niet
            // een dag lang te blijven staan.
            default => (int) ($this->config['pending_max_age'] ?? 300),
        };
    }

    private function size(): int
    {
        return (int) ($this->config['size'] ?? 128);
    }
}
