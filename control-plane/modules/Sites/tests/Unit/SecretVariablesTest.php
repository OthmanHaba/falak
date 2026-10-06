<?php

use Falak\Kernel\Support\SecretMask;
use Falak\Sites\Contracts\SecretVariables;

it('names secret variables by pattern, with exceptions for identifiers and public values', function () {
    $names = app(SecretVariables::class)->names([
        'APP_KEY' => 'base64:x', 'APP_ENV' => 'production', 'DB_PASSWORD' => 'x', 'DB_PASS' => 'x', 'MAIL_PASSWORD' => 'x',
        'STRIPE_SECRET' => 'x', 'GITHUB_TOKEN' => 'x', 'JWT_PRIVATE_KEY' => 'x', 'GOOGLE_CREDENTIALS' => 'x', 'SENTRY_DSN' => 'x',
        'DATABASE_URL' => 'x', 'REDIS_URL' => 'x', 'BASIC_AUTH' => 'x', 'AWS_SECRET_ACCESS_KEY' => 'x',
        // Not secrets.
        'AWS_ACCESS_KEY_ID' => 'x', 'FALAK_SITE_ID' => 'x', 'VITE_PUSHER_APP_KEY' => 'x', 'STRIPE_PUBLISHABLE_KEY' => 'x',
        'NEXT_PUBLIC_API_KEY' => 'x', 'AUTHOR' => 'x', 'APP_URL' => 'x', 'KEYBOARD' => 'x',
    ]);

    expect($names)->toEqualCanonicalizing([
        'APP_KEY', 'DB_PASSWORD', 'DB_PASS', 'MAIL_PASSWORD', 'STRIPE_SECRET', 'GITHUB_TOKEN', 'JWT_PRIVATE_KEY', 'GOOGLE_CREDENTIALS',
        'SENTRY_DSN', 'DATABASE_URL', 'REDIS_URL', 'BASIC_AUTH', 'AWS_SECRET_ACCESS_KEY',
    ]);
});

it('names a variable that references another service secret', function () {
    expect(app(SecretVariables::class)->names([
        'MY_DB' => '${{ postgres.DB_PASSWORD }}',
        'CONN' => 'pgsql://u:${{postgres.DB_PASSWORD}}@h/db',
        'DB_HOST' => '${{ postgres.DB_HOST }}',
        'APP_ENV' => 'production',
    ]))->toBe(['MY_DB', 'CONN']);
});

it('reads the patterns from config (the secret store replaces them)', function () {
    config(['sites.secret_variables' => ['patterns' => ['/^ONLY_THIS$/'], 'except' => []]]);

    expect(app(SecretVariables::class)->names(['ONLY_THIS' => 'x', 'DB_PASSWORD' => 'x']))->toBe(['ONLY_THIS']);
});

it('masks secret values and their encodings', function () {
    $secret = 's3cr3t-Pa$$/w0rd+=';
    $mask = new SecretMask([$secret, 'short', '']);

    foreach ([$secret, base64_encode($secret), strtr(base64_encode($secret), '+/', '-_'), urlencode($secret), rawurlencode($secret)] as $form) {
        expect($mask->apply("before {$form} after"))->toBe('before •••• after');
    }

    expect($mask->apply('short and plain'))->toBe('short and plain')
        ->and((new SecretMask)->isEmpty())->toBeTrue()
        ->and((new SecretMask(['abcde']))->isEmpty())->toBeTrue();
});

it('merges overlapping secrets into one mask', function () {
    $mask = new SecretMask(['abcdefgh', 'efghijkl', 'password123', 'word12']);

    expect($mask->apply('x abcdefghijkl y'))->toBe('x •••• y')
        ->and($mask->apply('[password123][word12]'))->toBe('[••••][••••]')
        ->and($mask->apply('abcdefgh'))->toBe('••••');
});
