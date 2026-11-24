<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\SupportedLocale;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $urlLocale = $request->query('locale');

        $locale = is_string($urlLocale) ? SupportedLocale::tryFrom($urlLocale) : null;
        $locale ??= $user instanceof User ? $user->preferences->locale : null;

        if ($locale === null) {
            $locale = SupportedLocale::fromAcceptLanguage($request->header('Accept-Language'));

            if ($locale !== null && $user instanceof User && $user->preferences->locale === null) {
                $user->updatePreferences(['locale' => $locale->value]);
            }
        }

        if ($locale !== null) {
            app()->setLocale($locale->value);
        }

        return $next($request);
    }
}
