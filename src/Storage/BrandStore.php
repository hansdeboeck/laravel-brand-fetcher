<?php

declare(strict_types=1);

namespace HansDeBoeck\BrandFetcher\Storage;

use Generator;
use HansDeBoeck\BrandFetcher\BrandStorePaths;
use HansDeBoeck\BrandFetcher\Net\DomainNormalizer;
use HansDeBoeck\BrandFetcher\SiteDetail;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use InvalidArgumentException;
use League\Flysystem\StorageAttributes;
use Throwable;

/**
 * De schijf achter de dienst: per domein een map met logo.webp en detail.json.
 *
 * Er wordt uitsluitend met de filesystem-factory gepraat en nooit met een
 * bestandspad. Dat is niet netheid maar noodzaak: dezelfde code moet op de
 * lokale schijf en op s3 werken, en op s3 bestaat een pad niet.
 */
final class BrandStore
{
    private readonly string $diskName;

    private readonly string $folder;

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly FilesystemFactory $filesystem,
        private readonly array $config = [],
    ) {
        $storage = (array) ($config['storage'] ?? []);

        $this->diskName = (string) ($storage['disk'] ?? 'local');
        $this->folder = trim((string) ($storage['folder'] ?? 'hans/brand'), '/');
    }

    public function diskName(): string
    {
        return $this->diskName;
    }

    public function disk(): Filesystem
    {
        return $this->filesystem->disk($this->diskName);
    }

    /**
     * Het pad van de map van een domein.
     *
     * De domeinnaam komt van een afnemer, dus die wordt hier nog een keer tegen
     * dezelfde allowlist gehouden als aan de deur. Deze klasse kan namelijk ook
     * rechtstreeks aangeroepen worden, en dan is dit de enige controle die er is.
     */
    public function directory(string $domain): string
    {
        if (! preg_match(DomainNormalizer::HOST_PATTERN, $domain)) {
            throw new InvalidArgumentException("Ongeldige domeinnaam voor de opslag: {$domain}");
        }

        return $this->folder . '/' . $domain;
    }

    public function logoPath(string $domain): string
    {
        return $this->directory($domain) . '/' . BrandStorePaths::LOGO_FILE;
    }

    public function detailPath(string $domain): string
    {
        return $this->directory($domain) . '/' . BrandStorePaths::DETAIL_FILE;
    }

    /**
     * Leest detail.json, met de mtime als klok.
     *
     * Onleesbaar of uit een andere versie telt als afwezig: dan wordt het domein
     * gewoon opnieuw opgehaald. Dat is meteen het vangnet voor een schrijfbeurt
     * die halverwege afbrak.
     */
    public function detail(string $domain): ?SiteDetail
    {
        $path = $this->detailPath($domain);

        try {
            $raw = $this->disk()->get($path);
        } catch (Throwable) {
            return null;
        }

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);

        if (! is_array($data)) {
            return null;
        }

        return SiteDetail::fromDetailArray($data, $this->lastModified($domain));
    }

    /**
     * Schrijft het beeld en daarna pas de details.
     *
     * De volgorde is dwingend en detail.json is het commit-record. Mislukt de
     * tweede schrijfbeurt, dan vindt de volgende lezer geen details, telt de
     * entry als afwezig en wordt alles overschreven: het beeld dat dan even
     * alleen staat is onschadelijk. Andersom zou de ergste kant zijn, want dan
     * gelooft een lezer dat er een logo is terwijl het bestand ontbreekt.
     */
    public function write(SiteDetail $detail, ?string $logoBytes): SiteDetail
    {
        if ($logoBytes !== null && $logoBytes !== '') {
            $this->put($this->logoPath($detail->domain), $logoBytes);
        }

        $this->put(
            $this->detailPath($detail->domain),
            (string) json_encode($detail->toDetailArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        );

        return $detail;
    }

    public function contents(string $domain): ?string
    {
        try {
            $bytes = $this->disk()->get($this->logoPath($domain));
        } catch (Throwable) {
            return null;
        }

        return is_string($bytes) && $bytes !== '' ? $bytes : null;
    }

    /** @return resource|null */
    public function stream(string $domain)
    {
        try {
            $stream = $this->disk()->readStream($this->logoPath($domain));
        } catch (Throwable) {
            return null;
        }

        return is_resource($stream) ? $stream : null;
    }

    public function lastModified(string $domain): ?int
    {
        try {
            return (int) $this->disk()->lastModified($this->detailPath($domain));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * De publieke url van het beeld, of null als deze disk er geen kent.
     *
     * De lokale adapter gooit hier niet maar verzint /storage/<pad>, en dat wijst
     * naar storage/app/public terwijl onze bestanden in storage/app staan. Een
     * url teruggeven die naar niets wijst is erger dan er geen teruggeven, dus
     * eerst kijken of de disk er echt een kent.
     */
    public function url(string $domain): ?string
    {
        $config = (array) config('filesystems.disks.' . $this->diskName, []);

        $servesUrls = isset($config['url']) || ($config['driver'] ?? '') === 's3';

        if (! $servesUrls) {
            return null;
        }

        try {
            return $this->disk()->url($this->logoPath($domain));
        } catch (Throwable) {
            return null;
        }
    }

    public function forget(string $domain): void
    {
        try {
            $this->disk()->deleteDirectory($this->directory($domain));
        } catch (Throwable) {
            // Een map die al weg is, is precies wat we wilden.
        }
    }

    /**
     * Loopt het archief af met de mtime erbij.
     *
     * De listing levert die mtime al mee: op de lokale adapter uit de SplFileInfo
     * die de iterator toch maakt, en op s3 uit het lijstverzoek zelf. Dat is het
     * hele punt van deze aanpak: per domein een HEAD-verzoek doen zou op s3
     * duizend heenreizen kosten waar er nu een paar volstaan.
     *
     * @return Generator<int, array{domain: string, mtime: int, hasLogo: bool, hasDetail: bool}>
     */
    public function walk(?string $after = null): Generator
    {
        $entries = [];

        try {
            $listing = $this->disk()->getDriver()->listContents($this->folder, true);
        } catch (Throwable) {
            return;
        }

        /** @var StorageAttributes $item */
        foreach ($listing as $item) {
            if (! $item->isFile()) {
                continue;
            }

            $relative = ltrim(substr($item->path(), strlen($this->folder)), '/');
            $parts = explode('/', $relative);

            if (count($parts) !== 2) {
                continue;
            }

            [$domain, $file] = $parts;

            if ($after !== null && strcmp($domain, $after) <= 0) {
                continue;
            }

            if ($file === BrandStorePaths::DETAIL_FILE) {
                $entries[$domain]['mtime'] = (int) ($item->lastModified() ?? 0);
                $entries[$domain]['hasDetail'] = true;
            } elseif ($file === BrandStorePaths::LOGO_FILE) {
                $entries[$domain]['hasLogo'] = true;
            }
        }

        ksort($entries);

        foreach ($entries as $domain => $row) {
            if (! ($row['hasDetail'] ?? false)) {
                continue;
            }

            yield [
                'domain' => (string) $domain,
                'mtime' => (int) ($row['mtime'] ?? 0),
                'hasLogo' => (bool) ($row['hasLogo'] ?? false),
                'hasDetail' => true,
            ];
        }
    }

    public function readCursor(): ?string
    {
        try {
            $value = $this->disk()->get($this->folder . '/' . BrandStorePaths::CURSOR_FILE);
        } catch (Throwable) {
            return null;
        }

        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }

    public function writeCursor(?string $domain): void
    {
        $this->put($this->folder . '/' . BrandStorePaths::CURSOR_FILE, (string) $domain);
    }

    /*
    | Altijd eroverheen schrijven, nooit eerst verwijderen: zo is er geen moment
    | waarop de entry weg is voor een lezer die net langskomt.
    */
    private function put(string $path, string $contents): void
    {
        $visibility = ((array) ($this->config['storage'] ?? []))['visibility'] ?? null;

        if (is_string($visibility) && $visibility !== '') {
            $this->disk()->put($path, $contents, $visibility);

            return;
        }

        $this->disk()->put($path, $contents);
    }
}
