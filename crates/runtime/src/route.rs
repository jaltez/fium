use serde::Deserialize;
use std::{fs, path::Path};

#[derive(Debug, Clone, Deserialize)]
pub struct RouteEntry {
    pub method: String,
    pub path: String,
    pub name: String,
    pub handler: String,
}

#[derive(Debug, Clone)]
pub struct RouteTable {
    routes: Vec<RouteEntry>,
}

impl RouteTable {
    pub fn load_from_file(path: impl AsRef<Path>) -> Result<Self, String> {
        let path = path.as_ref();
        let raw = fs::read_to_string(path)
            .map_err(|error| format!("failed to read route manifest '{}': {error}", path.display()))?;
        let routes: Vec<RouteEntry> = serde_json::from_str(&raw)
            .map_err(|error| format!("failed to parse route manifest '{}': {error}", path.display()))?;

        if routes.is_empty() {
            return Err(format!("route manifest '{}' contained no routes", path.display()));
        }

        Ok(Self { routes })
    }

    pub fn phase_one() -> Self {
        Self {
            routes: vec![
                RouteEntry {
                    method: "GET".to_string(),
                    path: "/".to_string(),
                    name: "home".to_string(),
                    handler: "Fium\\Handlers\\HomeHandler".to_string(),
                },
                RouteEntry {
                    method: "GET".to_string(),
                    path: "/api/me".to_string(),
                    name: "api.me".to_string(),
                    handler: "Fium\\Handlers\\ApiMeHandler".to_string(),
                },
            ],
        }
    }

    pub fn load_or_phase_one(path: impl AsRef<Path>) -> Self {
        match Self::load_from_file(path) {
            Ok(routes) => routes,
            Err(_) => Self::phase_one(),
        }
    }

    pub fn match_route(&self, method: &str, path: &str) -> Option<&RouteEntry> {
        self.routes
            .iter()
            .find(|route| route.method == method && route.path == path)
    }
}
