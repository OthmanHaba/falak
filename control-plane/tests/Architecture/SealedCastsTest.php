<?php

use Illuminate\Database\Eloquent\Model;

// Secrets are sealed under the key hierarchy (Falak\Kernel\Security), never with APP_KEY: Laravel's
// `encrypted` casts and Crypt / encrypt() helpers have no place in module code.
test('no model uses Laravel encrypted casts', function () {
    $offenders = [];

    foreach (glob(dirname(__DIR__, 2).'/modules/*/src/Domain/Models/*.php') as $file) {
        $class = 'Falak\\'.basename(dirname($file, 4)).'\\Domain\\Models\\'.basename($file, '.php');

        if (! is_subclass_of($class, Model::class) || (new ReflectionClass($class))->isAbstract()) {
            continue;
        }

        foreach ((new $class)->getCasts() as $column => $cast) {
            if (is_string($cast) && (str_starts_with($cast, 'encrypted') || str_contains($cast, 'AsEncrypted'))) {
                $offenders[] = "{$class}::{$column} ({$cast})";
            }
        }
    }

    expect($offenders)->toBe([]);
});

test('module code does not encrypt with APP_KEY', function () {
    $offenders = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/modules', FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        $path = $file->getPathname();

        if (! str_ends_with($path, '.php') || ! str_contains($path, '/src/')) {
            continue;
        }

        // Method calls (->decrypt) and definitions are fine: they go through an encrypter such as SealedEncrypter.
        if (preg_match("/=>\\s*'encrypted|AsEncrypted(ArrayObject|Collection)|Crypt::|(?<![>:]|function )\\b(encrypt|decrypt|encryptString|decryptString)\\(/", (string) file_get_contents($path), $m)) {
            $offenders[] = basename(dirname($path)).'/'.basename($path).": {$m[0]}";
        }
    }

    expect($offenders)->toBe([]);
});
