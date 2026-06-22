<?php

declare(strict_types=1);

namespace Fium\Middleware;

use Fium\Contracts\Middleware;
use Fium\Runtime\Request;
use Fium\Runtime\Response;

/**
 * Simple in-memory rate limiter per client IP.
 * Usage: 'ratelimit:60' = 60 requests per minute per IP.
 */
final class RateLimit implements Middleware
{
    /** @var array<string, array{count: int, window_start: int}> */
    private static array $buckets = [];

    private int $maxRequests;
    private int $windowSeconds;

    public function __construct(string $limit = '60')
    {
        $parts = explode(',', $limit, 2);
        $this->maxRequests = max(1, (int) ($parts[0] ?? 60));
        $this->windowSeconds = max(1, (int) ($parts[1] ?? 60));
    }

    public function handle(Request $request, callable $next): Response
    {
        // When the Rust runtime enforces rate limits natively (FIUM_NATIVE_RATELIMIT),
        // defer to it: Rust's counters are coherent across the whole worker pool, so
        // this per-worker limiter would otherwise both double-count and drift
        // (the documented ratelimit x N behavior).
        if (\Fium\Config::bool('FIUM_NATIVE_RATELIMIT')) {
            return $next($request);
        }

        $ip = $request->clientIp() ?? 'unknown';
        $now = time();

        // Purge expired entries every 256 requests to prevent unbounded growth
        if (count(self::$buckets) > 256) {
            self::$buckets = array_filter(
                self::$buckets,
                static fn (array $b): bool => ($now - $b['window_start']) < 300,
            );
        }

        if (!isset(self::$buckets[$ip]) || ($now - self::$buckets[$ip]['window_start']) >= $this->windowSeconds) {
            self::$buckets[$ip] = ['count' => 0, 'window_start' => $now];
        }

        self::$buckets[$ip]['count']++;

        if (self::$buckets[$ip]['count'] > $this->maxRequests) {
            $retryAfter = self::$buckets[$ip]['window_start'] + $this->windowSeconds - $now;

            return Response::json([
                'ok' => false,
                'error' => 'rate_limit_exceeded',
            ], 429)->withHeader('Retry-After', (string) max(1, $retryAfter));
        }

        $response = $next($request);

        $remaining = $this->maxRequests - self::$buckets[$ip]['count'];

        return $response
            ->withHeader('X-RateLimit-Limit', (string) $this->maxRequests)
            ->withHeader('X-RateLimit-Remaining', (string) max(0, $remaining));
    }
}
