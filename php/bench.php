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

    // Rate-limit coherence probe: limit 60/min. With N workers:
    //   FIUM_NATIVE_RATELIMIT=0 (Before) -> per-worker limit -> ~60xN allowed (incoherent).
    //   FIUM_NATIVE_RATELIMIT=1 (After)  -> Rust pool-wide  -> exactly 60 allowed, then 429.
    'GET /bench/limited' => [
        'middleware' => ['ratelimit:60'],
        'handler' => fn(Request $r) => Response::json(['ok' => true]),
    ],

    // Flood probe: limit 1/min. After the first allowed request the rest are 429s —
    // served by PHP (Before) or by Rust (After). Used to measure rejection throughput
    // and to prove the PHP pool is protected under a flood (requests_total stays flat).
    'GET /bench/flood' => [
        'middleware' => ['ratelimit:1'],
        'handler' => fn(Request $r) => Response::json(['ok' => true]),
    ],

    // Security-headers probe: a route whose response headers Rust stamps natively when
    // FIUM_NATIVE_SECURITY_HEADERS=1 (the PHP SecurityHeaders middleware then defers).
    'GET /bench/secure' => [
        'middleware' => ['security-headers'],
        'handler' => fn(Request $r) => Response::json(['secure' => true]),
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
