<?php

declare(strict_types=1);

use HansDeBoeck\BrandFetcher\Discovery\AvatarDiscoverer;
use HansDeBoeck\BrandFetcher\Net\Budget;
use HansDeBoeck\BrandFetcher\Net\SafeHttp;
use HansDeBoeck\BrandFetcher\Social\SocialProfile;
use Illuminate\Support\Facades\Http;

/*
| De vertaling van een profiel naar een beeld-url. De ophaling zelf zit hier
| niet in: die doet dezelfde downloadlus als voor elke andere kandidaat.
*/

function avatars(array $config = []): AvatarDiscoverer
{
    return new AvatarDiscoverer(app(SafeHttp::class), $config + config('brand-fetcher'));
}

function profiel(string $platform, string $url, ?string $handle): SocialProfile
{
    return new SocialProfile(platform: $platform, url: $url, handle: $handle, source: 'jsonld');
}

/** De eerste kandidaat, of null als er niets gevonden werd. */
function eerste(array $profielen, array $config = []): mixed
{
    return avatars($config)->discover($profielen, new Budget(4000))[0] ?? null;
}

it('vult de graph-url met de handle en de gevraagde maat', function (): void {
    Http::fake(['https://graph.facebook.com/*' => Http::response(
        ['data' => ['width' => 480, 'height' => 480, 'is_silhouette' => false,
            'url' => 'https://scontent.xx.fbcdn.net/v/logo.png']],
    )]);

    $kandidaat = eerste(['facebook' => profiel('facebook', 'https://facebook.com/Acme', 'Acme')]);

    expect($kandidaat->source)->toBe('facebook')
        ->and($kandidaat->declaredSize)->toBe(480)
        ->and($kandidaat->url)->toBe('https://scontent.xx.fbcdn.net/v/logo.png');

    Http::assertSent(fn ($v): bool => $v->url()
        === 'https://graph.facebook.com/Acme/picture?width=400&height=400&redirect=false');
});

it('neemt de afmetingen van graph over als meting en niet als bewering', function (): void {
    Http::fake(['https://graph.facebook.com/*' => Http::response(
        ['data' => ['width' => 320, 'height' => 200, 'is_silhouette' => false,
            'url' => 'https://scontent.xx.fbcdn.net/v/logo.png']],
    )]);

    expect(eerste(['facebook' => profiel('facebook', 'https://facebook.com/Acme', 'Acme')])->declaredSize)
        ->toBe(320);
});

it('weigert een facebook-antwoord dat geen echt logo is', function (array $data): void {
    Http::fake(['https://graph.facebook.com/*' => Http::response(['data' => $data])]);

    expect(eerste(['facebook' => profiel('facebook', 'https://facebook.com/Acme', 'Acme')]))->toBeNull();
})->with([
    'silhouet' => [['width' => 480, 'height' => 480, 'is_silhouette' => true,
        'url' => 'https://scontent.xx.fbcdn.net/v/logo.png']],
    'vreemde host' => [['width' => 480, 'height' => 480, 'is_silhouette' => false,
        'url' => 'https://kwaadaardig.example/logo.png']],
    'host die enkel lijkt op fbcdn' => [['width' => 480, 'height' => 480, 'is_silhouette' => false,
        'url' => 'https://fbcdn.net.kwaadaardig.example/logo.png']],
    'geen url' => [['width' => 480, 'height' => 480, 'is_silhouette' => false]],
]);

/*
| De canonieke vorm die we opslaan is linkedin.com zonder www, maar juist die
| vorm levert een pagina zonder og:image op. De www moet er dus weer voor.
*/
it('zet www voor de linkedin-url en decodeert de entiteiten', function (): void {
    Http::fake(['https://www.linkedin.com/company/acme-nv/' => Http::response(
        '<meta property="og:image" content="https://media.licdn.com/dms/a/company-logo_200_200/x?e=1&amp;t=abc">',
    )]);

    $kandidaat = eerste(['linkedin' => profiel('linkedin', 'https://linkedin.com/company/acme-nv', 'acme-nv')]);

    expect($kandidaat->source)->toBe('linkedin')
        ->and($kandidaat->declaredSize)->toBe(200)
        // Met &amp; erin geeft licdn een 403: de handtekening klopt dan niet.
        ->and($kandidaat->url)->toBe('https://media.licdn.com/dms/a/company-logo_200_200/x?e=1&t=abc');
});

it('leest de og:image ook als de attributen omgekeerd staan', function (): void {
    Http::fake(['https://www.linkedin.com/company/acme-nv/' => Http::response(
        '<meta content="https://media.licdn.com/a/company-logo_400_400/x" property="og:image">',
    )]);

    expect(eerste(['linkedin' => profiel('linkedin', 'https://linkedin.com/company/acme-nv', 'acme-nv')])->declaredSize)
        ->toBe(400);
});

it('weigert een og:image die niet op het mediadomein van linkedin staat', function (string $url): void {
    Http::fake(['https://www.linkedin.com/company/acme-nv/' => Http::response(
        '<meta property="og:image" content="' . $url . '">',
    )]);

    expect(eerste(['linkedin' => profiel('linkedin', 'https://linkedin.com/company/acme-nv', 'acme-nv')]))->toBeNull();
})->with([
    // Het grijze vierkantje dat linkedin toont voor wie geen logo uploadde.
    'placeholder' => 'https://static.licdn.com/aero-v1/sc/h/cs8pjfgyw96g44ln9r7tct85f',
    'vreemde host' => 'https://kwaadaardig.example/logo.png',
]);

it('bekijkt alleen de padvormen die zonder inloggen te lezen zijn', function (string $pad, bool $verwacht): void {
    Http::fake(['https://www.linkedin.com/*' => Http::response(
        '<meta property="og:image" content="https://media.licdn.com/a/company-logo_200_200/x">',
    )]);

    $gevonden = eerste(['linkedin' => profiel('linkedin', 'https://linkedin.com' . $pad, 'acme-nv')]) !== null;

    expect($gevonden)->toBe($verwacht);
})->with([
    'bedrijf' => ['/company/acme-nv', true],
    'showcase' => ['/showcase/acme-nv', true],
    // Een portret achter een inlogmuur, en sowieso geen merklogo.
    'persoon' => ['/in/acme-nv', false],
    // Antwoordt in het echt met 999, de blokkeerstatus van linkedin.
    'school' => ['/school/acme-nv', false],
    'kaal pad' => ['/acme-nv', false],
]);

it('laat een platform zonder eigen regel met rust', function (): void {
    Http::preventStrayRequests();

    expect(eerste(['instagram' => profiel('instagram', 'https://instagram.com/acme', 'acme')]))->toBeNull();
});

/*
| fromDetailArray() leest een handle terug uit detail.json zonder de vorm nog
| te controleren, en hier gaat hij een url in.
*/
it('weigert een handle die geen handle is', function (?string $handle): void {
    Http::preventStrayRequests();

    expect(eerste(['facebook' => profiel('facebook', 'https://facebook.com/x', $handle)]))->toBeNull();
})->with([
    'pad erin' => '../../etc/passwd',
    'schuine streep' => 'acme/logo',
    'leeg' => '',
    'null' => null,
]);
