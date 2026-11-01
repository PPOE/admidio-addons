<?php

// SPDX-License-Identifier: GPL-2.0-only
//
// Test only: a minimal HTTPS server for tests/accsync-test.php.
// php https-server.php <port> <certificate and key PEM> <token> <document file>
//   /mbapi.php  200 and the document file, or 403 without "Bearer <token>"
//   /error      500 (with a valid body)
//   /redirect   302 to /mbapi.php
//   /slow       200 after 3 seconds
//   /noreason   as /mbapi.php, with the status line "HTTP/1.0 200" (no reason)
//   /status2000 status line "HTTP/1.0 2000 OK" (with a valid body)
//   /big        200 and a valid document of 1 MiB + 1 byte
// One request at a time; HTTP/1.0, the connection is closed after each.

[, $port, $pem, $token, $document] = $argv;
$server = stream_socket_server(
    "ssl://127.0.0.1:$port",
    $errno,
    $error,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
    stream_context_create(['ssl' => ['local_cert' => $pem]]),
);
if ($server === false) {
    fwrite(STDERR, "https-server: $error\n");
    exit(1);
}
while (true) {
    // False when the client rejects the certificate during the handshake.
    $connection = @stream_socket_accept($server, 3600);
    if ($connection === false) {
        continue;
    }
    $head = [];
    while (($line = fgets($connection)) !== false && rtrim($line) !== '') {
        $head[] = rtrim($line);
    }
    $path = explode(' ', $head[0] ?? '')[1] ?? '';
    $headers = '';
    $body = '[]';
    if ($path === '/mbapi.php' || $path === '/noreason') {
        if (in_array("Authorization: Bearer $token", $head, true)) {
            $status = $path === '/noreason' ? '200' : '200 OK';
            $body = file_get_contents($document);
        } else {
            $status = '403 Forbidden';
            $body = '';
        }
    } elseif ($path === '/error') {
        $status = '500 Internal Server Error';
    } elseif ($path === '/redirect') {
        $status = '302 Found';
        $headers = "Location: https://127.0.0.1:$port/mbapi.php\r\n";
    } elseif ($path === '/status2000') {
        $status = '2000 OK';
    } elseif ($path === '/big') {
        $status = '200 OK';
        $body = '[' . str_repeat(' ', 1048576 - 1) . ']';
    } elseif ($path === '/slow') {
        sleep(3);
        $status = '200 OK';
    } else {
        $status = '404 Not Found';
    }
    @fwrite($connection, "HTTP/1.0 $status\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body)
        . "\r\n{$headers}Connection: close\r\n\r\n$body");
    fclose($connection);
}
