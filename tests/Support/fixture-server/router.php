<?php

// Local fixture server for CurlTransport tests (bound to 127.0.0.1 only).
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

switch ($path) {
    case '/ok':
        header('Content-Type: text/html; charset=utf-8');
        echo '<html><head><title>Fixture</title></head><body>ok</body></html>';
        break;
    case '/big':
        header('Content-Type: text/plain');
        $chunk = str_repeat('A', 65536);
        for ($i = 0; $i < 64; $i++) { // 4 MiB
            echo $chunk;
            flush();
        }
        break;
    case '/gzip-bomb':
        header('Content-Type: text/plain');
        header('Content-Encoding: gzip');
        echo gzencode(str_repeat("\0", 8 * 1024 * 1024), 9); // ~8 KiB on the wire, 8 MiB decoded
        break;
    case '/slow':
        sleep(3);
        echo 'late';
        break;
    case '/redirect':
        header('Location: http://169.254.169.254/latest/meta-data/', true, 302);
        break;
    default:
        http_response_code(404);
}
