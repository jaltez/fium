use fium_transport::BootRoute;
use std::collections::{BTreeMap, HashMap};

#[derive(Debug, Clone)]
pub struct RouteEntry {
    pub method: String,
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

impl RouteTable {
    pub fn from_boot_routes(boot_routes: Vec<BootRoute>) -> Result<Self, String> {
        if boot_routes.is_empty() {
            return Err("worker boot message contained no routes".to_string());
        }

        let mut by_method: HashMap<String, Vec<RouteEntry>> = HashMap::new();

        for br in boot_routes {
            let is_static = !br.path.contains('{');
            let segments = if is_static {
                Vec::new() // static routes skip segment splitting
            } else {
                split_segments_owned(&br.path)
            };

            let methods = by_method.entry(br.method).or_default();
            // Push static routes first so the fast-path hits early.
            if is_static {
                methods.insert(0, RouteEntry {
                    method: String::new(), // filled below
                    name: br.name,
                    path: br.path,
                    is_static,
                    segments,
                });
            } else {
                methods.push(RouteEntry {
                    method: String::new(),
                    name: br.name,
                    path: br.path,
                    is_static,
                    segments,
                });
            }
        }

        // Fill in the method field (won't compile without clone, do it after).
        for (method, routes) in by_method.iter_mut() {
            for route in routes.iter_mut() {
                route.method = method.clone();
            }
        }

        Ok(Self { by_method })
    }

    pub fn list(&self) -> Vec<(&str, &str, &str)> {
        let mut all: Vec<(&str, &str, &str)> = self.by_method
            .values()
            .flatten()
            .map(|r| (r.method.as_str(), r.path.as_str(), r.name.as_str()))
            .collect();
        // Stable ordering for display.
        all.sort_by_key(|(_, _, name)| *name);
        all
    }

    pub fn match_route(&self, method: &str, path: &str) -> Option<RouteMatch> {
        let candidates = self.by_method.get(method)?;

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

            // Parameterised route — split actual path segments and compare.
            let actual_segments = split_segments(path);
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
