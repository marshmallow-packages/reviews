{{--
    Kiyoh's own badge is an account specific snippet copied out of their
    dashboard and it differs per widget a customer buys, so it cannot be
    generated from the API credentials alone. This renders the part that is
    always the same: a link to the public profile, carrying the location id as a
    data attribute so a site that does have a Kiyoh widget can attach it here
    instead of publishing this view.
--}}
<a
    class="reviews-kiyoh-badge"
    href="{{ $profile_url }}"
    @if ($location_id)
        data-location-id="{{ $location_id }}"
    @endif
    target="_blank"
    rel="noopener nofollow"
>
    {{-- A literal key, so this still reads correctly with no translation file published. --}}
    {{ __('Read our reviews') }}
</a>
