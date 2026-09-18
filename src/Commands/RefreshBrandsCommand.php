<?php

declare(strict_types=1);

namespace HansDeBoeck\BrandFetcher\Commands;

use HansDeBoeck\BrandFetcher\BrandFetcher;
use HansDeBoeck\BrandFetcher\Net\Budget;
use HansDeBoeck\BrandFetcher\SiteDetail;
use HansDeBoeck\BrandFetcher\Storage\BrandStore;
use Illuminate\Console\Command;

/**
 * Haalt op wat nog niet opgehaald is en vernieuwt wat verlopen is.
 *
 * Dit is de wachtrij van deze dienst. Op hosting zonder echte cron en zonder
 * queue-werker is een bestand met de stand "pending" precies dat: een taak die
 * klaarligt. Dat kost een klein bestandje en geen enkele infrastructuur.
 *
 * De opdracht stopt altijd zelf op tijd. Wordt schedule:run over http
 * aangeroepen, dan telt de tijdslimiet van een webverzoek ook hier, en een
 * ronde die halverwege afgekapt wordt laat de cursor niet achter.
 */
class RefreshBrandsCommand extends Command
{
    protected $signature = 'brand-fetcher:refresh
                            {domain?* : Bepaalde domeinen, in plaats van wat verlopen is}
                            {--max= : Hoogstens zoveel domeinen deze ronde}
                            {--budget= : Wandklokbudget in milliseconden}
                            {--force : Ook wat nog niet verlopen is}
                            {--delete : De opgegeven domeinen verwijderen in plaats van verversen}';

    protected $description = 'Ververst de opgeslagen logos en sociale profielen.';

    /** @var array<string, true> De domeinen die deze ronde voorrang kregen. */
    private array $priority = [];

    public function handle(BrandFetcher $fetcher, BrandStore $store): int
    {
        $domains = array_values(array_filter((array) $this->argument('domain')));

        if ($this->option('delete')) {
            return $this->delete($domains, $store);
        }

        $budget = new Budget((int) ($this->option('budget') ?? config('brand-fetcher.refresh_budget_ms', 25000)));
        $max = (int) ($this->option('max') ?? config('brand-fetcher.refresh_max', 15));

        $done = 0;
        $last = null;

        foreach ($this->targets($domains, $store) as $domain) {
            // De controle staat tussen twee domeinen en nooit middenin een
            // omzetting: half werk laat een bestandenpaar achter dat niet klopt.
            if ($done >= $max || $budget->exhausted()) {
                break;
            }

            $detail = $fetcher->refresh($domain);
            $done++;

            // De cursor onthoudt alleen waar de verlopen rij gebleven was. Een
            // domein met voorrang staat op een willekeurige plek in het alfabet
            // en zou alles ertussen een ronde laten overslaan.
            if (! isset($this->priority[$domain])) {
                $last = $domain;
            }

            $this->line(sprintf(
                '  %-42s %-9s %s',
                $domain,
                $detail?->status ?? 'overgeslagen',
                $detail?->source ?? '-',
            ));
        }

        if ($domains === [] && $last !== null) {
            $store->writeCursor($last);
        }

        $this->info(sprintf('%d domeinen ververst in %d ms.', $done, (int) round($budget->elapsedMs())));

        return self::SUCCESS;
    }

    /**
     * Wat er deze ronde aan de beurt is.
     *
     * Eerst de domeinen zonder beeld: die zijn gratis te herkennen aan de
     * listing, zonder ook maar een bestand te openen, en het zijn precies de
     * bezoekers die nu een monogram zien waar een logo hoort.
     *
     * @param  list<string>  $domains
     * @return list<string>
     */
    private function targets(array $domains, BrandStore $store): array
    {
        if ($domains !== []) {
            return $domains;
        }

        $force = (bool) $this->option('force');
        $cursor = $store->readCursor();
        $now = time();

        $pending = [];
        $stale = [];

        foreach ($store->walk() as $entry) {
            if (! $entry['hasLogo']) {
                $pending[] = $entry['domain'];

                continue;
            }

            // Vanaf de cursor verder, zodat een volgende ronde niet opnieuw bij
            // het begin van het alfabet begint.
            if ($cursor !== null && strcmp($entry['domain'], $cursor) <= 0) {
                continue;
            }

            $detail = $force ? null : $store->detail($entry['domain']);

            if ($force || $detail === null || $detail->isStale($now)) {
                $stale[] = $entry['domain'];
            }
        }

        $this->priority = array_fill_keys($pending, true);

        $targets = array_merge($pending, $stale);

        // Aan het einde van de lijst weer vooraan beginnen.
        if ($stale === [] && $cursor !== null) {
            $store->writeCursor(null);
        }

        return $targets;
    }

    /** @param list<string> $domains */
    private function delete(array $domains, BrandStore $store): int
    {
        if ($domains === []) {
            $this->error('Geef minstens een domein op om te verwijderen.');

            return self::FAILURE;
        }

        foreach ($domains as $domain) {
            $store->forget($domain);
            $this->line('  verwijderd: ' . $domain);
        }

        return self::SUCCESS;
    }
}
