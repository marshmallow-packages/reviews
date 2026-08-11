<?php

declare(strict_types=1);

return [

    /*
     * Alle regels hier zijn bedoeld voor de developer die
     * `php artisan reviews:doctor` draait, niet voor een bezoeker. Niets uit
     * dit bestand komt op een pagina terecht.
     */

    'doctor' => [

        'title' => 'Controle van de reviewinstellingen.',

        'labels' => [
            'package' => 'Package',
            'default_provider' => 'Standaardprovider',
            'active_providers' => 'Actieve providers',
            'consent' => 'Toestemmingscallback',
            'delivery_date' => 'Verwachte leverdatum',
            'events' => 'Luisteren naar events',
            'queue' => 'Queue',
        ],

        'columns' => [
            'provider' => 'Provider',
            'configured' => 'Ingesteld',
            'capabilities' => 'Mogelijkheden',
        ],

        'capabilities' => [
            'send' => 'Versturen',
            'opt_in' => 'Opt-in tonen',
            'badge' => 'Badge tonen',
            'import' => 'Importeren',
            'respond' => 'Reageren',
            'link' => 'Link',
        ],

        'yes' => 'ja',
        'no' => 'nee',

        'enabled' => 'ingeschakeld',
        'disabled' => 'de hoofdschakelaar staat uit, dus hieronder is niets actief',

        'none_active' => 'geen, er wordt dus niets verstuurd en niets getoond',
        'provider_unresolved' => '[:provider] kon niet geladen worden: :message',

        'consent_configured' => 'ingesteld, weergave in de browser is hierop afgeschermd',
        'consent_missing' => 'niet ingesteld, wat prima is zolang geen enkele provider in de browser rendert',
        'consent_missing_with_opt_in' => ':providers rendert in de browser van de bezoeker, plaatst cookies en geeft een e-mailadres door. Stel daarom de consent callable in config/reviews.php in.',

        'delivery_date_configured' => 'resolver ingesteld',
        'delivery_date_missing' => 'geen resolver ingesteld, alleen de google provider heeft die nodig',
        'delivery_date_required' => 'de google provider is actief zonder resolver, waardoor de opt-in nooit verschijnt. Stel estimated_delivery_date in config/reviews.php in, of implementeer reviewEstimatedDeliveryDate() op de order.',

        'events_disabled' => 'uitgeschakeld, uitnodigingen verstuur je vanuit je eigen code',
        'events_enabled' => 'ingeschakeld, :count event(s) gekoppeld: :events',
        'events_without_listeners' => 'ingeschakeld, maar er zijn geen events gekoppeld, dus er wordt nooit een uitnodiging gestart',

        'queue' => 'connectie :connection, queue :queue',
        'default_queue' => 'standaard van de applicatie',

        'problems' => ':count probleem(en) vragen aandacht.',
        'warnings' => ':count aandachtspunt(en), niets kapot.',
        'healthy' => 'Alles in orde.',

    ],

];
