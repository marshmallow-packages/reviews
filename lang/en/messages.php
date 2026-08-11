<?php

declare(strict_types=1);

return [

    /*
     * Every line here is written for the developer running
     * `php artisan reviews:doctor`, not for a visitor. Nothing in this file is
     * rendered on a page.
     */

    'doctor' => [

        'title' => 'Review collection setup check.',

        'labels' => [
            'package' => 'Package',
            'default_provider' => 'Default provider',
            'active_providers' => 'Active providers',
            'consent' => 'Consent callback',
            'delivery_date' => 'Estimated delivery date',
            'events' => 'Event listening',
            'queue' => 'Queue',
        ],

        'columns' => [
            'provider' => 'Provider',
            'configured' => 'Configured',
            'capabilities' => 'Capabilities',
        ],

        'capabilities' => [
            'send' => 'Send',
            'opt_in' => 'Show opt-in',
            'badge' => 'Show badge',
            'import' => 'Import',
            'respond' => 'Respond',
            'link' => 'Link',
        ],

        'yes' => 'yes',
        'no' => 'no',

        'enabled' => 'enabled',
        'disabled' => 'the master switch is off, so nothing below is active',

        'none_active' => 'none, so nothing is sent and nothing renders',
        'provider_unresolved' => '[:provider] could not be resolved: :message',

        'consent_configured' => 'configured, client side rendering is gated on it',
        'consent_missing' => 'not configured, which is fine while no provider renders in the browser',
        'consent_missing_with_opt_in' => ':providers renders in the visitor\'s browser, sets cookies and transfers an email address, so it should be gated. Set the consent callable in config/reviews.php.',

        'delivery_date_configured' => 'resolver configured',
        'delivery_date_missing' => 'no resolver configured, which only the google provider needs',
        'delivery_date_required' => 'the google provider is active without one, so its opt-in will never render. Set the estimated_delivery_date callable in config/reviews.php, or implement reviewEstimatedDeliveryDate() on the order.',

        'events_disabled' => 'disabled, invitations are sent from your own code',
        'events_enabled' => 'enabled, :count event(s) bound: :events',
        'events_without_listeners' => 'enabled, but no events are bound so nothing ever triggers an invitation',

        'queue' => 'connection :connection, queue :queue',
        'default_queue' => 'application default',

        'problems' => ':count problem(s) need attention.',
        'warnings' => ':count thing(s) worth a look, nothing broken.',
        'healthy' => 'Everything checks out.',

    ],

];
