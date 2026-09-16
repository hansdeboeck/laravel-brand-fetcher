<?php

declare(strict_types=1);

namespace HansDeBoeck\BrandFetcher;

use HansDeBoeck\BrandFetcher\Net\SafeHttp;
use HansDeBoeck\BrandFetcher\Net\UrlGuard;
use HansDeBoeck\BrandFetcher\Storage\BrandStore;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\ServiceProvider;

class BrandFetcherServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/brand-fetcher.php', 'brand-fetcher');

        $this->app->singleton(UrlGuard::class, function ($app) {
            return new UrlGuard($app['config']->get('brand-fetcher', []));
        });

        $this->app->singleton(SafeHttp::class, function ($app) {
            return new SafeHttp(
                $app->make(UrlGuard::class),
                $app['config']->get('brand-fetcher', []),
            );
        });

        $this->app->singleton(BrandStore::class, function ($app) {
            return new BrandStore(
                $app->make(FilesystemFactory::class),
                $app['config']->get('brand-fetcher', []),
            );
        });

        /*
        | Singleton op de concrete klasse, met de config in de constructor
        | gebakken. Dat is niet alleen het patroon van de andere packages hier:
        | het maakt ook het geheugen mogelijk waardoor logo() en profile() in
        | hetzelfde verzoek samen een keer de voorpagina ophalen.
        */
        $this->app->singleton(BrandFetcher::class, function ($app) {
            return new BrandFetcher(
                $app->make(BrandStore::class),
                $app->make(SafeHttp::class),
                $app['config']->get('brand-fetcher', []),
            );
        });

        $this->app->alias(BrandFetcher::class, Contracts\FetchesBrands::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/brand-fetcher.php' => config_path('brand-fetcher.php'),
            ], 'brand-fetcher-config');

            $this->commands([
                Commands\RefreshBrandsCommand::class,
            ]);
        }
    }
}
