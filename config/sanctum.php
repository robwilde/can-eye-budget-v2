<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Token-only authentication
    |--------------------------------------------------------------------------
    |
    | No guard is consulted before the bearer token, so a browser session never
    | authenticates an API request (and is never exposed to cross-site requests
    | through it). The SPA cookie flow and Sanctum's own routes are off.
    |
    */

    'stateful' => [],

    'guard' => [],

    'routes' => false,

    'expiration' => null,

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', 'cib_'),

    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies' => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token' => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ],

];
