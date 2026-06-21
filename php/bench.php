<?php

declare(strict_types=1);

// Benchmark routes for the World-B thesis proof (see scripts/bench-native-cors.sh
// and BENCHMARKS.md). These exist to give the harness stable, comparable targets.

use Fium\Runtime\Request;
use Fium\Runtime\Response;

return [
    // Workload "json": trivial PHP handler. Always crosses into PHP in both modes,
    // so it is the control that establishes the per-request PHP round-trip cost.
    'GET /bench/json' => fn(Request $r) => Response::json(['ok' => true, 'n' => 42]),

    // Advertise CORS on this static path so the boot manifest carries 'cors' for it.
    // Rust's cors_preflight_target() can then answer OPTIONS here when FIUM_NATIVE_CORS=1.
    'POST /bench/cors' => [
        'middleware' => ['cors'],
        'handler' => fn(Request $r) => Response::json(['created' => true]),
    ],

    // Workload "preflight" — the PHP path (Before). Only reached when FIUM_NATIVE_CORS=0.
    // Produces the same 204 + CORS headers as the Rust path so the comparison isolates
    // *who serves it*, not *what is served*.
    'OPTIONS /bench/cors' => function (Request $r): Response {
        return Response::empty(204)
            ->withHeader('Access-Control-Allow-Origin', getenv('FIUM_CORS_ORIGINS') ?: '*')
            ->withHeader(
                'Access-Control-Allow-Methods',
                getenv('FIUM_CORS_METHODS') ?: 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            )
            ->withHeader(
                'Access-Control-Allow-Headers',
                getenv('FIUM_CORS_HEADERS') ?: 'Content-Type, Authorization, Accept, X-Requested-With',
            )
            ->withHeader('Access-Control-Max-Age', getenv('FIUM_CORS_MAX_AGE') ?: '86400');
    },
];
