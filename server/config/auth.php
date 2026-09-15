<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | This option defines the default authentication "guard" and password
    | reset "broker" for your application. You may change these values
    | as required. All authentication requests use the admin (Sanctum)
    | guard; the framework's web/session guard and User model were
    | removed with the skeleton (API-only backend).
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'admin'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | Here you may define every authentication guard for your application.
    | The admin guard authenticates Admin models via Sanctum tokens.
    |
    */

    'guards' => [
        'admin' => [
            'driver' => 'sanctum',
            'provider' => 'admins',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | All authentication drivers have a user provider. This defines how the
    | users are actually retrieved out of your database or other storage
    | mechanisms used by this application to persist your user's data.
    |
    */

    'providers' => [
        'admins' => [
            'driver' => 'eloquent',
            'model' => App\Admin\Models\Admin::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    |
    | The number of seconds before a password confirmation window expires
    | and the user is asked to re-enter their password via the confirmation
    | screen. Reserved for future admin profile flows.
    |
    */

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
