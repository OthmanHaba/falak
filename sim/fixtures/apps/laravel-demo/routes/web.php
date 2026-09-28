<?php

use App\Jobs\RecordVisit;
use App\Support\OctaneProbe;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

// Kiln E2E demo: each route exercises one thing the platform must observe.

Route::get('/', function () {
    return response()->json([
        'app' => config('app.name'),
        'release' => config('app.release'),
        'server' => gethostname(),
    ]);
});

Route::get('/health', fn () => response('ok'));

// Octane E2E: in a long-lived Octane worker the counter keeps growing across requests; under classic
// FrankenPHP / PHP-FPM every request starts fresh (served is always 1).
Route::get('/octane', function () {
    OctaneProbe::$served++;

    return response()->json([
        'octane' => (bool) ($_SERVER['LARAVEL_OCTANE'] ?? $_ENV['LARAVEL_OCTANE'] ?? getenv('LARAVEL_OCTANE')),
        'served' => OctaneProbe::$served,
        'pid' => getmypid(),
        'release' => config('app.release'),
    ]);
});

Route::get('/work', function () {
    Cache::increment('visits.total');
    RecordVisit::dispatch(now()->toIso8601String());

    return response()->json(['queued' => true]);
});

Route::get('/slow', function () {
    usleep(1_200_000);

    return response()->json(['slow' => true]);
});

Route::get('/outgoing', fn () => response()->json([
    'status' => Http::timeout(3)->get(url('/health'))->status(),
]));

Route::get('/boom', function () {
    throw new RuntimeException('Kiln E2E demo exception');
});
