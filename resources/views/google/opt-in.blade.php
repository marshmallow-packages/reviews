@php
    // Google collects the opt-in in the visitor's own browser: there is no
    // server side call behind this snippet.
    $optInScriptUrl = 'https://apis.google.com/js/platform.js?onload=renderOptIn';

    if ($language) {
        $optInScriptUrl .= '&hl='.urlencode($language);
    }

    $gtins = array_map(static fn (string $gtin): array => ['gtin' => $gtin], $products);
@endphp

<script src="{{ $optInScriptUrl }}" async defer></script>

{{--
    Every value below is json encoded rather than echoed: an order reference or
    an email address may contain characters that would end the JavaScript
    string it sits in.
--}}
<script>
    window.renderOptIn = function () {
        window.gapi.load('surveyoptin', function () {
            window.gapi.surveyoptin.render({
                merchant_id: @json($merchant_id),
                order_id: @json($order_id),
                email: @json($email),
                delivery_country: @json($delivery_country),
                estimated_delivery_date: @json($estimated_delivery_date),
                opt_in_style: @json($opt_in_style),
                @if ($gtins !== [])
                    products: @json($gtins),
                @endif
            });
        });
    };
</script>
