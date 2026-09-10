<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Laravel\Fortify\Features;

trait DisablesRegistration
{
    public function createApplication(): Application
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';

        $app->afterBootstrapping(LoadConfiguration::class, static function (Application $app): void {
            $app['config']->set('fortify.features', array_values(array_filter(
                $app['config']->get('fortify.features'),
                static fn (string $feature): bool => $feature !== Features::registration(),
            )));
        });

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
