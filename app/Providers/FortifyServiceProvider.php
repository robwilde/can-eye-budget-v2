<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

final class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn () => view('pages::auth.login'));
        Fortify::verifyEmailView(fn () => view('pages::auth.verify-email'));
        Fortify::twoFactorChallengeView(fn () => view('pages::auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => view('pages::auth.confirm-password'));
        Fortify::registerView(fn () => view('pages::auth.register'));
        Fortify::resetPasswordView(fn () => view('pages::auth.reset-password'));
        Fortify::requestPasswordResetLinkView(fn () => view('pages::auth.forgot-password'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute(5)->by('register|'.$request->ip());
        });

        RateLimiter::for('password-reset', function (Request $request) {
            // Two budgets: the client's request rate, and the mail volume any single address can
            // be made to receive. Without the second one a distributed sender stays under every
            // per-IP ceiling while still mail-bombing one inbox.
            $limits = [Limit::perMinute(5)->by('password-reset|ip|'.$request->ip())];

            $email = $request->input(Fortify::email());

            // This limiter runs before validation, so the field can be absent, empty, oversized
            // or not even a string. Those requests get the per-client budget only. Keying a
            // shared address budget on the empty string would let a handful of malformed
            // requests exhaust one bucket every real address then queues behind, and normalising
            // an unbounded value would transliterate up to post_max_size (26M) of request body
            // on every attempt. RFC 5321 caps an address at 254 octets, so a longer value is not
            // a real reset target; the cap here counts characters, which bounds the work all the
            // same.
            if (is_string($email) && $email !== '' && mb_strlen($email) <= 254) {
                $normalised = Str::transliterate(Str::lower($email));

                if ($normalised !== '') {
                    $limits[] = Limit::perHour(3)->by('password-reset|email|'.$normalised);
                }
            }

            return $limits;
        });
    }
}
