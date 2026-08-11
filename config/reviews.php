<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    |
    | Turns the whole package off: no invitations are sent, no opt-in module or
    | badge renders. Useful on staging, where inviting real customers to review
    | a test order is a genuine hazard.
    |
    */

    'enabled' => env('REVIEWS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Default provider
    |--------------------------------------------------------------------------
    |
    | Resolved by Reviews::driver() when no name is given, exactly as Socialite
    | does. Ships as "null" so installing the package changes nothing until you
    | choose a provider on purpose.
    |
    */

    'default' => env('REVIEWS_PROVIDER', 'null'),

    /*
    |--------------------------------------------------------------------------
    | Active providers
    |--------------------------------------------------------------------------
    |
    | The providers that take part in the fan-out used by the queued job and
    | the Blade components. Unlike Socialite, more than one can be active:
    | running Google Customer Reviews for the Google seller rating alongside
    | Kiyoh for the on-site badge and the invitation email is a normal setup.
    |
    | Leave this null to use the default provider alone.
    |
    */

    'active' => null,

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | Credentials per provider. A custom provider registered with
    | Reviews::extend() reads its own key from here by the same convention.
    |
    */

    'providers' => [

        'kiyoh' => [
            // Kiyoh and Klantenvertellen are the same platform on two domains.
            // Use the one your account lives on.
            'base_url' => env('KIYOH_BASE_URL', 'https://www.klantenvertellen.nl'),
            'api_token' => env('KIYOH_API_TOKEN'),
            'location_id' => env('KIYOH_LOCATION_ID'),
            'locale' => env('KIYOH_LOCALE', 'nl'),

            // Days between the order and the invitation email.
            'delay_days' => env('KIYOH_DELAY_DAYS', 3),

            // Push the delay past Saturday and Sunday, so an invitation is not
            // sent into a weekend inbox where it is least likely to be opened.
            'skip_weekends' => env('KIYOH_SKIP_WEEKENDS', true),

            // Public profile URL, used for the badge and the review link.
            'profile_url' => env('KIYOH_PROFILE_URL'),
        ],

        'google' => [
            // Google Merchant Center account id. Without it nothing renders.
            'merchant_id' => env('GOOGLE_MERCHANT_ID'),

            // CENTER_DIALOG, BOTTOM_RIGHT_DIALOG, BOTTOM_LEFT_DIALOG,
            // BOTTOM_TRAY or TOP_BAR.
            'opt_in_style' => env('GOOGLE_OPT_IN_STYLE', 'CENTER_DIALOG'),

            // BOTTOM_RIGHT, BOTTOM_LEFT, INLINE.
            'badge_position' => env('GOOGLE_BADGE_POSITION', 'BOTTOM_RIGHT'),

            // Language of the opt-in module itself. Null follows the app locale.
            'language' => env('GOOGLE_REVIEWS_LANGUAGE'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Consent
    |--------------------------------------------------------------------------
    |
    | Gates client side rendering only, never server side sending.
    |
    | The distinction is deliberate. Posting an invitation to a provider's API
    | is a controller to processor transfer under your data processing
    | agreement, not a cookie placement, so a cookie banner has no bearing on
    | it. Google's opt-in module, on the other hand, loads Google JavaScript,
    | sets cookies and transfers the customer's email address from the
    | visitor's own browser, which is exactly what consent is for.
    |
    | Set this to any callable returning a bool. Wire it to whatever cookie
    | consent package the site already uses.
    |
    */

    'consent' => null,

    /*
    |--------------------------------------------------------------------------
    | Estimated delivery date
    |--------------------------------------------------------------------------
    |
    | Google will not render its opt-in without one, and it decides when the
    | survey is sent. marshmallow/cart has no such concept, so the site has to
    | supply it: only you know your lead times.
    |
    | Set to a callable taking a ReviewableOrder and returning a
    | CarbonImmutable or null. Alternatively implement
    | reviewEstimatedDeliveryDate() on the order model itself, which wins over
    | this. When neither is present the Google provider declines to render and
    | `php artisan reviews:doctor` reports it, rather than guessing a date and
    | quietly mistiming every survey.
    |
    */

    'estimated_delivery_date' => null,

    /*
    |--------------------------------------------------------------------------
    | Order model mapping
    |--------------------------------------------------------------------------
    |
    | The DerivesReviewableOrder trait reads these column and relation names,
    | so a site whose Order does not follow marshmallow/cart conventions can
    | adjust config instead of overriding trait methods.
    |
    */

    'order' => [
        'customer_relation' => 'customer',
        'shipping_address_relation' => 'shippingAddress',
        'items_relation' => 'items',
        'product_relation' => 'product',
        'country_relation' => 'country',

        'email_column' => 'email',
        'first_name_column' => 'first_name',
        'last_name_column' => 'last_name',
        'city_column' => 'city',
        'country_code_column' => 'alpha2',
        'reference_column' => 'shopping_cart_display_id',
        'total_column' => 'price_including_vat',

        // GTIN lives on products.ni in marshmallow/products. Nova labels the
        // field GTIN, its help text calls it the EAN number.
        'gtin_column' => 'ni',
        'product_name_column' => 'name',
        'quantity_column' => 'quantity',
    ],

    /*
    |--------------------------------------------------------------------------
    | Events
    |--------------------------------------------------------------------------
    |
    | Off by default, because which event means "this order is really done"
    | differs per site and getting it wrong invites people who never paid.
    |
    | marshmallow/cart has no completed order event: OrderCreated fires before
    | payment. PaymentStatusPaid from marshmallow/payable is the closer proxy,
    | which is why it is the documented default here even though it carries a
    | Payment rather than an Order. That is what resolve_order is for: a
    | callable taking the event and returning a ReviewableOrder or null.
    |
    */

    'events' => [
        'enabled' => env('REVIEWS_LISTEN_TO_EVENTS', false),

        'listen' => [
            // \Marshmallow\Payable\Events\PaymentStatusPaid::class,
        ],

        'resolve_order' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Invitations are queued so a provider outage can never slow down or break
    | a checkout. Null uses the application defaults.
    |
    */

    'queue' => [
        'connection' => env('REVIEWS_QUEUE_CONNECTION'),
        'queue' => env('REVIEWS_QUEUE'),
        'tries' => env('REVIEWS_QUEUE_TRIES', 3),
        'backoff' => [60, 300, 900],
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    |
    | Applied by every provider that talks to an API.
    |
    */

    'http' => [
        'timeout' => env('REVIEWS_HTTP_TIMEOUT', 10),

        // Total attempts, not retries on top of the first try. This mirrors
        // Laravel's own Http::retry(), where 2 means one try and one retry.
        'attempts' => env('REVIEWS_HTTP_ATTEMPTS', 2),

        'retry_sleep_milliseconds' => env('REVIEWS_HTTP_RETRY_SLEEP', 250),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Successful invitations are not logged by default. Every log line this
    | package writes goes through a context() method that excludes the email
    | address, the customer name and the city, so turning this on does not put
    | personal data in your log files.
    |
    */

    'log' => [
        'channel' => env('REVIEWS_LOG_CHANNEL'),
        'successes' => env('REVIEWS_LOG_SUCCESSES', false),

        /*
         * An unexpected exception is sent to Sentry in full while the log line
         * stays redacted. A Throwable is not ours: an HTTP client that echoes
         * the request body would put a customer's email address into a log
         * file. Sentry is access controlled and does its own scrubbing, and it
         * is where someone actually looks, so it gets the whole thing.
         *
         * Without this the job's "never throw" rule made real bugs invisible:
         * nothing rethrown, nothing in the log but a class name.
         *
         * Does nothing when Sentry is not installed.
         */
        'report_exceptions' => env('REVIEWS_REPORT_EXCEPTIONS', true),
    ],

];
