<?php

use Laravel\Fortify\Features;

/*
 * Fortify is used by the Identity module for its two-factor authentication actions only;
 * its routes are disabled (Fortify::ignoreRoutes()) and Identity owns every auth endpoint.
 */
return [
    'guard' => 'web',
    'passwords' => 'users',
    'username' => 'email',
    'email' => 'email',
    'lowercase_usernames' => true,
    'home' => '/dashboard',
    'prefix' => '',
    'domain' => null,
    'middleware' => ['web'],
    'limiters' => [
        'login' => null,
        'two-factor' => null,
    ],
    'views' => false,
    'features' => [
        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]),
    ],
];
