<?php

declare(strict_types=1);

namespace HansDeBoeck\BrandFetcher\Tests;

use HansDeBoeck\BrandFetcher\BrandFetcherServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            BrandFetcherServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');

        // Laravel 13 zet dit standaard op false. Door het hier ook zo te zetten
        // testen we onder de scherpste stand: er mag geen object door de cache.
        $app['config']->set('cache.serializable_classes', false);

        // Geen echte dns in de suite: die is traag en wispelturig, en een test
        // die soms faalt is erger dan geen test.
        $app['config']->set('brand-fetcher.allow_private_hosts', true);
        $app['config']->set('brand-fetcher.pin_dns', false);
        $app['config']->set('brand-fetcher.respect_robots', false);
    }
}
