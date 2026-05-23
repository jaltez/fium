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

// Boot protocol: send route manifest as first message
fwrite($stdout, $application->bootManifest() . PHP_EOL);
fflush($stdout);

while (($line = fgets($stdin)) !== false) {
    $request = json_decode(trim($line), true);

    if (!is_array($request)) {
        fwrite($stdout, json_encode([
            'protocol_version' => 1,
            'request_id' => null,
            'status' => 500,
            'headers' => ['content-type' => ['application/json']],
            'cookies' => [],
            'body' => '{"ok":false,"error":"invalid_request"}',
            'error' => ['kind' => 'invalid_request', 'message' => 'Malformed request frame'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
        fflush($stdout);
        continue;
    }

    // Validate protocol version before dispatching.
    $protocolVersion = isset($request['protocol_version']) ? (int) $request['protocol_version'] : 0;
    if ($protocolVersion !== 1) {
        fwrite($stdout, json_encode([
            'protocol_version' => 1,
            'request_id' => $request['request_id'] ?? null,
            'status' => 500,
            'headers' => ['content-type' => ['application/json']],
            'cookies' => [],
            'body' => '{"ok":false,"error":"protocol_version_mismatch"}',
            'error' => ['kind' => 'protocol_version_mismatch', 'message' => "Protocol version {$protocolVersion} is not supported"],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
        fflush($stdout);
        continue;
    }

    $response = $application->handleWorkerRequest($request);

    fwrite($stdout, json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    fflush($stdout);
}
