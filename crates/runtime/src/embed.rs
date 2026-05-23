use include_dir::{include_dir, Dir};
use std::fs;
use std::path::{Path, PathBuf};

/// The embedded PHP library: worker.php + src/ tree.
static PHP_WORKER: &str = include_str!("../../../php/worker.php");
static PHP_SRC: Dir<'_> = include_dir!("$CARGO_MANIFEST_DIR/../../php/src");

/// A version token that changes when the embedded PHP lib changes.
/// Increment this when you modify php/worker.php or php/src/.
const EMBED_VERSION: &str = env!("CARGO_PKG_VERSION");

/// Extract the embedded PHP library to a `.fium/` directory next to the app file.
/// Returns the path to the `.fium/` directory.
/// Skips extraction if `.fium/.version` matches the current binary version.
pub fn extract_php_lib(app_path: &Path) -> anyhow::Result<PathBuf> {
    let app_dir = app_path
        .parent()
        .unwrap_or_else(|| Path::new("."));
    let fium_dir = app_dir.join(".fium");
    let version_file = fium_dir.join(".version");

    // Skip extraction if the version matches.
    if fium_dir.exists() && version_file.is_file() {
        let current = fs::read_to_string(&version_file).unwrap_or_default();
        if current.trim() == EMBED_VERSION {
            tracing::info!(version = EMBED_VERSION, "PHP lib up to date, skipping extraction");
            return Ok(fium_dir);
        }
    }

    tracing::info!(version = EMBED_VERSION, "extracting PHP library");

    if fium_dir.exists() {
        fs::remove_dir_all(&fium_dir)?;
    }

    fs::create_dir_all(&fium_dir)?;

    // Write worker.php
    fs::write(fium_dir.join("worker.php"), PHP_WORKER)?;

    // Write src/ tree
    extract_dir(&PHP_SRC, &fium_dir.join("src"))?;

    // Write version marker.
    fs::write(&version_file, format!("{EMBED_VERSION}\n"))?;

    Ok(fium_dir)
}

fn extract_dir(dir: &Dir<'_>, target: &Path) -> anyhow::Result<()> {
    fs::create_dir_all(target)?;

    for file in dir.files() {
        let dest = target.join(file.path().file_name().unwrap());
        fs::write(&dest, file.contents())?;
    }

    for subdir in dir.dirs() {
        let dest = target.join(subdir.path().file_name().unwrap());
        extract_dir(subdir, &dest)?;
    }

    Ok(())
}
