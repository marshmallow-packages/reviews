<?php

declare(strict_types=1);

namespace Marshmallow\Reviews\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Marshmallow\Reviews\Contracts\ProvidesReviewLink;
use Marshmallow\Reviews\Contracts\RendersBadge;
use Marshmallow\Reviews\Contracts\SendsInvitations;
use Marshmallow\Reviews\Data\InvitationProduct;
use Marshmallow\Reviews\Data\InvitationResult;
use Marshmallow\Reviews\Data\ReviewInvitation;
use Marshmallow\Reviews\Enums\SkipReason;
use Marshmallow\Reviews\Support\ConfigValue;
use Marshmallow\Reviews\Support\Gate;

/**
 * Kiyoh and Klantenvertellen are one platform on two domains, which is why the
 * host is configuration rather than a constant here.
 *
 * Kiyoh is the provider that does all three things: it mails the invitation
 * itself, it publishes a badge, and it exposes a public profile URL we can drop
 * into our own email. It does not implement ImportsReviews yet, so nothing here
 * touches the review feed.
 */
final class KiyohProvider implements ProvidesReviewLink, RendersBadge, SendsInvitations
{
    private const string INVITE_PATH = '/v1/invite/external';

    private const string DEFAULT_BASE_URL = 'https://www.klantenvertellen.nl';

    /**
     * The complete language allow list Kiyoh accepts. It is not ISO-639-1: some
     * entries are bare language codes and some are language-region, so a locale
     * has to be mapped onto this list rather than passed through.
     *
     * Order matters. Prefix matching takes the first entry whose language part
     * matches, so pt-PT sitting before pt-BR is what makes a bare "pt" mean
     * European Portuguese.
     *
     * @var list<string>
     */
    private const array LOCALES = [
        'en', 'nl', 'fi-FI', 'fr', 'be', 'de', 'hu', 'bg', 'ro', 'hr', 'ja',
        'es-ES', 'it', 'pt-PT', 'tr', 'nn-NO', 'sv-SE', 'da', 'pt-BR', 'pl',
        'sl', 'zh-CN', 'ru', 'el', 'cs', 'et', 'lt', 'lv', 'sk',
    ];

    /**
     * Locales whose Kiyoh equivalent prefix matching cannot reach: Norwegian is
     * "no" or "nb" in ISO-639-1 but "nn-NO" to Kiyoh.
     *
     * @var array<string, string>
     */
    private const array LOCALE_ALIASES = [
        'no' => 'nn-NO',
        'nb' => 'nn-NO',
    ];

    public function __construct(
        private readonly Repository $config,
        private readonly HttpFactory $http,
        private readonly ViewFactory $views,
        private readonly Gate $gate,
    ) {}

    public function name(): string
    {
        return 'kiyoh';
    }

    public function isConfigured(): bool
    {
        return $this->apiToken() !== null && $this->locationId() !== null;
    }

    public function invite(ReviewInvitation $invitation): InvitationResult
    {
        // Checked here, not only in the fan-out. reviews.enabled is documented
        // as "no invitations are sent", and staging relies on that: without
        // this guard a direct Reviews::driver('kiyoh')->invite() would post to
        // the live API and mail a real customer about a test order.
        if (! $this->gate->maySend()) {
            return InvitationResult::skipped($this->name(), SkipReason::Disabled);
        }

        $token = $this->apiToken();
        $locationId = $this->locationId();

        if ($token === null || $locationId === null) {
            return InvitationResult::skipped($this->name(), SkipReason::NotConfigured);
        }

        if (! $invitation->hasEmail()) {
            return InvitationResult::skipped($this->name(), SkipReason::NoEmail);
        }

        $language = $this->language($invitation->locale);

        if ($language === null) {
            return InvitationResult::skipped($this->name(), SkipReason::MissingData);
        }

        try {
            $response = $this->http
                ->withHeaders(['X-Publication-Api-Token' => $token])
                ->acceptJson()
                ->timeout($this->intConfig('reviews.http.timeout', 10))
                ->retry(
                    max(1, $this->intConfig('reviews.http.attempts', 2)),
                    max(0, $this->intConfig('reviews.http.retry_sleep_milliseconds', 250)),
                    throw: false,
                )
                ->post($this->baseUrl().self::INVITE_PATH, $this->payload($invitation, $locationId, $language));
        } catch (ConnectionException) {
            /*
             * The exception message carries the request URL and can carry the
             * body, so it is not repeated here: this string is logged verbatim.
             */
            return InvitationResult::failed($this->name(), 'Could not reach Kiyoh: the connection failed or timed out.');
        }

        if ($response->successful()) {
            return InvitationResult::sent($this->name());
        }

        $error = $this->errorPayload($response);

        if ($this->looksLikeDuplicate($error)) {
            return InvitationResult::skipped($this->name(), SkipReason::Duplicate);
        }

        return InvitationResult::failed($this->name(), $this->failureMessage($error, $response->status()), $response->status());
    }

    public function badge(): ?View
    {
        if (! $this->gate->mayRender()) {
            return null;
        }

        $profileUrl = $this->profileUrl();

        if ($profileUrl === null) {
            return null;
        }

        return $this->views->make('reviews::kiyoh.badge', [
            'profile_url' => $profileUrl,
            'location_id' => $this->locationId(),
        ]);
    }

    /**
     * Kiyoh has no per-order review URL on this API, so the invitation is
     * accepted and ignored: every customer gets the same public profile.
     */
    public function reviewLink(?ReviewInvitation $invitation = null): ?string
    {
        return $this->profileUrl();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ReviewInvitation $invitation, string $locationId, string $language): array
    {
        $productCodes = $this->productCodes($invitation);

        return array_filter([
            'location_id' => $locationId,
            'invite_email' => $invitation->email,
            'delay' => $this->delayInDays($invitation),
            'first_name' => $invitation->firstName,
            'last_name' => $invitation->lastName,
            'language' => $language,
            'ref_code' => $invitation->orderReference,
            'product_code' => $productCodes === [] ? null : $productCodes,
            'city' => $invitation->city,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return list<string>
     */
    private function productCodes(ReviewInvitation $invitation): array
    {
        return array_map(
            static fn (InvitationProduct $product): string => $product->identifier,
            $invitation->products,
        );
    }

    /**
     * Kiyoh counts the delay in days from the moment it receives the invitation,
     * so skipping weekends means adjusting the number rather than the date.
     */
    private function delayInDays(ReviewInvitation $invitation): int
    {
        $today = CarbonImmutable::now()->startOfDay();
        $delay = max(0, $invitation->delayInDays ?? $this->intConfig('reviews.providers.kiyoh.delay_days', 3));

        if (! $this->skipWeekends()) {
            return $delay;
        }

        $sendAt = $today->addDays($delay);

        if ($sendAt->isSaturday()) {
            $sendAt = $sendAt->addDays(2);
        } elseif ($sendAt->isSunday()) {
            $sendAt = $sendAt->addDay();
        }

        return (int) $today->diffInDays($sendAt);
    }

    /**
     * Maps an ISO-639-1 locale onto Kiyoh's own list, falling back to the
     * configured default. Null means neither could be mapped, which is a skip:
     * an unsupported language is rejected by Kiyoh anyway.
     */
    private function language(?string $locale): ?string
    {
        return $this->mapLocale($locale) ?? $this->mapLocale($this->stringConfig('reviews.providers.kiyoh.locale'));
    }

    private function mapLocale(?string $locale): ?string
    {
        if ($locale === null) {
            return null;
        }

        $normalised = str_replace('_', '-', trim($locale));

        if ($normalised === '') {
            return null;
        }

        foreach (self::LOCALES as $supported) {
            if (strcasecmp($supported, $normalised) === 0) {
                return $supported;
            }
        }

        $language = mb_strtolower(explode('-', $normalised)[0]);

        if (array_key_exists($language, self::LOCALE_ALIASES)) {
            return self::LOCALE_ALIASES[$language];
        }

        foreach (self::LOCALES as $supported) {
            if (mb_strtolower(explode('-', $supported)[0]) === $language) {
                return $supported;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function errorPayload(Response $response): array
    {
        $decoded = $response->json();

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Kiyoh refuses a second invitation to the same address within 30 days, and
     * that refusal is a correct outcome rather than a failure.
     *
     * Kiyoh does not document the payload of that refusal, so this matches on
     * the wording of the message and on the error code. Anything it cannot
     * recognise falls through to a failure on purpose: reporting a real error as
     * a duplicate would hide an outage, while reporting a duplicate as an error
     * only produces a log line.
     *
     * @param  array<string, mixed>  $error
     */
    private function looksLikeDuplicate(array $error): bool
    {
        foreach ($this->errorMessages($error) as $message) {
            $lowered = mb_strtolower($message);

            if (str_contains($lowered, 'invite') && str_contains($lowered, 'already')) {
                return true;
            }
        }

        $code = $error['errorCode'] ?? null;

        if (! is_string($code)) {
            return false;
        }

        $lowered = mb_strtolower($code);

        return str_contains($lowered, 'duplicate') || str_contains($lowered, 'already');
    }

    /**
     * @param  array<string, mixed>  $error
     * @return list<string>
     */
    private function errorMessages(array $error): array
    {
        $messages = [];

        $message = $error['message'] ?? null;

        if (is_string($message) && trim($message) !== '') {
            $messages[] = trim($message);
        }

        $detailed = $error['detailedError'] ?? null;

        if (! is_array($detailed)) {
            return $messages;
        }

        foreach ($detailed as $detail) {
            if (! is_array($detail)) {
                continue;
            }

            $detailMessage = $detail['message'] ?? null;

            if (is_string($detailMessage) && trim($detailMessage) !== '') {
                $messages[] = trim($detailMessage);
            }
        }

        return $messages;
    }

    /**
     * @param  array<string, mixed>  $error
     */
    private function failureMessage(array $error, int $status): string
    {
        $parts = [];
        $code = $error['errorCode'] ?? null;

        if (is_string($code) || is_int($code)) {
            $parts[] = 'errorCode '.$code;
        }

        $messages = $this->errorMessages($error);

        if ($messages !== []) {
            $parts[] = implode('; ', $messages);
        }

        if ($parts === []) {
            return 'Kiyoh returned HTTP '.$status.' without a readable error body.';
        }

        return $this->redact(implode(': ', $parts));
    }

    /**
     * This string ends up in a log file verbatim, and Kiyoh is free to quote the
     * address it rejected back at us, so any address is stripped before the
     * message leaves this class. Trimmed as well, because a provider error body
     * is not a place to trust the length of.
     */
    private function redact(string $message): string
    {
        $redacted = preg_replace('/[\w.+-]+@[\w-]+\.[\w.-]+/', '[email redacted]', $message) ?? $message;

        return mb_substr($redacted, 0, 500);
    }

    private function apiToken(): ?string
    {
        return $this->stringConfig('reviews.providers.kiyoh.api_token');
    }

    private function locationId(): ?string
    {
        return $this->stringConfig('reviews.providers.kiyoh.location_id');
    }

    private function profileUrl(): ?string
    {
        return $this->stringConfig('reviews.providers.kiyoh.profile_url');
    }

    private function baseUrl(): string
    {
        return rtrim($this->stringConfig('reviews.providers.kiyoh.base_url') ?? self::DEFAULT_BASE_URL, '/');
    }

    private function skipWeekends(): bool
    {
        return ConfigValue::bool($this->config->get('reviews.providers.kiyoh.skip_weekends', true));
    }

    private function stringConfig(string $key): ?string
    {
        return ConfigValue::string($this->config->get($key));
    }

    private function intConfig(string $key, int $default): int
    {
        return ConfigValue::int($this->config->get($key), $default);
    }
}
