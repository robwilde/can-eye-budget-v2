<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;

trait DisablesRegistration
{
    private string|false $registrationFlagBeforeTest = false;

    public function createApplication(): Application
    {
        $this->registrationFlagBeforeTest = getenv('FORTIFY_REGISTRATION_ENABLED');

        $app = require Application::inferBasePath().'/bootstrap/app.php';

        $app->afterBootstrapping(LoadEnvironmentVariables::class, static function (): void {
            putenv('FORTIFY_REGISTRATION_ENABLED=false');
            $_ENV['FORTIFY_REGISTRATION_ENABLED'] = 'false';
            $_SERVER['FORTIFY_REGISTRATION_ENABLED'] = 'false';
        });

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    public function restoreRegistrationFlag(): void
    {
        if ($this->registrationFlagBeforeTest === false) {
            putenv('FORTIFY_REGISTRATION_ENABLED');
            unset($_ENV['FORTIFY_REGISTRATION_ENABLED'], $_SERVER['FORTIFY_REGISTRATION_ENABLED']);

            return;
        }

        putenv('FORTIFY_REGISTRATION_ENABLED='.$this->registrationFlagBeforeTest);
        $_ENV['FORTIFY_REGISTRATION_ENABLED'] = $this->registrationFlagBeforeTest;
        $_SERVER['FORTIFY_REGISTRATION_ENABLED'] = $this->registrationFlagBeforeTest;
    }
}
