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
    ]))->toEqualCanonicalizing(['MY_DB', 'CONN']);
});

it('names a variable that references the secret store, whatever its name', function () {
    expect(app(SecretVariables::class)->names([
        'STRIPE' => '${{ secrets.STRIPE }}',
        'BILLING_ENDPOINT' => 'https://api.example.com/${{ secrets.ACCOUNT }}',
        'APP_ENV' => 'production',
    ]))->toEqualCanonicalizing(['STRIPE', 'BILLING_ENDPOINT']);
});

it('names credentials in values, more name patterns, and secrets that except would let through', function () {
    expect(app(SecretVariables::class)->names([
        'MONGODB_URI' => 'mongodb://app:pw-123456@db/app', 'UPSTREAM' => 'https://bot:tok-123456@api.example.com/x', 'HOMEPAGE' => 'https://example.com/a@b',
        'SMTP_PWD' => 'x', 'MAPS_APIKEY' => 'x', 'AZURE_CONNECTION_STRING' => 'x', 'POSTGRES_URL' => 'x', 'APP_MYSQL_URL' => 'x',
        'VITE_API_SECRET' => 'x', 'NEXT_PUBLIC_TOKEN' => 'x', 'VITE_APP_NAME' => 'x', 'REDIRECT_URI' => 'https://example.com/cb',
    ]))->toEqualCanonicalizing([
        'MONGODB_URI', 'UPSTREAM', 'SMTP_PWD', 'MAPS_APIKEY', 'AZURE_CONNECTION_STRING', 'POSTGRES_URL', 'APP_MYSQL_URL', 'VITE_API_SECRET', 'NEXT_PUBLIC_TOKEN',
    ]);
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

it('masks JSON-escaped, hex, shell-quoted and shifted base64 forms like the agent', function () {
    $secret = "p\"a/ss<w>&'\u{f6}\u{1F511}";
    $mask = new SecretMask([$secret]);
    $texts = [
        'php json' => json_encode(['pw' => $secret]),
        'go json' => json_encode(['pw' => $secret], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'hex' => 'dump '.bin2hex($secret),
        'shell' => "PW='".str_replace("'", "'\\''", $secret)."'",
    ];

    foreach ($texts as $text) {
        expect($mask->apply($text))->toContain('••••')->not->toContain('ss<w>')->not->toContain('ss\\u003');
    }

    $hunter = new SecretMask(['hunter2-Secret']);

    foreach (['a', 'ab', 'abc', 'abcd'] as $user) {
        expect($hunter->apply('Basic '.base64_encode("{$user}:hunter2-Secret")))->toContain('••••');
    }
});

it('merges overlapping secrets into one mask', function () {
    $mask = new SecretMask(['abcdefgh', 'efghijkl', 'password123', 'word12']);

    expect($mask->apply('x abcdefghijkl y'))->toBe('x •••• y')
        ->and($mask->apply('[password123][word12]'))->toBe('[••••][••••]')
        ->and($mask->apply('abcdefgh'))->toBe('••••');
});
