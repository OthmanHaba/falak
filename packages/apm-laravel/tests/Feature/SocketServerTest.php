<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Falak\Apm\Recorder;
use Falak\Apm\Transport\SocketTransport;

/**
 * Starts tests/Fixtures/fake-otlp-server.php and returns [process, outDir, address].
 */
function startFakeOtlpServer(string $listen): array
{
    $dir = sys_get_temp_dir().'/falak-otlp-'.bin2hex(random_bytes(4));
    mkdir($dir);

    $process = proc_open([PHP_BINARY, __DIR__.'/../Fixtures/fake-otlp-server.php', $listen, $dir], [1 => ['file', '/dev/null', 'w'], 2 => ['file', "$dir/stderr", 'w']], $pipes);

    $deadline = microtime(true) + 5;

    while (! file_exists("$dir/address") && microtime(true) < $deadline) {
        usleep(10_000);
    }

    return [$process, $dir, trim((string) @file_get_contents("$dir/address"))];
}

/** @return list<string> raw HTTP requests received */
function receivedRequests(string $dir, int $expected): array
{
    $deadline = microtime(true) + 5;

    while (count(glob("$dir/*.http")) < $expected && microtime(true) < $deadline) {
        usleep(10_000);
    }

    $files = glob("$dir/*.http");
    natsort($files);

    return array_values(array_map('file_get_contents', $files));
}

function useTransport(Recorder $recorder, SocketTransport $transport): void
{
    (new ReflectionProperty($recorder, 'transport'))->setValue($recorder, $transport);
}

it('sends OTLP/HTTP JSON over the agent unix socket', function () {
    $socket = sys_get_temp_dir().'/falak-'.bin2hex(random_bytes(4)).'.sock';
    [$process, $dir] = startFakeOtlpServer('unix://'.$socket);

    try {
        useTransport($this->recorder(), new SocketTransport(['unix:'.$socket], 0.25));

        Route::get('/hello', function () {
            Log::info('hi from request');

            return 'hello';
        });

        $this->get('/hello')->assertOk();

        $requests = receivedRequests($dir, 2);
        expect($requests)->toHaveCount(2);

        [$traces, $logs] = $requests;
        [$head, $body] = explode("\r\n\r\n", $traces, 2);

        expect($head)->toStartWith("POST /v1/traces HTTP/1.1\r\n")
            ->toContain('Content-Type: application/json')
            ->toContain('Content-Length: '.strlen($body));

        $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        $span = collect($payload['resourceSpans'][0]['scopeSpans'][0]['spans'])->firstWhere('name', 'GET /hello');
        expect($span)->not->toBeNull()
            ->and(collect($span['attributes'])->firstWhere('key', 'falak.event.type')['value'])->toBe(['stringValue' => 'request']);

        expect($logs)->toStartWith("POST /v1/logs HTTP/1.1\r\n")->toContain('hi from request');
    } finally {
        proc_terminate($process);
        @unlink($socket);
    }
});

it('falls back to the TCP endpoint when the socket is missing', function () {
    [$process, $dir, $address] = startFakeOtlpServer('tcp://127.0.0.1:0');

    try {
        $transport = new SocketTransport(['unix:/nonexistent/falak.sock', 'http://'.$address], 0.25);

        expect($transport->send('/v1/traces', '{"resourceSpans":[]}'))->toBeTrue();

        $requests = receivedRequests($dir, 1);
        expect($requests[0])->toStartWith("POST /v1/traces HTTP/1.1\r\n")->toEndWith('{"resourceSpans":[]}');
    } finally {
        proc_terminate($process);
    }
});

it('sends large payloads completely', function () {
    $socket = sys_get_temp_dir().'/falak-'.bin2hex(random_bytes(4)).'.sock';
    [$process, $dir] = startFakeOtlpServer('unix://'.$socket);

    try {
        $body = json_encode(['resourceSpans' => [], 'pad' => str_repeat('x', 2_000_000)]);
        expect((new SocketTransport(['unix:'.$socket], 2.0))->send('/v1/traces', $body))->toBeTrue();

        $requests = receivedRequests($dir, 1);
        expect(explode("\r\n\r\n", $requests[0], 2)[1])->toBe($body);
    } finally {
        proc_terminate($process);
        @unlink($socket);
    }
});
