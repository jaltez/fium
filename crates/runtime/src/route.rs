use fium_transport::BootRoute;
use std::collections::{BTreeMap, HashMap};
use thiserror::Error;

#[derive(Debug, Clone)]
pub struct RouteEntry {
    pub name: String,
    pub path: String,
    pub is_static: bool,
    pub segments: Vec<String>,
}

#[derive(Debug, Clone)]
pub struct RouteTable {
    /// Routes keyed by HTTP method for O(1) lookup per method.
    by_method: HashMap<String, Vec<RouteEntry>>,
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
    pub fn from_boot_routes(boot_routes: Vec<BootRoute>) -> Result<Self, RouteError> {
        if boot_routes.is_empty() {
            return Err(RouteError::EmptyBootRoutes);
        }

        let mut by_method: HashMap<String, Vec<RouteEntry>> = HashMap::new();

        for br in boot_routes {
            let is_static = !br.path.contains('{');
            let segments = if is_static {
                Vec::new() // static routes skip segment splitting
            } else {
                split_segments_owned(&br.path)
            };

            by_method.entry(br.method).or_default().push(RouteEntry {
                name: br.name,
                path: br.path,
                is_static,
                segments,
            });
        }

        for routes in by_method.values_mut() {
            routes.sort_by_key(|route| !route.is_static);
        }

        Ok(Self { by_method })
    }

    pub fn list(&self) -> Vec<(&str, &str, &str)> {
        let mut all: Vec<(&str, &str, &str)> = self.by_method
            .iter()
            .flat_map(|(method, routes)| {
                routes.iter().map(move |route| (method.as_str(), route.path.as_str(), route.name.as_str()))
            })
            .collect();
        // Stable ordering for display.
        all.sort_by_key(|(_, _, name)| *name);
        all
    }

    pub fn match_route(&self, method: &str, path: &str) -> Option<RouteMatch> {
        let candidates = self.by_method.get(method)?;
        let mut actual_segments = None;

        // Fast path: exact match for static routes.
        for route in candidates {
            if route.is_static {
                if route.path == path {
                    return Some(RouteMatch {
                        route_name: route.name.clone(),
                        params: BTreeMap::new(),
                    });
                }
                continue;
            }

            let actual_segments = actual_segments.get_or_insert_with(|| split_segments(path));
            if actual_segments.len() != route.segments.len() {
                continue;
            }

            let mut params = BTreeMap::new();
            let mut matched = true;

            for (pseg, aseg) in route.segments.iter().zip(actual_segments.iter()) {
                if let Some(param_name) = extract_param_name(pseg) {
                    params.insert(param_name.to_string(), (*aseg).to_string());
                } else if pseg != aseg {
                    matched = false;
                    break;
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

fn split_segments_owned(path: &str) -> Vec<String> {
    if path == "/" {
        return Vec::new();
    }

    path.trim_matches('/')
        .split('/')
        .filter(|segment| !segment.is_empty())
        .map(ToString::to_string)
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

        RouteTable::from_boot_routes(boot_routes).expect("route table should build")
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
}
