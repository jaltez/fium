use crate::ratelimit::{parse_rate_limit_alias, RateLimitConfig};
use fium_transport::{BootCors, BootRoute};
use std::collections::{BTreeMap, HashMap, HashSet};
use thiserror::Error;

#[derive(Debug, Clone)]
enum RouteSegment {
    Static(String),
    Param(String),
}

#[derive(Debug, Clone)]
pub struct RouteEntry {
    pub name: String,
    segments: Vec<RouteSegment>,
}

#[derive(Debug, Clone, Default)]
struct MethodRoutes {
    static_routes: HashMap<String, String>,
    dynamic_routes: HashMap<usize, Vec<RouteEntry>>,
}

#[derive(Debug, Clone)]
struct ListedRoute {
    method: String,
    path: String,
    name: String,
}

#[derive(Debug, Clone)]
pub struct RouteTable {
    /// Routes keyed by HTTP method for O(1) lookup per method.
    by_method: HashMap<String, MethodRoutes>,
    routes: Vec<ListedRoute>,
    /// Static paths that carry the `cors` middleware, so Rust can answer
    /// OPTIONS preflights itself without a PHP round-trip.
    cors_paths: HashSet<String>,
    /// Routes that declare a `ratelimit` directive, with the parsed config, so Rust
    /// can enforce the limit pool-wide without a PHP round-trip.
    rate_limits: HashMap<String, RateLimitConfig>,
    /// Global CORS config resolved by PHP, used by Rust's native preflight path.
    cors: Option<BootCors>,
}

#[derive(Debug, Clone)]
pub struct RouteMatch {
    pub route_name: String,
    pub params: BTreeMap<String, String>,
}

#[derive(Debug, Error)]
pub enum RouteError {
    #[error("worker boot message contained no routes")]
    EmptyBootRoutes,
}

impl RouteTable {
    pub fn from_boot_routes(
        boot_routes: Vec<BootRoute>,
        cors: Option<BootCors>,
    ) -> Result<Self, RouteError> {
        if boot_routes.is_empty() {
            return Err(RouteError::EmptyBootRoutes);
        }

        let mut by_method: HashMap<String, MethodRoutes> = HashMap::new();
        let mut routes = Vec::with_capacity(boot_routes.len());
        let mut cors_paths = HashSet::new();
        let mut rate_limits = HashMap::new();

        for br in boot_routes {
            let method = br.method;
            let path = br.path;
            let name = br.name;
            let method_routes = by_method.entry(method.clone()).or_default();

            // Record static paths protected by CORS so preflights can be served from Rust.
            if is_static_path(&path) && br.middleware.iter().any(|m| m == "cors") {
                cors_paths.insert(path.clone());
            }

            // Parse the first ratelimit directive on the route, if any.
            if let Some(alias) = br
                .middleware
                .iter()
                .find_map(|m| parse_rate_limit_alias(m.as_str()))
            {
                rate_limits.insert(name.clone(), alias);
            }

            if is_static_path(&path) {
                method_routes
                    .static_routes
                    .insert(path.clone(), name.clone());
            } else {
                let segments = parse_segments_owned(&path);
                method_routes
                    .dynamic_routes
                    .entry(segments.len())
                    .or_default()
                    .push(RouteEntry {
                        name: name.clone(),
                        segments,
                    });
            }

            routes.push(ListedRoute { method, path, name });
        }

        routes.sort_by(|left, right| left.name.cmp(&right.name));

        Ok(Self {
            by_method,
            routes,
            cors_paths,
            rate_limits,
            cors,
        })
    }

    pub fn list(&self) -> Vec<(&str, &str, &str)> {
        self.routes
            .iter()
            .map(|route| {
                (
                    route.method.as_str(),
                    route.path.as_str(),
                    route.name.as_str(),
                )
            })
            .collect()
    }

    pub fn match_route(&self, method: &str, path: &str) -> Option<RouteMatch> {
        let method_routes = self.by_method.get(method)?;

        if let Some(route_name) = method_routes.static_routes.get(path) {
            return Some(RouteMatch {
                route_name: route_name.clone(),
                params: BTreeMap::new(),
            });
        }

        let actual_segments = split_segments(path);
        let candidates = method_routes.dynamic_routes.get(&actual_segments.len())?;

        for route in candidates {
            let mut params = BTreeMap::new();
            let mut matched = true;

            for (pseg, aseg) in route.segments.iter().zip(actual_segments.iter()) {
                match pseg {
                    RouteSegment::Static(segment) => {
                        if segment != aseg {
                            matched = false;
                            break;
                        }
                    }
                    RouteSegment::Param(param_name) => {
                        params.insert(param_name.clone(), (*aseg).to_string());
                    }
                }
            }

            if matched {
                return Some(RouteMatch {
                    route_name: route.name.clone(),
                    params,
                });
            }
        }

        None
    }

    /// Whether `path` is a static route protected by the `cors` middleware, meaning
    /// Rust can answer an OPTIONS preflight for it without dispatching to PHP.
    /// Note: dynamic (parameterized) cors paths are not matched here yet — see
    /// BENCHMARKS.md — so this is currently exact-path only.
    pub fn cors_preflight_target(&self, path: &str) -> bool {
        self.cors_paths.contains(path)
    }

    /// The rate-limit directive declared on `route_name`, if any, so Rust can enforce
    /// it pool-wide without dispatching to PHP.
    pub fn rate_limit_for(&self, route_name: &str) -> Option<&RateLimitConfig> {
        self.rate_limits.get(route_name)
    }

    /// Global CORS config resolved by PHP, for Rust's native preflight path. `None` if
    /// the worker didn't advertise any (older worker).
    pub fn cors(&self) -> Option<&BootCors> {
        self.cors.as_ref()
    }
}

fn split_segments(path: &str) -> Vec<&str> {
    if path == "/" {
        return Vec::new();
    }

    path.trim_matches('/')
        .split('/')
        .filter(|segment| !segment.is_empty())
        .collect()
}

fn is_static_path(path: &str) -> bool {
    !path.contains('{')
}

fn parse_segments_owned(path: &str) -> Vec<RouteSegment> {
    if path == "/" {
        return Vec::new();
    }

    path.trim_matches('/')
        .split('/')
        .filter(|segment| !segment.is_empty())
        .map(|segment| match extract_param_name(segment) {
            Some(param_name) => RouteSegment::Param(param_name.to_string()),
            None => RouteSegment::Static(segment.to_string()),
        })
        .collect()
}

fn extract_param_name(segment: &str) -> Option<&str> {
    segment
        .strip_prefix('{')
        .and_then(|value| value.strip_suffix('}'))
        .filter(|value| !value.is_empty())
}

#[cfg(test)]
mod tests {
    use super::*;
    use fium_transport::BootRoute;

    fn table(routes: &[(&str, &str, &str)]) -> RouteTable {
        let boot_routes = routes
            .iter()
            .map(|(method, path, name)| BootRoute {
                method: (*method).to_string(),
                path: (*path).to_string(),
                name: (*name).to_string(),
                middleware: Vec::new(),
            })
            .collect();

        RouteTable::from_boot_routes(boot_routes, None).expect("route table should build")
    }

    fn table_with_middleware(routes: &[(&str, &str, &str, &[&str])]) -> RouteTable {
        let boot_routes = routes
            .iter()
            .map(|(method, path, name, mw)| BootRoute {
                method: (*method).to_string(),
                path: (*path).to_string(),
                name: (*name).to_string(),
                middleware: mw.iter().map(|s| (*s).to_string()).collect(),
            })
            .collect();

        RouteTable::from_boot_routes(boot_routes, None).expect("route table should build")
    }

    #[test]
    fn static_route_match() {
        let t = table(&[("GET", "/health", "health")]);
        let matched = t.match_route("GET", "/health").expect("route should match");
        assert_eq!(matched.route_name, "health");
    }

    #[test]
    fn static_route_404() {
        let t = table(&[("GET", "/health", "health")]);
        assert!(t.match_route("GET", "/missing").is_none());
    }

    #[test]
    fn method_mismatch() {
        let t = table(&[("GET", "/health", "health")]);
        assert!(t.match_route("POST", "/health").is_none());
    }

    #[test]
    fn parameterized_route() {
        let t = table(&[("GET", "/users/{id}", "users_show")]);
        let matched = t
            .match_route("GET", "/users/42")
            .expect("parameterized route should match");

        assert_eq!(matched.route_name, "users_show");
        assert_eq!(matched.params.get("id").map(String::as_str), Some("42"));
    }

    #[test]
    fn static_before_parameterized() {
        let t = table(&[
            ("GET", "/users/me", "users_me"),
            ("GET", "/users/{id}", "users_show"),
        ]);

        assert_eq!(
            t.match_route("GET", "/users/me")
                .expect("static route should win")
                .route_name,
            "users_me"
        );
        assert_eq!(
            t.match_route("GET", "/users/42")
                .expect("parameterized route should still match")
                .route_name,
            "users_show"
        );
    }

    #[test]
    fn prefers_dynamic_routes_with_matching_segment_count() {
        let t = table(&[
            ("GET", "/teams/{team}", "teams_show"),
            ("GET", "/teams/{team}/members/{member}", "team_members_show"),
        ]);

        let matched = t
            .match_route("GET", "/teams/core/members/javier")
            .expect("four-segment route should match");

        assert_eq!(matched.route_name, "team_members_show");
        assert_eq!(matched.params.get("team").map(String::as_str), Some("core"));
        assert_eq!(
            matched.params.get("member").map(String::as_str),
            Some("javier")
        );
    }

    #[test]
    fn cors_preflight_target_only_for_cors_static_paths() {
        let t = table_with_middleware(&[
            ("POST", "/bench/cors", "bench_cors", &["cors"]),
            ("GET", "/bench/plain", "bench_plain", &[]),
            ("POST", "/api/{id}", "api_dynamic", &["cors"]),
        ]);

        assert!(t.cors_preflight_target("/bench/cors"));
        assert!(!t.cors_preflight_target("/bench/plain"));
        // Dynamic cors paths are not yet matched (documented limitation).
        assert!(!t.cors_preflight_target("/api/42"));
    }

    #[test]
    fn rate_limit_for_parses_directive_from_middleware() {
        let t = table_with_middleware(&[
            ("GET", "/bench/limited", "limited", &["ratelimit:60"]),
            ("GET", "/bench/flood", "flood", &["ratelimit:1,10"]),
            ("GET", "/bench/open", "open", &[]),
        ]);

        let limited = t
            .rate_limit_for("limited")
            .expect("limited route has a directive");
        assert_eq!(limited.max, 60);
        assert_eq!(limited.window, std::time::Duration::from_secs(60));

        let flood = t
            .rate_limit_for("flood")
            .expect("flood route has a directive");
        assert_eq!(flood.max, 1);
        assert_eq!(flood.window, std::time::Duration::from_secs(10));

        assert!(t.rate_limit_for("open").is_none());
    }
}
