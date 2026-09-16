<?php

declare(strict_types=1);

namespace HansDeBoeck\BrandFetcher\Net;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Haalt op wat een afnemer aanwijst, met drie dingen die een gewone Http-call
 * niet doet: elke omleiding apart gecontroleerd, een harde bytegrens, en een
 * budget dat over alle fasen samen loopt.
 *
 * De omleidingen worden met de hand gevolgd omdat het niet anders kan. Guzzle
 * roept on_redirect wel aan maar doet niets met wat die teruggeeft, dus een
 * omleiding is daarmee niet tegen te houden. Wie de controle alleen op de
 * eerste url doet, laat een site zelf kiezen waar wij daarna heen gaan.
 */
final class SafeHttp
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly UrlGuard $guard,
        private readonly array $config = [],
    ) {}

    /**
     * @param  string|null  $stopAt  Stop met lezen zodra dit stuk tekst binnen is.
     * @param  bool  $allowPartial  Is het eerste stuk ook bruikbaar?
     */
    public function get(
        string $url,
        Budget $budget,
        float $timeout,
        int $maxBytes,
        ?string $stopAt = null,
        bool $allowPartial = false,
    ): SafeResponse {
        $maxHops = max(0, (int) ($this->config['max_redirects'] ?? 3));
        $seen = [];

        for ($hop = 0; $hop <= $maxHops; $hop++) {
            if (! $budget->allows($timeout)) {
                return SafeResponse::failure('budget_exhausted', finalUrl: $url);
            }

            $key = Url::dedupeKey($url);

            if (isset($seen[$key])) {
                return SafeResponse::failure('redirect_loop', finalUrl: $url);
            }

            $seen[$key] = true;

            $verdict = $this->guard->check($url);

            if (! $verdict->allowed) {
                return SafeResponse::failure((string) $verdict->reason, finalUrl: $url);
            }

            try {
                $response = $this->request($url, $verdict, $budget, $timeout);
            } catch (Throwable $e) {
                return SafeResponse::failure('unreachable', finalUrl: $url);
            }

            $status = $response->status();

            if ($status >= 300 && $status < 400) {
                $location = $response->header('Location');

                if ($location === '') {
                    return SafeResponse::failure('redirect_without_location', $status, $url);
                }

                $next = Url::absolutise($location, $url);

                if ($next === null) {
                    return SafeResponse::failure('bad_redirect', $status, $url);
                }

                $url = $next;

                continue;
            }

            if ($status < 200 || $status >= 300) {
                return SafeResponse::failure('http_' . $status, $status, $url);
            }

            /*
            | Een Content-Length die te groot is, is de goedkoopste afwijzing die
            | er bestaat: geen bytes gelezen. Maar alleen waar een half bestand
            | niets waard is, zoals bij een beeld of een manifest. Bij html is
            | het eerste stuk juist precies wat we zoeken, en een voorpagina van
            | meer dan een halve megabyte is geen uitzondering: die afwijzen zou
            | betekenen dat we de grootste sites overslaan.
            |
            | Nooit als waarheid gebruiken: een chunked antwoord heeft geen
            | Content-Length, en een server mag erin liegen.
            */
            $announced = (int) $response->header('Content-Length');

            if (! $allowPartial && $announced > 0 && $announced > $maxBytes) {
                return SafeResponse::failure('too_large', $status, $url);
            }

            try {
                [$body, $truncated] = $this->readCapped($response, $maxBytes, $budget, $stopAt);
            } catch (Throwable) {
                // Een stroom die halverwege dichtvalt is vervelend maar geen
                // reden om het verzoek van een bezoeker om te trekken.
                return SafeResponse::failure('unreadable_body', $status, $url);
            }

            return SafeResponse::success(
                status: $status,
                body: $body,
                finalUrl: $url,
                contentType: $this->contentType($response),
                truncated: $truncated,
            );
        }

        return SafeResponse::failure('too_many_redirects', finalUrl: $url);
    }

    private function request(string $url, HostVerdict $verdict, Budget $budget, float $timeout): Response
    {
        $options = [
            'stream' => true,
            // De omleidingen doen we zelf; laat Guzzle er vooral af blijven.
            'allow_redirects' => false,
        ];

        /*
        | De verbinding pinnen op de adressen die de controle net goedkeurde.
        | Zonder dit blijft er een venster open tussen de controle en het
        | verbinden waarin dns naar een intern adres kan gaan wijzen.
        */
        if (($this->config['pin_dns'] ?? true) && $verdict->addresses !== [] && defined('CURLOPT_RESOLVE')) {
            $options['curl'] = [
                CURLOPT_RESOLVE => array_map(
                    static fn (string $ip): string => $verdict->host . ':' . $verdict->port . ':' . $ip,
                    $verdict->addresses,
                ),
            ];
        }

        return Http::withOptions($options)
            ->withHeaders([
                'User-Agent' => (string) ($this->config['user_agent'] ?? 'BrandFetcher/1.0'),
                'Accept' => '*/*',
                'Accept-Encoding' => 'identity',
            ])
            ->connectTimeout((float) ($this->config['connect_timeout'] ?? 2))
            ->timeout($budget->clamp($timeout))
            ->get($url);
    }

    /**
     * Leest in blokken en stopt bij de grens, bij het budget, of zodra het
     * stuk dat we zochten binnen is. Dat laatste scheelt op een zware
     * voorpagina het grootste deel van de download.
     *
     * @return array{0: string, 1: bool}
     */
    private function readCapped(Response $response, int $maxBytes, Budget $budget, ?string $stopAt): array
    {
        $stream = $response->toPsrResponse()->getBody();
        $buffer = '';
        $truncated = false;

        while (! $stream->eof()) {
            if ($budget->exhausted()) {
                $truncated = true;

                break;
            }

            if (strlen($buffer) >= $maxBytes) {
                $truncated = true;

                break;
            }

            $chunk = $stream->read(8192);

            if ($chunk === '') {
                break;
            }

            $buffer .= $chunk;

            // Alleen in de staart kijken plus de overlap, anders wordt dit op een
            // grote pagina een zoekopdracht per blok over alles wat er al staat.
            if ($stopAt !== null && stripos(substr($buffer, -8192 - strlen($stopAt)), $stopAt) !== false) {
                break;
            }
        }

        $stream->close();

        return [substr($buffer, 0, $maxBytes), $truncated];
    }

    private function contentType(Response $response): ?string
    {
        $header = $response->header('Content-Type');

        if ($header === '') {
            return null;
        }

        return strtolower(trim(explode(';', $header)[0]));
    }
}
