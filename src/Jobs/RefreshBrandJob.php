<?php

declare(strict_types=1);

namespace HansDeBoeck\BrandFetcher\Jobs;

use HansDeBoeck\BrandFetcher\BrandFetcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Haalt het echte logo op voor een domein dat in de wacht staat.
 *
 * Dit is dezelfde poging als die van brand-fetcher:refresh, maar meteen in
 * plaats van bij de volgende ronde. De bezoeker die het monogram te zien kreeg
 * is allang bediend; deze opdracht zorgt dat de volgende bezoeker het echte
 * logo krijgt.
 *
 * De verversopdracht blijft het vangnet. Draait er geen werker, of loopt deze
 * opdracht stuk, dan staat het domein nog altijd zonder beeld in de opslag en
 * pikt die opdracht het op.
 */
final class RefreshBrandJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Een site kan even onbereikbaar zijn; twee keer proberen is genoeg. */
    public int $tries = 2;

    public function __construct(public readonly string $domain) {}

    public function handle(BrandFetcher $fetcher): void
    {
        $fetcher->refresh($this->domain);
    }
}
