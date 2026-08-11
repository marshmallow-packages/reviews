<?php

declare(strict_types=1);

namespace Marshmallow\Reviews\Tests;

use Marshmallow\Reviews\ReviewsServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            ReviewsServiceProvider::class,
        ];
    }

    /**
     * The package ships disabled by default so installing it changes nothing.
     * Tests that want a provider switch it on themselves, which keeps every
     * test explicit about what it is exercising.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('reviews.enabled', true);
        $app['config']->set('reviews.default', 'null');
        $app['config']->set('reviews.active', null);
        $app['config']->set('queue.default', 'sync');
    }
}
