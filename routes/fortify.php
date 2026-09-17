<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;
use Laravel\Fortify\Http\Controllers\PasswordResetLinkController;
use Laravel\Fortify\Http\Controllers\RegisteredUserController;
use Laravel\Fortify\RoutePath;

// Fortify hardcodes ['guest:<guard>'] on register.store and password.email and only ever reads
// the login, two-factor and verification limiter keys, so neither route can be throttled through
// config. Mutating the Route objects from a provider was measured during #383 and never landed in
// the route cache the container builds at start-up. Re-declaring the same method+URI does land:
// RouteCollection::addToCollections keys $routes[$method][$domain.$uri], so the later declaration
// replaces Fortify's for dispatch, and because this is an ordinary route declaration it serialises
// into route:cache like any other. Only these two POSTs are owned here; the rest of Fortify's route
// table stays with Fortify. Guard, prefix, domain and path overrides mirror Fortify's own reads so
// the override cannot drift onto a different URI and leave the unthrottled route in front.
Route::group([
    'prefix' => config('fortify.prefix'),
    'domain' => config('fortify.domain'),
], function (): void {
    $guard = 'guest:'.config('fortify.guard');

    if (Features::enabled(Features::resetPasswords())) {
        Route::post(RoutePath::for('password.email', '/forgot-password'), [PasswordResetLinkController::class, 'store'])
            ->middleware([$guard, 'throttle:password-reset'])
            ->name('password.email');
    }

    if (Features::enabled(Features::registration())) {
        Route::post(RoutePath::for('register', '/register'), [RegisteredUserController::class, 'store'])
            ->middleware([$guard, 'throttle:register'])
            ->name('register.store');
    }
});

// RouteCollection::addLookups is first-wins for names, so without this the name lookup keeps
// pointing at Fortify's unthrottled Route object while dispatch uses the throttled one. The URI is
// identical so URL generation is unaffected either way, but route('register.store') and
// getByName() would otherwise report middleware the request never runs.
Route::getRoutes()->refreshNameLookups();
