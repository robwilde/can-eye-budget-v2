<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

final class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    protected function gate(): void
    {
        Gate::define('viewHorizon', static fn ($user = null): bool => match (true) {
            app()->environment('local') => true,
            $user === null => false,
            default => in_array($user->email, (array) config('horizon.authorized_emails'), true),
        });
    }
}
