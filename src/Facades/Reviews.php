<?php

declare(strict_types=1);

namespace Marshmallow\Reviews\Facades;

use Illuminate\Support\Facades\Facade;
use Illuminate\Support\HtmlString;
use Marshmallow\Reviews\Contracts\ReviewProvider;
use Marshmallow\Reviews\Data\InvitationResult;
use Marshmallow\Reviews\Data\ReviewInvitation;
use Marshmallow\Reviews\Reviews as ReviewManager;
use Marshmallow\Reviews\Testing\ReviewsFake;

/**
 * @method static ReviewProvider driver(string|null $driver = null)
 * @method static ReviewManager extend(string $driver, \Closure $callback)
 * @method static string getDefaultDriver()
 * @method static list<string> available()
 * @method static list<ReviewProvider> active()
 * @method static list<ReviewProvider> supporting(string $capability)
 * @method static list<InvitationResult> inviteAll(ReviewInvitation $invitation)
 * @method static HtmlString optInAll(ReviewInvitation $invitation)
 * @method static HtmlString badgeAll()
 * @method static array<string, string> reviewLinks(ReviewInvitation|null $invitation = null)
 * @method static bool enabled()
 * @method static bool hasConsent()
 *
 * @see ReviewManager
 */
class Reviews extends Facade
{
    /**
     * Swaps the manager for a recording double, so a test can assert on
     * invitations without faking HTTP for every provider.
     *
     * activate() replaces the container binding as well as the facade's
     * resolved instance. Swapping only the facade would leave anything that
     * type hints the manager in its constructor, which is both view components
     * and the queued job, still holding the real one.
     */
    public static function fake(): ReviewsFake
    {
        return (new ReviewsFake)->activate();
    }

    protected static function getFacadeAccessor(): string
    {
        return ReviewManager::class;
    }
}
