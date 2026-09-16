<?php

declare(strict_types=1);

namespace HansDeBoeck\BrandFetcher\Social;

/**
 * De platformen die we herkennen, met per stuk de hosts en de padvormen.
 *
 * Het lastigste hier zijn niet de profielen maar de DEELKNOPPEN. Zowat elke
 * site heeft een rij knoppen naar facebook.com/sharer, twitter.com/intent en
 * linkedin.com/shareArticle. Dat zijn links naar een platform, maar ze zeggen
 * niets over het merk. Wie die meeneemt, krijgt voor elke site dezelfde vijf
 * nutteloze profielen terug.
 */
enum Platform: string
{
    case Facebook = 'facebook';
    case Instagram = 'instagram';
    case X = 'x';
    case LinkedIn = 'linkedin';
    case YouTube = 'youtube';
    case TikTok = 'tiktok';
    case Pinterest = 'pinterest';
    case GitHub = 'github';
    case Threads = 'threads';
    case Bluesky = 'bluesky';
    case WhatsApp = 'whatsapp';
    case Vimeo = 'vimeo';
    case Snapchat = 'snapchat';
    case Xing = 'xing';
    case Spotify = 'spotify';
    case Telegram = 'telegram';
    case Mastodon = 'mastodon';

    /** @return list<string> */
    public function hosts(): array
    {
        return match ($this) {
            self::Facebook => ['facebook.com', 'fb.com', 'fb.me', 'facebook.net'],
            self::Instagram => ['instagram.com', 'instagr.am'],
            self::X => ['x.com', 'twitter.com'],
            self::LinkedIn => ['linkedin.com'],
            self::YouTube => ['youtube.com'],
            self::TikTok => ['tiktok.com'],
            self::Pinterest => ['pinterest.com', 'pinterest.be', 'pinterest.nl', 'pinterest.fr',
                'pinterest.de', 'pinterest.co.uk', 'pinterest.es', 'pinterest.it'],
            self::GitHub => ['github.com'],
            self::Threads => ['threads.net', 'threads.com'],
            self::Bluesky => ['bsky.app'],
            self::WhatsApp => ['wa.me', 'chat.whatsapp.com'],
            self::Vimeo => ['vimeo.com'],
            self::Snapchat => ['snapchat.com'],
            self::Xing => ['xing.com'],
            self::Spotify => ['open.spotify.com', 'spotify.com'],
            self::Telegram => ['t.me', 'telegram.me'],
            // Mastodon draait op elke host; die wordt apart afgehandeld.
            self::Mastodon => [],
        };
    }

    /** De host waar we alles naartoe schrijven, zodat twee schrijfwijzen samenvallen. */
    public function canonicalHost(): ?string
    {
        return match ($this) {
            self::Facebook => 'facebook.com',
            self::Instagram => 'instagram.com',
            self::X => 'x.com',
            self::LinkedIn => 'linkedin.com',
            self::YouTube => 'youtube.com',
            self::TikTok => 'tiktok.com',
            self::Pinterest => 'pinterest.com',
            self::GitHub => 'github.com',
            self::Threads => 'threads.net',
            self::Bluesky => 'bsky.app',
            self::Vimeo => 'vimeo.com',
            self::Snapchat => 'snapchat.com',
            self::Xing => 'xing.com',
            // wa.me en chat.whatsapp.com zijn verschillende dingen, spotify ook.
            default => null,
        };
    }

    /**
     * Paden die naar dit platform wijzen maar geen profiel zijn.
     *
     * @return list<string>
     */
    public function sharePaths(): array
    {
        return match ($this) {
            self::Facebook => ['/sharer', '/share.php', '/share', '/dialog/', '/plugins/', '/tr', '/login', '/v2.0/'],
            self::X => ['/intent/', '/share', '/home', '/hashtag/', '/i/', '/search', '/login'],
            self::LinkedIn => ['/sharearticle', '/sharing/', '/sharer', '/shareofferservice',
                '/cws/share', '/uas/login', '/signup', '/shareurl'],
            self::Pinterest => ['/pin/create', '/pin/'],
            self::WhatsApp => ['/send'],
            self::YouTube => ['/watch', '/embed/', '/playlist', '/results', '/shorts/', '/redirect', '/feed/'],
            self::Instagram => ['/p/', '/reel/', '/reels/', '/explore/', '/accounts/', '/stories/', '/tv/'],
            self::TikTok => ['/share', '/embed', '/video/'],
            self::Threads => ['/intent/'],
            self::GitHub => ['/login', '/signup', '/search', '/orgs/', '/sponsors/'],
            self::Telegram => ['/share'],
            self::Spotify => ['/embed'],
            default => [],
        };
    }

    /**
     * Haalt de handle uit het pad, of null als dit pad geen profiel is.
     *
     * Dit is het derde en scherpste filter: het pad moet exact een vorm hebben
     * die dit platform voor een profiel gebruikt. Een kaal pad telt nooit: een
     * link naar facebook.com zonder meer is een icoontje in een voettekst en
     * geen profiel.
     */
    public function handleFrom(string $path): ?string
    {
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn ($s) => $s !== ''));

        if ($segments === []) {
            return null;
        }

        $handle = match ($this) {
            self::LinkedIn => count($segments) >= 2
                && in_array(strtolower($segments[0]), ['company', 'in', 'school', 'showcase'], true)
                    ? $segments[1] : null,
            self::YouTube => match (true) {
                str_starts_with($segments[0], '@') => substr($segments[0], 1),
                count($segments) >= 2 && in_array(strtolower($segments[0]), ['channel', 'c', 'user'], true) => $segments[1],
                default => null,
            },
            self::TikTok, self::Threads => str_starts_with($segments[0], '@') ? substr($segments[0], 1) : null,
            self::Bluesky => count($segments) >= 2 && strtolower($segments[0]) === 'profile' ? $segments[1] : null,
            self::Snapchat => count($segments) >= 2 && strtolower($segments[0]) === 'add' ? $segments[1] : null,
            self::Xing => count($segments) >= 2 && in_array(strtolower($segments[0]), ['pages', 'profile'], true)
                ? $segments[1] : null,
            self::Spotify => count($segments) >= 2
                && in_array(strtolower($segments[0]), ['artist', 'user', 'show'], true) ? $segments[1] : null,
            self::WhatsApp => $segments[0],
            self::Mastodon => str_starts_with($segments[0], '@') ? substr($segments[0], 1) : null,
            // Facebook, Instagram, X, Pinterest, GitHub, Vimeo, Telegram: het
            // eerste segment is de naam.
            default => $segments[0],
        };

        if ($handle === null || $handle === '') {
            return null;
        }

        // Nog een vormcontrole, want hierboven glipt "over-ons" ook door.
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._\-]{0,63}$/', $handle)) {
            return null;
        }

        return $handle;
    }

    /** @return list<self> */
    public static function withHosts(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $p): bool => $p->hosts() !== []));
    }
}
