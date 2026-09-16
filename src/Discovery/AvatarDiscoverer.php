<?php

declare(strict_types=1);

namespace HansDeBoeck\BrandFetcher\Discovery;

use HansDeBoeck\BrandFetcher\Net\Budget;
use HansDeBoeck\BrandFetcher\Net\SafeHttp;
use HansDeBoeck\BrandFetcher\Social\Platform;
use HansDeBoeck\BrandFetcher\Social\SocialProfile;

/**
 * De avatar van een bedrijfspagina op een sociaal netwerk.
 *
 * Een site die zelf niets in zijn head zet, heeft op facebook of linkedin vaak
 * wel een keurig vierkant merklogo staan: bijgesneden, door de eigenaar zelf
 * gekozen, en meestal beter dan de favicon van zestien pixels die er anders
 * van overblijft.
 *
 * Deze klasse levert alleen een url. Het beeld zelf wordt door dezelfde
 * downloadlus opgehaald als elke andere kandidaat, zodat het meten, scoren en
 * omzetten op een plek blijft staan.
 *
 * Wat hier per platform aan regels staat, is niet verzonnen maar gemeten. Beide
 * netwerken hebben een manier om je een grijze placeholder in de maag te
 * splitsen in plaats van een logo, en allebei op een andere manier.
 *
 * Let op wat er van beide in detail.json belandt: een ondertekende url met een
 * vervaldatum erin. Dat is een aantekening van waar de bytes vandaan kwamen en
 * geen handvat om later nog eens op te halen.
 */
final class AvatarDiscoverer
{
    /**
     * De padvormen van linkedin die zonder inloggen te lezen zijn.
     *
     * Een toelatingslijst en geen weigerlijst: een vorm die linkedin volgend
     * jaar verzint, hoort niet stilzwijgend meegenomen te worden. /in/ is een
     * persoon achter een inlogmuur, en een portret is sowieso geen logo;
     * /school/ antwoordt met 999, de blokkeerstatus van linkedin.
     */
    private const LINKEDIN_PAGES = ['company', 'showcase'];

    /** Het mediadomein van linkedin. Zie ogImage() voor waarom dat telt. */
    private const LINKEDIN_IMAGE_HOST = 'media.licdn.com';

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly SafeHttp $http,
        private readonly array $config = [],
    ) {}

    /**
     * @param  array<string, SocialProfile>  $profiles
     * @return list<IconCandidate>
     */
    public function discover(array $profiles, Budget $budget): array
    {
        $found = [];

        foreach ($profiles as $profile) {
            $candidate = match (Platform::tryFrom($profile->platform)) {
                Platform::Facebook => $this->fromFacebook($profile, $budget),
                Platform::LinkedIn => $this->fromLinkedIn($profile, $budget),
                default => null,
            };

            if ($candidate !== null) {
                $found[] = $candidate;
            }
        }

        return $found;
    }

    /**
     * Facebook vertelt het gewoon als je het vraagt.
     *
     * Het graph-eindpunt levert zonder sleutel een json van een paar honderd
     * byte met drie dingen die we alledrie nodig hebben: de rechtstreekse
     * url, de echte afmetingen, en is_silhouette. Dat laatste is de vlag voor
     * een pagina zonder eigen profielfoto, en die willen we niet: dat is het
     * generieke poppetje van facebook en geen merk.
     */
    private function fromFacebook(SocialProfile $profile, Budget $budget): ?IconCandidate
    {
        if (! ($this->config['facebook_logo'] ?? true)) {
            return null;
        }

        $id = $this->handle($profile);

        if ($id === null || ! $budget->allows(1.0)) {
            return null;
        }

        /*
        | Vierhonderd vragen levert in de praktijk 480 pixels, en dat is de
        | zoete plek van sizeBonus: 256 tot 511 telt zwaarder dan 512 en meer.
        | Groter opvragen kost dus bandbreedte en levert een lagere score op.
        */
        $width = max(1, (int) ($this->config['facebook_width'] ?? 400));

        $response = $this->http->get(
            'https://graph.facebook.com/' . rawurlencode($id) . '/picture'
                . '?width=' . $width . '&height=' . $width . '&redirect=false',
            $budget,
            (float) ($this->config['social_timeout'] ?? 2),
            (int) ($this->config['manifest_max_bytes'] ?? 65536),
        );

        // Een onbekende pagina geeft hier een 400 met een json-fout. Dat is
        // gewoon een misser en verder niets.
        if (! $response->ok) {
            return null;
        }

        $data = json_decode($response->body, true);

        if (! is_array($data) || ! is_array($data['data'] ?? null)) {
            return null;
        }

        $data = $data['data'];
        $url = $data['url'] ?? null;

        if (($data['is_silhouette'] ?? false) === true || ! is_string($url)) {
            return null;
        }

        /*
        | Het beeld hoort op het cdn van facebook te staan. UrlGuard bewaakt al
        | waar we naartoe verbinden; deze controle gaat over iets anders, en
        | wel dat een vreemd antwoord ons niet het beeld van een derde als
        | "source: facebook" in detail.json laat schrijven.
        */
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if (! str_ends_with($host, '.fbcdn.net')) {
            return null;
        }

        return new IconCandidate(
            url: $url,
            source: 'facebook',
            // Geen bewering maar een meting: graph geeft de echte afmetingen.
            declaredSize: max((int) ($data['width'] ?? 0), (int) ($data['height'] ?? 0)) ?: null,
            mime: 'image/png',
        );
    }

    /**
     * Linkedin zet het logo in de og:image van de bedrijfspagina.
     *
     * Er zitten drie voetangels in. De pagina is bijna een halve megabyte maar
     * de tag staat ruim voor </head>, dus we lezen alleen de kop. Zonder www
     * antwoordt linkedin wel met 200 maar zonder enige og:image. En bedrijven
     * zonder geupload logo krijgen een placeholder op static.licdn.com.
     */
    private function fromLinkedIn(SocialProfile $profile, Budget $budget): ?IconCandidate
    {
        if (! ($this->config['linkedin_logo'] ?? true)) {
            return null;
        }

        $url = $this->linkedInPageUrl($profile);

        if ($url === null || ! $budget->allows(1.0)) {
            return null;
        }

        /*
        | allowPartial omdat het eerste stuk precies is wat we zoeken: zonder
        | die vlag wijst SafeHttp een antwoord af dat een grotere lengte
        | aankondigt dan onze grens, en deze pagina is veel groter dan de kop
        | die we ervan willen.
        */
        $response = $this->http->get(
            $url,
            $budget,
            (float) ($this->config['social_timeout'] ?? 2),
            (int) ($this->config['linkedin_max_bytes'] ?? 32768),
            stopAt: '</head>',
            allowPartial: true,
        );

        if (! $response->ok) {
            return null;
        }

        $image = $this->ogImage($response->body);

        if ($image === null) {
            return null;
        }

        return new IconCandidate(
            url: $image,
            source: 'linkedin',
            declaredSize: $this->renditionSize($image),
            mime: 'image/jpeg',
        );
    }

    /**
     * De url van de bedrijfspagina, met www ervoor.
     *
     * De canonieke vorm die we opslaan is linkedin.com zonder www, want dat is
     * de host waar het platform op uitkomt. Maar juist die vorm levert een
     * pagina zonder og:image op: de meta's staan er alleen bij www. Gemeten op
     * drie van de drie domeinen, dus dit is geen toeval.
     */
    private function linkedInPageUrl(SocialProfile $profile): ?string
    {
        $handle = $this->handle($profile);

        if ($handle === null) {
            return null;
        }

        $path = (string) parse_url($profile->url, PHP_URL_PATH);
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn ($s) => $s !== ''));
        $prefix = strtolower($segments[0] ?? '');

        if (! in_array($prefix, self::LINKEDIN_PAGES, true)) {
            return null;
        }

        return 'https://www.linkedin.com/' . $prefix . '/' . rawurlencode($handle) . '/';
    }

    /**
     * De og:image uit een afgekapte kop.
     *
     * Met een regex en niet met DOMDocument: dit lichaam is een half document
     * en een parser optuigen voor een attribuut kost meer dan het oplevert.
     * Twee patronen, want de volgorde van de attributen ligt niet vast.
     */
    private function ogImage(string $html): ?string
    {
        $patterns = [
            '/<meta[^>]+property=["\']og:image["\'][^>]*content=["\']([^"\']+)["\']/i',
            '/<meta[^>]+content=["\']([^"\']+)["\'][^>]*property=["\']og:image["\']/i',
        ];

        foreach ($patterns as $pattern) {
            if (! preg_match($pattern, $html, $match)) {
                continue;
            }

            /*
            | De url is ondertekend: zonder de exacte t= geeft licdn een 403.
            | In het attribuut staat &amp; en niet &, dus die entiteiten moeten
            | eruit, en verder blijft er niets aan hem gebeuren.
            */
            $url = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');

            // Het echte logo staat op het mediadomein. Een bedrijf zonder
            // geupload logo krijgt er een op static.licdn.com, en dat is het
            // grijze vierkantje van linkedin zelf.
            if (strtolower((string) parse_url($url, PHP_URL_HOST)) !== self::LINKEDIN_IMAGE_HOST) {
                return null;
            }

            return $url;
        }

        return null;
    }

    /** De maat staat in de naam van de uitsnede: company-logo_200_200. */
    private function renditionSize(string $url): ?int
    {
        return preg_match('/logo_(\d+)_\d+/', $url, $match) ? (int) $match[1] : 200;
    }

    /**
     * SocialProfile::fromDetailArray() leest een handle terug uit detail.json
     * zonder de vorm nog te controleren, en hier gaat hij een url in.
     */
    private function handle(SocialProfile $profile): ?string
    {
        $handle = (string) $profile->handle;

        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._\-]{0,63}$/', $handle) ? $handle : null;
    }
}
