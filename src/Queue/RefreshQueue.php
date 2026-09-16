<?php

declare(strict_types=1);

namespace HansDeBoeck\BrandFetcher\Queue;

use HansDeBoeck\BrandFetcher\Jobs\RefreshBrandJob;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository;

/**
 * Zet een domein dat in de wacht komt op de queue.
 *
 * Alles wat met de queue van de applicatie te maken heeft, staat hier en niet
 * in BrandFetcher: die klasse kent alleen zijn eigen instellingen en raakt het
 * framework nergens aan.
 *
 * Zonder echte queue gebeurt er niets. Met sync zou de opdracht in het
 * webverzoek zelf draaien, en dat is juist wat on_miss op monogram voorkomt;
 * met null zou ze meteen weggegooid worden. In allebei de gevallen blijft
 * brand-fetcher:refresh het vangnet, want het domein staat zonder beeld in de
 * opslag en die opdracht zoekt precies daarop.
 */
final class RefreshQueue
{
    /** Verbindingen die geen wachtrij zijn. */
    private const NO_QUEUE = ['sync', 'null'];

    public function __construct(
        private readonly Dispatcher $bus,
        private readonly Repository $config,
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

        $this->bus->dispatch(new RefreshBrandJob($domain));
    }
}
