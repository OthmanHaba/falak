<?php

/**
 * Fake OTLP/HTTP receiver used by the tests.
 *
 *   php fake-otlp-server.php <listen-uri> <out-dir>
 *
 * listen-uri: unix:///tmp/x.sock or tcp://127.0.0.1:0 (the bound address is written to <out-dir>/address).
 * Each request is stored raw as <out-dir>/<n>.http; responds 200 {}.
 */
[$_, $uri, $out] = $argv;

$server = stream_socket_server($uri, $errno, $errstr);

if ($server === false) {
    fwrite(STDERR, "listen failed: $errstr\n");
    exit(1);
}

file_put_contents("$out/address", stream_socket_get_name($server, false));
$n = 0;

while (true) {
    $conn = @stream_socket_accept($server, 30);

    if ($conn === false) {
        continue;
    }

    $raw = '';

    while (! str_contains($raw, "\r\n\r\n") && ! feof($conn)) {
        $raw .= fread($conn, 8192);
    }

    $length = preg_match('/Content-Length:\s*(\d+)/i', $raw, $m) ? (int) $m[1] : 0;
    $headerEnd = strpos($raw, "\r\n\r\n") + 4;

    while (strlen($raw) - $headerEnd < $length && ! feof($conn)) {
        $raw .= fread($conn, 65536);
    }

    fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: 2\r\nConnection: close\r\n\r\n{}");
    fclose($conn);

    $n++;
    file_put_contents("$out/$n.http.tmp", $raw);
    rename("$out/$n.http.tmp", "$out/$n.http");
}
