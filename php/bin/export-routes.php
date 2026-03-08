<?php

declare(strict_types=1);

$routesPath = __DIR__ . '/../routes.php';
$manifestPath = __DIR__ . '/../routes.json';

$routes = require $routesPath;

if (!is_array($routes)) {
    fwrite(STDERR, "Route source did not return an array\n");
    exit(1);
}

foreach ($routes as $index => $route) {
    if (!is_array($route)) {
        fwrite(STDERR, "Route at index {$index} is not an array\n");
        exit(1);
    }

    foreach (['method', 'path', 'name', 'handler'] as $requiredKey) {
        if (!array_key_exists($requiredKey, $route)) {
            fwrite(STDERR, "Route at index {$index} is missing required key '{$requiredKey}'\n");
            exit(1);
        }
    }

    if (array_key_exists('middleware', $route) && !is_array($route['middleware'])) {
        fwrite(STDERR, "Route at index {$index} has a non-array middleware value\n");
        exit(1);
    }
}

$json = json_encode($routes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

if ($json === false) {
    fwrite(STDERR, "Failed to encode route manifest\n");
    exit(1);
}

$result = file_put_contents($manifestPath, $json . PHP_EOL);

if ($result === false) {
    fwrite(STDERR, "Failed to write route manifest\n");
    exit(1);
}

fwrite(STDOUT, "Wrote route manifest to {$manifestPath}\n");