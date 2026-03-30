use fium_transport::BootRoute;
use std::collections::BTreeMap;

#[derive(Debug, Clone)]
pub struct RouteEntry {
    pub method: String,
    pub path: String,
    pub name: String,
}

#[derive(Debug, Clone)]
pub struct RouteTable {
    routes: Vec<RouteEntry>,
}

#[derive(Debug, Clone)]
pub struct RouteMatch {
    pub route: RouteEntry,
    pub params: BTreeMap<String, String>,
}

impl RouteTable {
    pub fn from_boot_routes(boot_routes: Vec<BootRoute>) -> Result<Self, String> {
        if boot_routes.is_empty() {
            return Err("worker boot message contained no routes".to_string());
        }

        let routes = boot_routes
            .into_iter()
            .map(|br| RouteEntry {
                method: br.method,
                path: br.path,
                name: br.name,
            })
            .collect();

        Ok(Self { routes })
    }

    /// Return a list of (method, path, name) for the boot banner.
    pub fn list(&self) -> Vec<(&str, &str, &str)> {
        self.routes.iter().map(|r| (r.method.as_str(), r.path.as_str(), r.name.as_str())).collect()
    }

    pub fn match_route(&self, method: &str, path: &str) -> Option<RouteMatch> {
        self.routes.iter().find_map(|route| {
            if route.method != method {
                return None;
            }

            match_path(&route.path, path).map(|params| RouteMatch {
                route: route.clone(),
                params,
            })
        })
    }
}

fn match_path(pattern: &str, actual: &str) -> Option<BTreeMap<String, String>> {
    let pattern_segments = split_segments(pattern);
    let actual_segments = split_segments(actual);

    if pattern_segments.len() != actual_segments.len() {
        return None;
    }

    let mut params = BTreeMap::new();

    for (pattern_segment, actual_segment) in pattern_segments.iter().zip(actual_segments.iter()) {
        if let Some(param_name) = extract_param_name(pattern_segment) {
            params.insert(param_name.to_string(), (*actual_segment).to_string());
            continue;
        }

        if pattern_segment != actual_segment {
            return None;
        }
    }

    Some(params)
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

fn extract_param_name(segment: &str) -> Option<&str> {
    segment
        .strip_prefix('{')
        .and_then(|value| value.strip_suffix('}'))
        .filter(|value| !value.is_empty())
}
