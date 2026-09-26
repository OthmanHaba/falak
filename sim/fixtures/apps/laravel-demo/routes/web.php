<?php

use App\Jobs\RecordVisit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

// Kiln E2E demo: each route exercises one thing the platform must observe.

Route::get('/', function () {
    return response()->json([
        'app' => config('app.name'),
        'release' => env('KILN_RELEASE_ID'),
        'server' => gethostname(),
    ]);
});

Route::get('/health', fn () => response('ok'));

Route::get('/work', function () {
    Cache::remember('visits.total', 60, fn () => DB::table('jobs')->count());
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
