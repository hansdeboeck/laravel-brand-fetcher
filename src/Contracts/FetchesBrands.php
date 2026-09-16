<?php

declare(strict_types=1);

namespace HansDeBoeck\BrandFetcher\Contracts;

use HansDeBoeck\BrandFetcher\LogoResult;
use HansDeBoeck\BrandFetcher\ProfileResult;
use HansDeBoeck\BrandFetcher\SiteDetail;

interface FetchesBrands
{
    /**
     * Het logo van een domein als vierkante webp.
     *
     * Bij een onbekend domein hangt het gedrag af van on_miss: standaard komt er
     * meteen een monogram en haalt de verversopdracht later het echte logo op,
     * zodat een beeld in een img-tag nooit op een crawl staat te wachten.
     */
    public function logo(string $domain, int $size = 128, bool $refresh = false): LogoResult;

    /** De sociale profielen van datzelfde domein, uit dezelfde voorpagina. */
    public function profile(string $domain, bool $refresh = false): ProfileResult;

    /** Alles opnieuw ophalen; dit is wat de verversopdracht aanroept. */
    public function refresh(string $domain): ?SiteDetail;

    /** Leest alleen de opslag en doet nooit een verzoek naar buiten. */
    public function cached(string $domain): ?LogoResult;
}
