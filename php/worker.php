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

$application = Application::boot(__DIR__ . '/routes.php');

$stdin = fopen('php://stdin', 'r');
$stdout = fopen('php://stdout', 'w');

if ($stdin === false || $stdout === false) {
    fwrite(STDERR, "Unable to open worker streams\n");
    exit(1);
}

while (($line = fgets($stdin)) !== false) {
    $request = json_decode(trim($line), true);

    if (!is_array($request)) {
        fwrite($stdout, json_encode([
            'protocol_version' => 1,
            'request_id' => null,
            'status' => 500,
            'headers' => ['content-type' => ['application/json']],
            'cookies' => [],
            'body' => json_encode(['ok' => false, 'error' => 'invalid_request']),
            'error' => ['kind' => 'invalid_request', 'message' => 'Malformed request frame'],
        ]) . PHP_EOL);
        fflush($stdout);
        continue;
    }

    $response = $application->handleWorkerRequest($request);

    fwrite($stdout, json_encode($response) . PHP_EOL);
    fflush($stdout);
}
