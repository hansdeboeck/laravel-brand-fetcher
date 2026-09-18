# laravel-brand-fetcher

Haalt het logo van een domein op bij dat domein zelf en levert het als vierkante
webp van 128 pixels. De sociale profielen die op dezelfde voorpagina staan komen
er gratis bij: een bezoek aan andermans site bedient allebei.

Geen upstream-dienst, geen sleutel, geen databank. Wat het nodig heeft is gd met
webp-ondersteuning en een Laravel-disk om op te schrijven.

## Demo

Op [www.deboeck.dev/merk](https://www.deboeck.dev/merk) staat een proefpagina die
op dit package draait: tik een domein in en je ziet wat eruit komt, het logo en de
sociale profielen naast elkaar. Onder dat formulier staan de twee open eindpunten
van diezelfde site, zonder sleutel en zonder registratie:

| Eindpunt | Wat je krijgt | Rem |
|---|---|---|
| `GET https://api.deboeck.dev/v1/brand/{domein}` | het logo als vierkante webp van 128 pixels | 60 per minuut per ip |
| `GET https://api.deboeck.dev/v1/brand/{domein}/social` | de profielen als json | 5 per minuut per ip |

```bash
curl -s https://api.deboeck.dev/v1/brand/kringwinkel.be/social
```

Het beeld kan rechtstreeks in de opmaak:

```html
<img src="https://api.deboeck.dev/v1/brand/kringwinkel.be" width="128" height="128" alt="Kringwinkel">
```

Die eindpunten horen bij die applicatie en niet bij dit package: de routes, de rem
en het bot-slot staan daar.

## Requirements

PHP 8.3 of hoger met **gd (met webp), dom, intl en json**, en Laravel 12 of 13.
Laravel 11 wordt niet meer ondersteund.

Of deze server webp kan schrijven, controleer je zo:

```bash
php --ri gd | grep -iE 'webp|freetype'
```

`WebP Support => enabled` is verplicht. `FreeType Support => enabled` is nodig
voor het monogram; zonder dat geeft de terugval een eerlijke fout in plaats van
een lelijk beeld.

## Installatie

```bash
composer require hansdeboeck/laravel-brand-fetcher
php artisan vendor:publish --tag=brand-fetcher-config
```

## Gebruik

```php
use HansDeBoeck\BrandFetcher\Facades\BrandFetcher;

$logo = BrandFetcher::logo('kringwinkel.be');

$logo->found;        // true
$logo->status;       // 'ok'
$logo->source;       // 'apple-touch-icon'
$logo->etag;         // sha1 van de bytes, voor een 304 zonder de opslag te raken
$logo->contents();   // de rauwe webp-bytes, pas op dit moment van de schijf
$logo->cacheHeaders();
// ['Cache-Control' => 'public, max-age=86400', 'ETag' => '"..."', 'Last-Modified' => '...']

$profiel = BrandFetcher::profile('kringwinkel.be');

$profiel->urls();
// ['facebook' => 'https://facebook.com/...', 'linkedin' => 'https://linkedin.com/company/...']
$profiel->for('instagram')?->handle;
```

Uitserveren gaat op elke disk hetzelfde:

```php
return response($logo->contents(), 200, ['Content-Type' => 'image/webp'] + $logo->cacheHeaders());
```

Staat de disk publiek (s3, of een lokale disk met een `url`), dan geeft
`$logo->url()` een adres terug en kan php er helemaal tussenuit.

Dit package registreert zelf **geen routes**. Wat een eindpunt mag, hoe hard de
rem staat en wie er langs mag, hoort in de applicatie thuis.

## Wat er gebeurt bij een onbekend domein

Dat hangt af van `on_miss`, en die keuze doet ertoe:

| Stand | Gedrag | Wanneer |
|---|---|---|
| `monogram` (standaard) | meteen een monogram, het domein komt in de wacht, `brand-fetcher:refresh` haalt het echte logo op | gedeelde hosting, en overal waar het beeld in een `<img>` van een vreemde site hangt |
| `fetch` | ter plaatse ophalen binnen het budget | scripts, beheerschermen, de verversopdracht |
| `defer` | alleen het merkteken, geen beeld | als de applicatie zelf een plaatshouder toont |

Met `monogram` wordt geen enkel webverzoek traag, ook niet als iemand een pagina
met vijftig onbekende domeinen opent. Een bestand met de stand `pending` is de
wachtrij: dat kost een klein bestandje en geen enkele infrastructuur.

Draait er een queue-werker, dan gaat dat domein er ook meteen op en staat het
echte logo er binnen seconden in plaats van bij de volgende verversronde. Dat
staat aan met `queue`. Is de verbinding `sync` of `null`, dan gebeurt er niets:
met `sync` zou die opdracht in het webverzoek zelf draaien, en dat is juist wat
`monogram` voorkomt. `brand-fetcher:refresh` blijft in alle gevallen het
vangnet, dus zonder queue werkt alles zoals voorheen.

Dat geldt niet alleen voor een onbekend domein: wie het beeld of de json opvraagt
van een domein dat nog in de wacht staat of waarvan de verlooptijd om is, zet
diezelfde opdracht in gang. Het antwoord blijft meteen komen, met wat er nu
staat; de volgende bezoeker ziet het verse logo. Hetzelfde domein komt hoogstens
een keer per `queue_cooldown` op de rij, want een beeld in een `<img>` wordt per
paginaweergave opgevraagd.

## Verversen

```bash
php artisan brand-fetcher:refresh                  # wat in de wacht staat, daarna wat verlopen is
php artisan brand-fetcher:refresh acme.be          # een bepaald domein, nu
php artisan brand-fetcher:refresh acme.be --delete # weghalen en weg houden
```

In de planner:

```php
Schedule::command('brand-fetcher:refresh')->everyFifteenMinutes()->withoutOverlapping(10);
```

De opdracht stopt altijd zelf binnen `refresh_budget_ms`. Loopt de planner over
http omdat de server geen echte cron heeft, dan telt de tijdslimiet van een
webverzoek ook hier, en dan is dat budget wat een afgekapte ronde voorkomt.

## Opslag

Per domein een map met twee bestanden:

```
hans/brand/acme.be/logo.webp
hans/brand/acme.be/detail.json
```

`detail.json` is vier dingen tegelijk: de cache van de metadata, de verloopklok
(de **mtime** van dat bestand is de autoriteit), de bron van de sociale
profielen, en het geheugen van wat er misging. Er staat een versienummer in;
een onbekende versie telt als afwezig en wordt gewoon opnieuw opgehaald, zodat
er nooit een migratie nodig is.

Het beeld wordt **eerst** geschreven en `detail.json` als laatste. Dat laatste
bestand is het commit-record: mislukt het, dan telt de entry als afwezig en
wordt alles overschreven. Andersom zou erger zijn, want dan gelooft een lezer
dat er een logo is terwijl het bestand ontbreekt.

Een internationaal domein komt als punycode in de mapnaam (`xn--mnchen-3ya.de`),
met de leesbare vorm als veld erin. Dat is pure ascii en dus overal veilig, en
het voorkomt dat macOS en Linux dezelfde naam verschillend opslaan.

**s3** werkt zodra de disk bestaat; dit package praat alleen met de
filesystem-factory en eist de driver bewust niet. In de applicatie is daarvoor
`composer require league/flysystem-aws-s3-v3` nodig.

Dit schaalt prima tot ongeveer tienduizend domeinen. Daarboven hoort er een
echte index voor, en die past niet in een package dat geen databank mag eisen.

## Configuratie

| Sleutel | Standaard | Doel |
|---|---|---|
| `storage.disk` | `local` | welke Laravel-disk |
| `storage.folder` | `hans/brand` | de map daarbinnen |
| `size` | `128` | de zijde van het vierkante beeld |
| `pad` | `edge` | de lucht rond het logo opvullen met de randkleur, of `transparent` |
| `on_miss` | `monogram` | zie hierboven |
| `queue` | `true` | een domein in de wacht ook op de queue zetten, als er een echte is |
| `ttl_ok` | 30 dagen | hoe lang een gevonden logo meegaat |
| `ttl_monogram` | 7 dagen | een site kan alsnog een favicon krijgen |
| `ttl_error` | 1 dag | verdubbelt bij herhaald falen |
| `browser_max_age` | 1 dag | wat we een afnemer aanraden |
| `budget_ms` | 4000 | totaal voor een ophaling, inclusief downloads |
| `max_downloads` | 3 | hoeveel kandidaten we hoogstens halen |
| `user_agent` | zie config | **zet hier een contactadres in** |
| `pin_dns` | `true` | de verbinding pinnen op het gecontroleerde adres |
| `social` | `true` | de profielen mee ophalen |
| `facebook_logo` | `true` | de avatar van de facebook-bedrijfspagina meenemen |
| `linkedin_logo` | `true` | de avatar van de linkedin-bedrijfspagina meenemen |

Alle sleutels staan in [`config/brand-fetcher.php`](config/brand-fetcher.php) met
een env-naam in de vorm `BRAND_FETCHER_*`.

## Hoe het logo gekozen wordt

Kandidaten komen uit `link rel=icon` en `apple-touch-icon`, uit het webmanifest,
uit `og:image` en `twitter:image`, uit `Organization.logo` in de json-ld, en als
vangnet uit `/favicon.ico` en `/apple-touch-icon.png`.

Daar komt de **avatar van de bedrijfspagina** bij, als er een facebook- of
linkedin-profiel gevonden is. Voor een site die zelf niets in zijn head zet, is
dat vaak het beste logo dat publiek te vinden is: vierkant, bijgesneden en door
de eigenaar zelf gekozen. Hij dingt gewoon mee en wint alleen op de meting, dus
een echt app-icoon van de site zelf blijft voorgaan; tegen een favicon van
tweeendertig pixels wint hij. Beide bronnen praten rechtstreeks met het platform,
er komt geen tussenpartij aan te pas.

Het scoren gebeurt in twee fasen, en dat onderscheid is de kern:

- de **papieren score** gebruikt wat de pagina beweert en bepaalt alleen de
  volgorde waarin we downloaden;
- de **harde score** gebruikt de gemeten afmetingen en bepaalt de keuze.

Dat is nodig omdat `sizes` een verklaring is en geen meting: sites leveren met
grote regelmaat een bestand van zestien pixels met `sizes="32x32"` erbij.
Vierkant weegt zwaar, een liggende socialekaart weegt licht, en transparantie
telt mee omdat een logo met alfa vrijwel altijd een echt logo is.

Op een punt geloven we de verklaring wel. Zegt een bron zelf dat zijn icoon
niet vierkant is, met een maat als `512x256` in het manifest, dan is dat een
bewering in het eigen nadeel en dus geen grootspraak. Dat icoon verliest zijn
voorsprong in de volgorde, en de avatar van de bedrijfspagina wordt eerst
gedownload: die is per definitie vierkant. Was die avatar goed genoeg, dan
wordt het liggende beeld niet meer opgehaald.

Vierkant maken gebeurt door te **passen** binnen het vierkant. Nooit uitrekken
en nooit het beeldmerk bijsnijden.

De lucht eromheen krijgt de **hoofdkleur van de rand** van de bron. Een logo dat
zijn eigen achtergrond meebrengt, een liggend beeld op een gekleurd vlak, wordt
zo een effen tegel in plaats van een band die in het niets zweeft. Heeft die
rand geen hoofdkleur, omdat het logo op transparant staat of omdat de rand een
foto is, dan wordt de vulling wit. Een beeld dat het vierkant zelf volmaakt,
merkt er niets van.

Daardoor levert dit package standaard geen doorzichtig beeld meer af. Wie het
logo op een donkere achtergrond zet, zet `pad` op `transparent`; dan blijft de
lucht doorzichtig zoals tot en met v1.1.

**Svg** wordt niet gerasteriseerd: gd kan het niet, en een svg-parser loslaten op
bytes van een vreemde server is een aanvalsoppervlak dat dit package niet wil
openen. De url wordt wel bewaard in `detail.json`, voor wie er zelf raad mee weet.

## Uitgaande verbindingen

De afnemer kiest het domein en deze server gaat het bezoeken. Daarom:

- het domein moet door een **allowlist**, niet door een schoonmaakbeurt: alleen
  `a-z0-9.-`, minstens twee labels, en het laatste label alfabetisch. Dat sluit
  in een keer `localhost`, `127.0.0.1`, `0177.0.0.1` en `2130706433` uit;
- na dns-resolutie wordt **elk** adres gecontroleerd tegen de prive-, loopback-,
  link-local- en documentatiebereiken, inclusief `100.64.0.0/10` en een ipv4-adres
  dat als ipv6 vermomd is;
- omleidingen worden **zelf** gehopt en per stap opnieuw gecontroleerd. Guzzle
  roept `on_redirect` wel aan maar negeert wat die teruggeeft, dus daarmee is een
  omleiding niet tegen te houden;
- de verbinding wordt gepind op het adres dat net goedgekeurd is;
- de parser draait met `LIBXML_NONET`, anders zou libxml zelf een externe dtd
  kunnen ophalen waar de pagina naar wijst.

Staan `facebook_logo` of `linkedin_logo` aan, dan raakt een ophaling ook
`graph.facebook.com`, `*.fbcdn.net`, `www.linkedin.com` en `media.licdn.com`.
Wie met een egress-allowlist werkt moet die vier kennen, of de twee sleutels
uitzetten. Beide bronnen worden alleen bekeken als er een profiel gevonden is en
`social` aanstaat.

## Testen

```bash
composer test
```

De beeldfixtures worden in php gemaakt en staan dus niet als binair bestand in de
repo.

## Het lettertype van het monogram

`resources/fonts/monogram.ttf` is een subset van **Lato Black** (SIL Open Font
License 1.1, zie `resources/fonts/OFL.txt`) met alleen hoofdletters, cijfers en
de gangbare accenten. Opnieuw maken gaat zo:

```bash
pyftsubset Lato-Black.ttf --output-file=resources/fonts/monogram.ttf \
  --unicodes="U+0020,U+0026,U+003F,U+0030-0039,U+0041-005A,U+00C0-00DD,U+0100-017F" \
  --layout-features="" --no-hinting --desubroutinize
```

De code staat onder MIT, het lettertype onder de OFL. Die licentietekst hoort mee
te reizen met het bestand.

## Merken

De logo's die dit package ophaalt zijn merken van hun eigenaars. Ze worden
opgehaald en getoond om een domein te herkennen, verder niets: er wordt niets
aan gewijzigd, niets uitgerekt en niets bijgesneden. Zet een contactadres in
`user_agent`, zodat de beheerder van een site die je bezoekt weet wie je bent en
waar hij je bereikt, en zorg dat `brand-fetcher:refresh --delete` een knop is die
iemand echt kan laten indrukken.
