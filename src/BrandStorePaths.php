<?php

declare(strict_types=1);

namespace HansDeBoeck\BrandFetcher;

/**
 * De twee bestandsnamen die per domein vastliggen.
 *
 * Ze staan apart zodat het formaat van detail.json ernaar kan verwijzen zonder
 * de opslagklasse te kennen, en zodat er maar een plek is waar ze staan.
 */
final class BrandStorePaths
{
    public const LOGO_FILE = 'logo.webp';

    public const DETAIL_FILE = 'detail.json';

    /** Houdt bij waar de vorige verversronde gestopt is. */
    public const CURSOR_FILE = '.cursor';
}
