<?php

declare(strict_types=1);

/**
 * A tiny HTTP responder for GeoipDownloadTest, run under `php -S`.
 *
 * MaxMindDownloader's real curl() transport has no URL seam of its own — the
 * endpoint is a private constant — so the only way to exercise it without
 * reaching MaxMind is to invoke curl() directly (via reflection) against a
 * server that actually speaks HTTP. This is that server's request handler.
 */
/**
 * A query parameter as an int, or a default when it is absent or not numeric.
 */
function queryInt(string $key, int $default): int
{
    $value = $_GET[$key] ?? null;

    return is_numeric($value) ? (int) $value : $default;
}

$case = $_GET['case'] ?? 'ok';

switch ($case) {
    case 'ok':
        $size = queryInt('size', 1024);
        header('Content-Type: application/octet-stream');
        echo str_repeat('x', $size);
        break;

    case 'slow':
        $chunk = queryInt('chunk', 200000);
        $chunks = queryInt('chunks', 5);
        header('Content-Type: application/octet-stream');
        header('Content-Length: '.($chunk * $chunks));

        for ($i = 0; $i < $chunks; $i++) {
            echo str_repeat('y', $chunk);
            @ob_flush();
            flush();
            usleep(120_000);
        }
        break;

    case 'unauthorized':
        http_response_code(401);
        echo 'no';
        break;

    default:
        http_response_code(404);
}
