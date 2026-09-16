<?php

declare(strict_types=1);

namespace HansDeBoeck\BrandFetcher\Net;

/**
 * Bewaakt elk adres dat deze server namens een afnemer gaat ophalen.
 *
 * Bij de avatars op deboeck.dev bepaalt wat de bezoeker stuurt alleen het zaad
 * en nooit het adres dat de server opvraagt. Hier is dat precies andersom: de
 * afnemer kiest het domein en wij gaan het bezoeken. Daarom hoort deze controle
 * bij elke hop opnieuw te draaien, en niet een keer aan de deur.
 *
 * De resolver is injecteerbaar: echte dns in een testsuite is traag en
 * wispelturig, en een test die soms faalt is erger dan geen test.
 */
final class UrlGuard
{
    /**
     * Bereiken die filter_var met NO_PRIV_RANGE en NO_RES_RANGE laat staan,
     * maar die nooit een publieke site kunnen zijn. Zonder deze lijst is
     * 100.64.0.0/10 (het bereik dat providers voor carrier-grade nat
     * gebruiken) gewoon toegestaan.
     */
    private const EXTRA_BLOCKED = [
        '100.64.0.0/10',      // carrier-grade nat
        '192.0.0.0/24',       // ietf-toewijzingen
        '192.0.2.0/24',       // documentatie
        '192.88.99.0/24',     // 6to4-relay
        '198.18.0.0/15',      // netwerkmetingen
        '198.51.100.0/24',    // documentatie
        '203.0.113.0/24',     // documentatie
        '224.0.0.0/4',        // multicast
        '64:ff9b::/96',       // nat64
        '2002::/16',          // 6to4
        '100::/64',           // weggooibereik
        'fec0::/10',          // site-local, afgevoerd maar nog in omloop
        'ff00::/8',           // multicast
    ];

    /** @var (callable(string): list<string>)|null */
    private $resolver;

    /**
     * @param  array<string, mixed>  $config
     * @param  (callable(string): list<string>)|null  $resolver
     */
    public function __construct(
        private readonly array $config = [],
        ?callable $resolver = null,
    ) {
        $this->resolver = $resolver;
    }

    /**
     * Mag deze url opgehaald worden? Geeft bij goedkeuring de adressen mee
     * waarop de verbinding gepind mag worden.
     */
    public function check(string $url): HostVerdict
    {
        $parts = parse_url($url);

        if ($parts === false || ! is_array($parts)) {
            return HostVerdict::deny('malformed_url');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $allowHttp = (bool) ($this->config['allow_http'] ?? false);

        if ($scheme !== 'https' && ! ($allowHttp && $scheme === 'http')) {
            return HostVerdict::deny('bad_scheme');
        }

        // Gebruikersinfo in een url is in dit verband nooit onschuldig: het is
        // de klassieke manier om te laten lijken alsof je ergens anders heen
        // gaat dan waar je heen gaat.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return HostVerdict::deny('userinfo_not_allowed');
        }

        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));

        if ($host === '') {
            return HostVerdict::deny('no_host');
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        if (! in_array($port, [80, 443], true)) {
            return HostVerdict::deny('bad_port', $host);
        }

        if (($this->config['allow_private_hosts'] ?? false) === true) {
            return HostVerdict::allow($host, $port, []);
        }

        $addresses = $this->resolve($host);

        if ($addresses === []) {
            return HostVerdict::deny('dns_failed', $host);
        }

        /*
        | Alle adressen moeten publiek zijn, niet alleen het eerste. Een host
        | met twee a-records waarvan er een naar 10.0.0.1 wijst is geen halve
        | treffer maar een hele weigering: welke van de twee curl pakt, ligt
        | niet bij ons.
        */
        foreach ($addresses as $address) {
            if (! $this->isPublic($address)) {
                return HostVerdict::deny('blocked_host', $host);
            }
        }

        return HostVerdict::allow($host, $port, $addresses);
    }

    /** @return list<string> */
    private function resolve(string $host): array
    {
        if ($this->resolver !== null) {
            return array_values(array_filter(($this->resolver)($host), 'is_string'));
        }

        $addresses = [];

        $v4 = @gethostbynamel($host);

        if (is_array($v4)) {
            $addresses = $v4;
        }

        // dns_get_record kan waarschuwingen geven op een host zonder aaaa; die
        // horen hier geen test of logregel om te trekken.
        $v6 = @dns_get_record($host, DNS_AAAA);

        if (is_array($v6)) {
            foreach ($v6 as $record) {
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($addresses));
    }

    /** Is dit adres routeerbaar op het publieke internet? */
    public function isPublic(string $ip): bool
    {
        $binary = @inet_pton($ip);

        if ($binary === false) {
            return false;
        }

        /*
        | Een ipv4-adres vermomd als ipv6 (::ffff:10.0.0.1) moet als ipv4
        | beoordeeld worden. filter_var kijkt naar de schrijfwijze, niet naar
        | wat het adres betekent, dus dat doen we hier zelf.
        */
        if (strlen($binary) === 16 && str_starts_with($binary, str_repeat("\0", 10) . "\xff\xff")) {
            $mapped = inet_ntop(substr($binary, 12));

            return is_string($mapped) && $this->isPublic($mapped);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        foreach (self::EXTRA_BLOCKED as $cidr) {
            if ($this->inCidr($binary, $cidr)) {
                return false;
            }
        }

        return true;
    }

    /** Vergelijkt op bitniveau, zodat ipv4 en ipv6 langs dezelfde weg gaan. */
    private function inCidr(string $binary, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr, 2);

        $subnetBinary = @inet_pton($subnet);

        if ($subnetBinary === false || strlen($subnetBinary) !== strlen($binary)) {
            return false;
        }

        $bits = (int) $bits;
        $fullBytes = intdiv($bits, 8);
        $restBits = $bits % 8;

        if ($fullBytes > 0 && strncmp($binary, $subnetBinary, $fullBytes) !== 0) {
            return false;
        }

        if ($restBits === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $restBits) & 0xFF;

        return (ord($binary[$fullBytes]) & $mask) === (ord($subnetBinary[$fullBytes]) & $mask);
    }
}
