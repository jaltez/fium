use include_dir::{include_dir, Dir};
use std::fs;
use std::path::{Path, PathBuf};

/// The embedded PHP library: worker.php + src/ tree.
static PHP_WORKER: &str = include_str!("../../../php/worker.php");
static PHP_SRC: Dir<'_> = include_dir!("$CARGO_MANIFEST_DIR/../../php/src");

const FNV_OFFSET_BASIS: u64 = 0xcbf29ce484222325;
const FNV_PRIME: u64 = 0x100000001b3;

/// Extract the embedded PHP library to a `.fium/` directory next to the app file.
/// Returns the path to the `.fium/` directory.
/// Skips extraction if `.fium/.version` matches the current binary version.
pub fn extract_php_lib(app_path: &Path) -> anyhow::Result<PathBuf> {
    let app_dir = app_path.parent().unwrap_or_else(|| Path::new("."));
    let fium_dir = app_dir.join(".fium");
    let version_file = fium_dir.join(".version");
    let embed_version = embedded_php_version();

    // Skip extraction if the version matches.
    if fium_dir.exists() && version_file.is_file() {
        let current = fs::read_to_string(&version_file).unwrap_or_default();
        if current.trim() == embed_version {
            tracing::info!(version = %embed_version, "PHP lib up to date, skipping extraction");
            return Ok(fium_dir);
        }
    }

    tracing::info!(version = %embed_version, "extracting PHP library");

    if fium_dir.exists() {
        fs::remove_dir_all(&fium_dir)?;
    }

    fs::create_dir_all(&fium_dir)?;

    // Write worker.php
    fs::write(fium_dir.join("worker.php"), PHP_WORKER)?;

    // Write src/ tree
    extract_dir(&PHP_SRC, &fium_dir.join("src"))?;

    // Write version marker.
    fs::write(&version_file, format!("{embed_version}\n"))?;

    Ok(fium_dir)
}

fn embedded_php_version() -> String {
    let mut hash = FNV_OFFSET_BASIS;
    hash_bytes(&mut hash, PHP_WORKER.as_bytes());
    hash_dir(&mut hash, &PHP_SRC);

    format!("{}-{hash:016x}", env!("CARGO_PKG_VERSION"))
}

fn hash_dir(hash: &mut u64, dir: &Dir<'_>) {
    let mut files = dir.files().collect::<Vec<_>>();
    files.sort_by_key(|file| file.path().to_string_lossy().into_owned());
    for file in files {
        hash_bytes(hash, file.path().to_string_lossy().as_bytes());
        hash_bytes(hash, file.contents());
    }

    let mut subdirs = dir.dirs().collect::<Vec<_>>();
    subdirs.sort_by_key(|subdir| subdir.path().to_string_lossy().into_owned());
    for subdir in subdirs {
        hash_bytes(hash, subdir.path().to_string_lossy().as_bytes());
        hash_dir(hash, subdir);
    }
}

fn hash_bytes(hash: &mut u64, bytes: &[u8]) {
    for byte in bytes {
        *hash ^= u64::from(*byte);
        *hash = hash.wrapping_mul(FNV_PRIME);
    }
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
