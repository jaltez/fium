<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Fium\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relativePath = str_replace('\\', '/', substr($class, strlen($prefix)));
    $filePath = __DIR__ . '/src/' . $relativePath . '.php';

    if (is_file($filePath)) {
        require $filePath;
    }
});

use Fium\Application;

$routesPath = $argv[1] ?? __DIR__ . '/routes.php';
$application = Application::boot($routesPath);

$stdin = fopen('php://stdin', 'r');
$stdout = fopen('php://stdout', 'w');

if ($stdin === false || $stdout === false) {
    fwrite(STDERR, "Unable to open worker streams\n");
    exit(1);
}

/**
 * Write a length-prefixed JSON frame to a stream.
 * Format: 4-byte big-endian u32 length, followed by the payload.
 */
function write_frame($handle, string $payload): void
{
    $len = strlen($payload);
    $header = pack('N', $len);
    $data = $header . $payload;
    $remaining = strlen($data);
    $offset = 0;
    while ($remaining > 0) {
        $written = fwrite($handle, substr($data, $offset, $remaining));
        if ($written === false) {
            break;
        }
        $offset += $written;
        $remaining -= $written;
    }
}

/**
 * Read a length-prefixed JSON frame from a stream.
 * Returns the decoded array, or null on EOF or malformed frame.
 */
function read_frame($handle): ?array
{
    // Read 4-byte length header
    $header = '';
    while (strlen($header) < 4) {
        $chunk = fread($handle, 4 - strlen($header));
        if ($chunk === false || $chunk === '') {
            return null;
        }
        $header .= $chunk;
    }

    $arr = unpack('N', $header);
    $len = $arr[1];

    if ($len > 16 * 1024 * 1024) {
        // Frame too large — drain and return error
        return null;
    }

    $body = '';
    while (strlen($body) < $len) {
        $chunk = fread($handle, $len - strlen($body));
        if ($chunk === false || $chunk === '') {
            return null;
        }
        $body .= $chunk;
    }

    return json_decode($body, true);
}

// Boot protocol: send route manifest as first message
write_frame($stdout, $application->bootManifest());
fflush($stdout);

while (true) {
    $request = read_frame($stdin);

    if ($request === null) {
        break;
    }

    if (!is_array($request)) {
        write_frame($stdout, json_encode([
            'protocol_version' => 1,
            'request_id' => null,
            'status' => 500,
            'headers' => ['content-type' => ['application/json']],
            'cookies' => [],
            'body' => '{"ok":false,"error":"invalid_request"}',
            'error' => ['kind' => 'invalid_request', 'message' => 'Malformed request frame'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        fflush($stdout);
        continue;
    }

    // Validate protocol version before dispatching.
    $protocolVersion = isset($request['protocol_version']) ? (int) $request['protocol_version'] : 0;
    if ($protocolVersion !== 1) {
        write_frame($stdout, json_encode([
            'protocol_version' => 1,
            'request_id' => $request['request_id'] ?? null,
            'status' => 500,
            'headers' => ['content-type' => ['application/json']],
            'cookies' => [],
            'body' => '{"ok":false,"error":"protocol_version_mismatch"}',
            'error' => ['kind' => 'protocol_version_mismatch', 'message' => "Protocol version {$protocolVersion} is not supported"],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        fflush($stdout);
        continue;
    }

    $response = $application->handleWorkerRequest($request);

    write_frame($stdout, json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    fflush($stdout);
}
