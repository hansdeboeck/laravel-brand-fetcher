<?php

declare(strict_types=1);

namespace HansDeBoeck\BrandFetcher\Queue;

use HansDeBoeck\BrandFetcher\Jobs\RefreshBrandJob;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository;
use Throwable;

/**
 * Zet een domein waarvan het logo nog niet af of verlopen is op de queue.
 *
 * Alles wat met de queue van de applicatie te maken heeft, staat hier en niet
 * in BrandFetcher: die klasse kent alleen zijn eigen instellingen en raakt het
 * framework nergens aan.
 *
 * Zonder echte queue gebeurt er niets. Met sync zou de opdracht in het
 * webverzoek zelf draaien, en dat is juist wat on_miss op monogram voorkomt;
 * met null zou ze meteen weggegooid worden. In allebei de gevallen blijft
 * brand-fetcher:refresh het vangnet, want het domein staat verlopen in de
 * opslag en die opdracht zoekt precies daarop.
 */
final class RefreshQueue
{
    /** Verbindingen die geen wachtrij zijn. */
    private const NO_QUEUE = ['sync', 'null'];

    private const CACHE_KEY = 'brand-fetcher:queued:';

    public function __construct(
        private readonly Dispatcher $bus,
        private readonly Repository $config,
        private readonly CacheFactory $cache,
    ) {}

    public function push(string $domain): void
    {
        if ($this->config->get('brand-fetcher.queue', true) === false) {
            return;
        }

        $connection = $this->config->get('queue.default');

        if (! is_string($connection) || in_array($connection, self::NO_QUEUE, true)) {
            return;
        }

        if ($this->recentlyQueued($domain)) {
            return;
        }

        $this->bus->dispatch(new RefreshBrandJob($domain));
    }

    /**
     * Is dit domein net al gevraagd?
     *
     * Een beeld hangt in een img-tag en wordt per paginaweergave opgevraagd. Elk
     * verzoek een opdracht laten maken zou de rij vullen met hetzelfde werk, dus
     * geldt er een afkoelperiode per domein.
     *
     * Deze bewaking faalt open. Een store die niets bewaart geeft op add() ook
     * false, net als een afkoelperiode van nul, en een cache die eruit ligt
     * gooit: in al die gevallen gaat de opdracht gewoon door. Liever een keer
     * dubbel werk dan een logo dat nooit komt.
     */
    private function recentlyQueued(string $domain): bool
    {
        $cooldown = (int) $this->config->get('brand-fetcher.queue_cooldown', 300);

        if ($cooldown <= 0) {
            return false;
        }

        $key = self::CACHE_KEY . $domain;

        try {
            // De store pas hier opvragen: deze klasse hangt aan een singleton en
            // zou anders de store van het eerste verzoek vastpinnen.
            $store = $this->cache->store();

            if ($store->add($key, true, $cooldown)) {
                return false;
            }

            return $store->get($key) !== null;
        } catch (Throwable) {
            return false;
        }
    }
}
