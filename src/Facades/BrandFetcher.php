<?php

declare(strict_types=1);

namespace HansDeBoeck\BrandFetcher\Facades;

use HansDeBoeck\BrandFetcher\Contracts\FetchesBrands;
use HansDeBoeck\BrandFetcher\BrandFetcher as Concrete;
use HansDeBoeck\BrandFetcher\BrandFetcherFake;
use HansDeBoeck\BrandFetcher\LogoResult;
use HansDeBoeck\BrandFetcher\ProfileResult;
use HansDeBoeck\BrandFetcher\SiteDetail;
use Illuminate\Support\Facades\Facade;

/**
 * @method static LogoResult logo(string $domain, int $size = 128, bool $refresh = false)
 * @method static ProfileResult profile(string $domain, bool $refresh = false)
 * @method static SiteDetail|null refresh(string $domain)
 * @method static LogoResult|null cached(string $domain)
 *
 * @see Concrete
 */
class BrandFetcher extends Facade
{
    /**
     * Vervangt de ophaler in tests, zodat er geen enkel verzoek naar buiten gaat.
     *
     * @param  array<string, array<string, mixed>>  $results per domein wat het moet teruggeven
     */
    public static function fake(array $results = []): BrandFetcherFake
    {
        return static::swapFake(new BrandFetcherFake($results));
    }

    /** Doet alsof er voor geen enkel domein iets te vinden is. */
    public static function fakeMissing(): BrandFetcherFake
    {
        return static::swapFake(new BrandFetcherFake([], found: false));
    }

    /**
     * Drie bindingen en niet alleen swap(): code die het contract vraagt, code
     * die de concrete klasse type-hint en de gevel zelf lopen elk langs een
     * andere sleutel. Zonder alle drie werkt fake() maar in een deel ervan.
     */
    protected static function swapFake(BrandFetcherFake $fake): BrandFetcherFake
    {
        static::swap($fake);

        $app = static::getFacadeApplication();
        $app->instance(Concrete::class, $fake);
        $app->instance(FetchesBrands::class, $fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return Concrete::class;
    }
}
