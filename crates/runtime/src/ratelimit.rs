//! Native (Rust-side) rate limiting.
//!
//! Mirrors the semantics of the PHP `RateLimit` middleware but lives in the one Rust
//! process that owns the worker pool, so counters are coherent across all workers
//! (fixing the per-worker `ratelimit x N` behavior). When the limit is exceeded the
//! `429` is served from Rust with no PHP round-trip, so a flood to a limited route can
//! no longer load the PHP pool.

use std::collections::HashMap;
use std::sync::Mutex;
use std::time::{Duration, Instant};

/// Parsed `ratelimit` directive: `max` requests per `window`.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct RateLimitConfig {
    pub max: u32,
    pub window: Duration,
}

/// Parse a middleware alias such as `ratelimit:60`, `ratelimit:100,300`, or a bare
/// `ratelimit`. Returns `None` for anything that isn't a rate-limit directive.
pub fn parse_rate_limit_alias(alias: &str) -> Option<RateLimitConfig> {
    let rest = match alias {
        a if a.starts_with("ratelimit:") => a.strip_prefix("ratelimit:").unwrap(),
        "ratelimit" => "",
        _ => return None,
    };

    let mut parts = rest.split(',');
    let max = parts
        .next()
        .filter(|s| !s.is_empty())
        .and_then(|s| s.trim().parse::<u32>().ok())
        .map(|m| m.max(1))
        .unwrap_or(60);
    let window_secs = parts
        .next()
        .and_then(|s| s.trim().parse::<u64>().ok())
        .unwrap_or(60)
        .max(1);

    Some(RateLimitConfig {
        max,
        window: Duration::from_secs(window_secs),
    })
}

/// Outcome of a rate-limit check.
pub enum Allow {
    /// Request is allowed; carry these onto the response headers.
    Allowed { limit: u32, remaining: u32 },
    /// Request exceeds the limit; serve a 429 with this retry-after (seconds).
    Denied { retry_after_secs: u64 },
}

struct Bucket {
    count: u32,
    /// This bucket's window length, captured from the route config. Stored per-bucket so
    /// the purge below evicts on the route's own window — a `ratelimit:N,86400` bucket
    /// must not be dropped after 300s, its window hasn't elapsed yet.
    window: Duration,
    window_start: Instant,
}

/// Pool-wide rate limiter. Counters are keyed by `(route_name, ip)` — an intentional
/// improvement over the PHP middleware's per-IP-only keying, which shared a single
/// counter across routes with different limits. For a single route the two are
/// observationally identical.
///
/// NOTE(world-b prod-readiness): a single `Mutex` serializes every check across all routes
/// and IPs — fine for the spike, but a contention point under real load. Shard by IP hash
/// or move to a lock-free concurrent map before this becomes default-on. Also in-memory
/// only: independent counters per Fium instance (no multi-instance / Redis backend yet).
#[derive(Default)]
pub struct RateLimiter {
    buckets: Mutex<HashMap<(String, String), Bucket>>,
}

impl RateLimiter {
    pub fn new() -> Self {
        Self::default()
    }

    /// Account one request for `(route_name, ip)` against `cfg`. Lock is held only for
    /// the duration of this synchronous call (no await while held).
    pub fn check(&self, route_name: &str, ip: &str, cfg: &RateLimitConfig) -> Allow {
        let now = Instant::now();
        let mut buckets = self.buckets.lock().expect("rate limiter mutex poisoned");

        // Bound memory: if the table is large, drop buckets whose own window has elapsed.
        // NOTE(world-b prod-readiness): this O(n) retain runs under the lock on every check
        // past the threshold — an attacker churning >4096 IPs can turn the limiter into a
        // contention/CPU DoS. Replace with a periodic background sweep or a size-bounded
        // concurrent map (see BENCHMARKS.md).
        if buckets.len() > 4096 {
            buckets.retain(|_, bucket| now.duration_since(bucket.window_start) < bucket.window);
        }

        let bucket = buckets
            .entry((route_name.to_string(), ip.to_string()))
            .or_insert(Bucket {
                count: 0,
                window: cfg.window,
                window_start: now,
            });

        // Reset the window if it has elapsed; refresh the stored window in case the route
        // config changed (dev reload).
        if now.duration_since(bucket.window_start) >= cfg.window {
            bucket.count = 0;
            bucket.window = cfg.window;
            bucket.window_start = now;
        }

        // PHP semantics: increment first, then deny when count exceeds the limit.
        bucket.count = bucket.count.saturating_add(1);
        if bucket.count > cfg.max {
            let elapsed = now.duration_since(bucket.window_start);
            let remaining_window = cfg.window.saturating_sub(elapsed);
            Allow::Denied {
                retry_after_secs: remaining_window.as_secs().max(1),
            }
        } else {
            let remaining = cfg.max.saturating_sub(bucket.count);
            Allow::Allowed {
                limit: cfg.max,
                remaining,
            }
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn parse_alias_variants() {
        assert_eq!(
            parse_rate_limit_alias("ratelimit:60"),
            Some(RateLimitConfig {
                max: 60,
                window: Duration::from_secs(60)
            })
        );
        assert_eq!(
            parse_rate_limit_alias("ratelimit:100,300"),
            Some(RateLimitConfig {
                max: 100,
                window: Duration::from_secs(300)
            })
        );
        assert_eq!(
            parse_rate_limit_alias("ratelimit"),
            Some(RateLimitConfig {
                max: 60,
                window: Duration::from_secs(60)
            })
        );
        // Zero clamps to 1, matching the PHP middleware.
        assert_eq!(
            parse_rate_limit_alias("ratelimit:0"),
            Some(RateLimitConfig {
                max: 1,
                window: Duration::from_secs(60)
            })
        );
        assert!(parse_rate_limit_alias("cors").is_none());
        assert!(parse_rate_limit_alias("auth").is_none());
    }

    #[test]
    fn allows_up_to_max_then_denies() {
        let limiter = RateLimiter::new();
        let cfg = RateLimitConfig {
            max: 2,
            window: Duration::from_secs(60),
        };

        assert!(matches!(
            limiter.check("r", "1.2.3.4", &cfg),
            Allow::Allowed {
                limit: 2,
                remaining: 1
            }
        ));
        assert!(matches!(
            limiter.check("r", "1.2.3.4", &cfg),
            Allow::Allowed {
                limit: 2,
                remaining: 0
            }
        ));
        assert!(matches!(
            limiter.check("r", "1.2.3.4", &cfg),
            Allow::Denied { .. }
        ));
    }

    #[test]
    fn keys_are_per_route_and_per_ip() {
        let limiter = RateLimiter::new();
        let cfg = RateLimitConfig {
            max: 1,
            window: Duration::from_secs(60),
        };

        // Same route, different IPs: each gets its own bucket.
        assert!(matches!(
            limiter.check("r", "10.0.0.1", &cfg),
            Allow::Allowed { .. }
        ));
        assert!(matches!(
            limiter.check("r", "10.0.0.2", &cfg),
            Allow::Allowed { .. }
        ));
        // Same IP again on the same route is now denied.
        assert!(matches!(
            limiter.check("r", "10.0.0.1", &cfg),
            Allow::Denied { .. }
        ));
        // Same IP on a different route gets a fresh bucket.
        assert!(matches!(
            limiter.check("other", "10.0.0.1", &cfg),
            Allow::Allowed { .. }
        ));
    }

    #[test]
    fn window_reset_re_allows() {
        let limiter = RateLimiter::new();
        let cfg = RateLimitConfig {
            max: 1,
            window: Duration::from_millis(20),
        };

        assert!(matches!(
            limiter.check("r", "1.2.3.4", &cfg),
            Allow::Allowed { .. }
        ));
        assert!(matches!(
            limiter.check("r", "1.2.3.4", &cfg),
            Allow::Denied { .. }
        ));

        std::thread::sleep(Duration::from_millis(30));
        // Window has elapsed: counter resets and the request is allowed again.
        assert!(matches!(
            limiter.check("r", "1.2.3.4", &cfg),
            Allow::Allowed { .. }
        ));
    }
}
