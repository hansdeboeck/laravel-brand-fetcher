<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Opslag
    |--------------------------------------------------------------------------
    |
    | Per domein komt er een map met twee bestanden: logo.webp en detail.json.
    | Enkel de disknaam staat hier, nooit de driver: de app beslist of daar de
    | lokale schijf of s3 achter zit. Voor s3 is `league/flysystem-aws-s3-v3`
    | nodig in de app; dit package eist die driver bewust niet.
    */
    'storage' => [
        'disk' => env('BRAND_FETCHER_DISK', 'local'),
        'folder' => env('BRAND_FETCHER_FOLDER', 'hans/brand'),
        // null laat de standaardzichtbaarheid van de disk staan. Op s3 zet je
        // dit op 'public' als je de bestanden rechtstreeks wil uitserveren.
        'visibility' => env('BRAND_FETCHER_VISIBILITY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Uitvoer
    |--------------------------------------------------------------------------
    */

    // De zijde van het vierkante beeld. De padvorm kent een bestand per domein,
    // dus een afwijkende maat vraagt eerst een andere padvorm.
    'size' => (int) env('BRAND_FETCHER_SIZE', 128),

    // Kwaliteit voor de lossy variant. 82 is de grens waarboven je bij 128px
    // niets meer ziet en het bestand wel groeit.
    'quality' => (int) env('BRAND_FETCHER_QUALITY', 82),

    // auto codeert lossy en lossless allebei en houdt de kleinste. Gemeten op
    // een vlak logo: 228 bytes lossless tegen 878 lossy. Op een foto andersom.
    'webp_mode' => env('BRAND_FETCHER_WEBP_MODE', 'auto'),

    // Uniforme of doorzichtige randen wegsnijden voor het verkleinen, zodat een
    // logo met veel lucht eromheen niet als postzegel in het vierkant eindigt.
    'trim' => (bool) env('BRAND_FETCHER_TRIM', true),
    'trim_threshold' => (int) env('BRAND_FETCHER_TRIM_THRESHOLD', 8),

    /*
    | Waarmee de lucht rond het logo opgevuld wordt.
    |
    | edge         de hoofdkleur aan de rand van de bron, en wit als de rand er
    |              geen heeft. Een logo dat zijn eigen achtergrond meebrengt
    |              wordt zo een effen tegel in plaats van een band die zweeft.
    | transparent  de lucht doorzichtig laten, zoals tot en met v1.1.
    |
    | Met edge levert dit package nooit nog een doorzichtig beeld af, ook niet
    | voor een logo dat zelf transparant was. Wie het op een donkere achtergrond
    | zet, is met transparent beter af.
    */
    'pad' => env('BRAND_FETCHER_PAD', 'edge'),

    // De terugval als er niets bruikbaars gevonden is: de beginletter op een
    // gekleurde schijf. Staat dit uit, dan komt er helemaal geen bestand.
    'monogram' => (bool) env('BRAND_FETCHER_MONOGRAM', true),

    // null gebruikt de meegeleverde Lato-subset in resources/fonts.
    'font_path' => env('BRAND_FETCHER_FONT_PATH'),

    /*
    |--------------------------------------------------------------------------
    | Verlooptijden
    |--------------------------------------------------------------------------
    |
    | Geen van deze mag onder browser_max_age zakken: dan crawlen we vaker dan
    | iemand het resultaat kan zien. Het package klemt ze daar zelf op.
    */

    // Een bedrijfslogo verandert eens in de paar jaar.
    'ttl_ok' => (int) env('BRAND_FETCHER_TTL_OK', 2592000),

    // Een gemiste kans die we graag repareren: de site kan alsnog een favicon
    // krijgen. Toch ruim boven de dag, want meestal blijft het zoals het is.
    'ttl_monogram' => (int) env('BRAND_FETCHER_TTL_MONOGRAM', 604800),

    // De vluchtigste toestand: dns-storing, verlopen certificaat, een firewall
    // die ons wegstuurde. Bij herhaald falen verdubbelt dit vanzelf.
    'ttl_error' => (int) env('BRAND_FETCHER_TTL_ERROR', 86400),

    // Een host die op een prive-adres uitkomt. Dns kan wijzigen, maar zelden.
    'ttl_blocked' => (int) env('BRAND_FETCHER_TTL_BLOCKED', 604800),

    // Wat het package de app aanraadt als Cache-Control mee te sturen.
    'browser_max_age' => (int) env('BRAND_FETCHER_BROWSER_MAX_AGE', 86400),

    // Voor een antwoord dat nog geen antwoord is: een domein dat in de wacht
    // staat of een site die net niet te bereiken was. Een volle dag een grijs
    // vierkant tonen terwijl het echte logo een kwartier later klaarstaat, is
    // precies het gedrag waardoor mensen een dienst gaan wantrouwen.
    'pending_max_age' => (int) env('BRAND_FETCHER_PENDING_MAX_AGE', 300),

    /*
    |--------------------------------------------------------------------------
    | Wat er gebeurt bij een misser
    |--------------------------------------------------------------------------
    |
    | fetch     haalt ter plaatse op, binnen het budget
    | monogram  zet meteen een monogram klaar en markeert het domein als pending
    | defer     schrijft enkel het merkteken en geeft niets terug
    |
    | Op gedeelde hosting zonder wachtrij hoort dit op monogram te staan: dan
    | wordt geen enkel webverzoek traag en doet de verversopdracht het werk.
    */
    'on_miss' => env('BRAND_FETCHER_ON_MISS', 'monogram'),

    /*
    | Een domein dat in de wacht komt ook meteen op de queue zetten, zodat het
    | echte logo er binnen seconden staat in plaats van bij de volgende ronde
    | van brand-fetcher:refresh.
    |
    | Ligt er geen echte queue klaar, dus met sync of null als verbinding, dan
    | gebeurt er niets: met sync zou de opdracht in het webverzoek zelf draaien
    | en dat is juist wat on_miss op monogram voorkomt. De verversopdracht
    | blijft in alle gevallen het vangnet.
    */
    'queue' => (bool) env('BRAND_FETCHER_QUEUE', true),

    /*
    | Hoe lang hetzelfde domein na een opdracht met rust gelaten wordt.
    |
    | Een beeld hangt in een img-tag en wordt per paginaweergave opgevraagd.
    | Zonder afkoelperiode zou elke bezoeker van dezelfde pagina dezelfde
    | opdracht opnieuw op de rij zetten. Op nul staat de bewaking uit.
    */
    'queue_cooldown' => (int) env('BRAND_FETCHER_QUEUE_COOLDOWN', 300),

    /*
    |--------------------------------------------------------------------------
    | Grenzen
    |--------------------------------------------------------------------------
    */

    // Totaalbudget voor een ophaling, inclusief alle downloads.
    'budget_ms' => (int) env('BRAND_FETCHER_BUDGET_MS', 4000),

    'connect_timeout' => (float) env('BRAND_FETCHER_CONNECT_TIMEOUT', 2),
    'html_timeout' => (float) env('BRAND_FETCHER_HTML_TIMEOUT', 3),
    'asset_timeout' => (float) env('BRAND_FETCHER_ASSET_TIMEOUT', 3),

    'html_max_bytes' => (int) env('BRAND_FETCHER_HTML_MAX_BYTES', 524288),
    'manifest_max_bytes' => (int) env('BRAND_FETCHER_MANIFEST_MAX_BYTES', 65536),
    'asset_max_bytes' => (int) env('BRAND_FETCHER_ASSET_MAX_BYTES', 2097152),

    'max_redirects' => (int) env('BRAND_FETCHER_MAX_REDIRECTS', 3),
    'max_downloads' => (int) env('BRAND_FETCHER_MAX_DOWNLOADS', 3),

    // Poort tegen decompressiebommen: een truecolor-beeld kost breedte maal
    // hoogte maal vier bytes, en dat is geheugen dat geen try/catch opvangt.
    'max_source_pixels' => (int) env('BRAND_FETCHER_MAX_SOURCE_PIXELS', 16777216),
    'max_source_edge' => (int) env('BRAND_FETCHER_MAX_SOURCE_EDGE', 6000),

    // Bereikt een kandidaat deze harde score, dan stoppen we met zoeken.
    'good_enough_score' => (int) env('BRAND_FETCHER_GOOD_ENOUGH', 190),

    /*
    |--------------------------------------------------------------------------
    | Netwerk en veiligheid
    |--------------------------------------------------------------------------
    */

    // Zet hier een contactadres in: de beheerder van een site die wij bezoeken
    // hoort te kunnen zien wie we zijn en waar hij ons bereikt.
    'user_agent' => env(
        'BRAND_FETCHER_USER_AGENT',
        'Mozilla/5.0 (compatible; BrandFetcher/1.0; +https://deboeck.dev/)',
    ),

    // Zonder tls halen we niets op, tenzij dit uitdrukkelijk aan staat.
    'allow_http' => (bool) env('BRAND_FETCHER_ALLOW_HTTP', false),

    // Pint de host op het adres dat net gecontroleerd is. Zonder pinnen blijft
    // er een venster open tussen de controle en de verbinding waarin dns naar
    // een ander adres kan wijzen. Enkel uitzetten als een proxy ermee botst.
    'pin_dns' => (bool) env('BRAND_FETCHER_PIN_DNS', true),

    // UITSLUITEND voor tests. Laat prive-adressen toe en opent dus de deur naar
    // het interne netwerk. Nooit aanzetten op een server die verzoeken van
    // buiten verwerkt.
    'allow_private_hosts' => (bool) env('BRAND_FETCHER_ALLOW_PRIVATE_HOSTS', false),

    // De robots.txt van het domein respecteren voor we iets ophalen.
    'respect_robots' => (bool) env('BRAND_FETCHER_RESPECT_ROBOTS', true),

    /*
    |--------------------------------------------------------------------------
    | Sociale profielen
    |--------------------------------------------------------------------------
    */

    'social' => (bool) env('BRAND_FETCHER_SOCIAL', true),
    'max_profiles' => (int) env('BRAND_FETCHER_MAX_PROFILES', 12),

    /*
    |--------------------------------------------------------------------------
    | De avatar van een sociale bedrijfspagina als logobron
    |--------------------------------------------------------------------------
    |
    | Levert de site zelf niets bruikbaars, dan is de avatar van de
    | bedrijfspagina vaak het beste logo dat publiek te vinden is: vierkant,
    | bijgesneden en door de eigenaar zelf gekozen. Hij dingt gewoon mee met de
    | rest en wint alleen op de meting, dus een echt app-icoon van de site
    | blijft voorgaan.
    |
    | Deze bronnen werken alleen als social hierboven aan staat: zonder
    | gevonden profielen is er geen pagina om te bekijken. Beide praten
    | rechtstreeks met het platform zelf, er komt geen tussenpartij aan te pas.
    */

    // Kost een json-verzoek van een paar honderd byte aan graph.facebook.com
    // per domein waar een pagina van gevonden is.
    'facebook_logo' => (bool) env('BRAND_FETCHER_FACEBOOK_LOGO', true),

    // Wat we bij graph opvragen. Vierhonderd levert in de praktijk 480 pixels,
    // en dat is de zoete plek van sizeBonus: 256 tot 511 telt zwaarder dan 512
    // en meer. Groter vragen kost dus bandbreedte en scoort lager.
    'facebook_width' => (int) env('BRAND_FETCHER_FACEBOOK_WIDTH', 400),

    // Kost een extra verzoek naar www.linkedin.com. Daar wordt alleen de kop
    // van gelezen, dus ongeveer dertien kilobyte van een pagina die er bijna
    // vijfhonderd telt.
    'linkedin_logo' => (bool) env('BRAND_FETCHER_LINKEDIN_LOGO', true),

    // De og:image staat ruim voor </head>, waar SafeHttp vanzelf stopt. Dit is
    // de vangrail voor als linkedin die tag ooit verplaatst.
    'linkedin_max_bytes' => (int) env('BRAND_FETCHER_LINKEDIN_MAX_BYTES', 32768),

    // Korter dan asset_timeout: een avatar is een omweg binnen hetzelfde
    // budget, en een omweg hoort als eerste te sneuvelen als de tijd op raakt.
    'social_timeout' => (float) env('BRAND_FETCHER_SOCIAL_TIMEOUT', 2),

    /*
    |--------------------------------------------------------------------------
    | Verversen
    |--------------------------------------------------------------------------
    |
    | Ruim onder de tijdslimiet van een webverzoek, want op hosting zonder echte
    | cron wordt schedule:run over http aangeroepen en telt die limiet ook hier.
    */
    'refresh_budget_ms' => (int) env('BRAND_FETCHER_REFRESH_BUDGET_MS', 25000),
    'refresh_max' => (int) env('BRAND_FETCHER_REFRESH_MAX', 15),
];
