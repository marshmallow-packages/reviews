<?php

declare(strict_types=1);

namespace Marshmallow\Reviews;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Compilers\BladeCompiler;
use Marshmallow\Reviews\Console\Commands\DoctorCommand;
use Marshmallow\Reviews\Listeners\SendInvitationForOrderEvent;
use Marshmallow\Reviews\Support\ConfigValue;
use Override;

class ReviewsServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/reviews.php', 'reviews');

        $this->app->singleton(Reviews::class, static fn (Container $app): Reviews => new Reviews($app));
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'reviews');

        $this->loadTranslationsFrom(__DIR__.'/../lang', 'reviews');

        $this->registerBladeComponents();

        $this->registerEventListeners();

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/reviews.php' => config_path('reviews.php'),
        ], ['reviews', 'reviews-config']);

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/reviews'),
        ], ['reviews', 'reviews-views']);

        $this->publishes([
            __DIR__.'/../lang' => $this->app->langPath('vendor/reviews'),
        ], ['reviews', 'reviews-lang']);

        $this->commands([
            DoctorCommand::class,
        ]);
    }

    private function registerBladeComponents(): void
    {
        $this->callAfterResolving('blade.compiler', static function (BladeCompiler $blade): void {
            $blade->componentNamespace('Marshmallow\\Reviews\\View\\Components', 'reviews');
        });
    }

    /**
     * Nothing is bound unless the site opts in. Which event means "this order
     * is really done" differs per site, and binding the wrong one invites
     * customers who never completed payment.
     */
    private function registerEventListeners(): void
    {
        $config = $this->app->make('config');

        if (! ConfigValue::bool($config->get('reviews.events.enabled', false))) {
            return;
        }

        $events = $config->get('reviews.events.listen', []);

        if (! is_array($events)) {
            return;
        }

        $dispatcher = $this->app->make(Dispatcher::class);

        foreach ($events as $event) {
            if (is_string($event) && $event !== '') {
                $dispatcher->listen($event, SendInvitationForOrderEvent::class);
            }
        }
    }
}
