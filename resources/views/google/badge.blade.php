@php
    // The declarative form is used rather than the JavaScript render call, so
    // the badge needs no callback of its own and cannot collide with the
    // opt-in module's renderOptIn on the same page. platform.js scans for the
    // element itself, which is why this loader carries no onload.
    $badgeScriptUrl = 'https://apis.google.com/js/platform.js';

    if ($language) {
        $badgeScriptUrl .= '?hl='.urlencode($language);
    }
@endphp

<script src="{{ $badgeScriptUrl }}" async defer></script>

<div class="g-ratingbadge"
     data-merchant-id="{{ $merchant_id }}"
     data-position="{{ $badge_position }}"></div>
