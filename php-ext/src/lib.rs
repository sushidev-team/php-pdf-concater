use ext_php_rs::{convert::IntoZval, prelude::*, types::Zval};
use pdf_concater::{Metrics, merge_directory_with_engine, merge_files_with_engine};
use std::{
    collections::HashMap,
    ffi::{CString, c_char, c_int},
    panic::{AssertUnwindSafe, catch_unwind},
    path::{Path, PathBuf},
};

unsafe extern "C" {
    // Exported by PHP's filesystem API. Not in ext-php-rs's generated allowlist.
    fn php_check_open_basedir(path: *const c_char) -> c_int;
}

fn check_path(path: &str) -> PhpResult<()> {
    let path = CString::new(path)
        .map_err(|_| PhpException::from_message("Paths must not contain null bytes".into()))?;
    // SAFETY: called on the PHP request thread with a valid, NUL-terminated string.
    if unsafe { php_check_open_basedir(path.as_ptr()) } != 0 {
        return Err("Path is outside PHP open_basedir".into());
    }
    Ok(())
}

fn metrics_array(m: Metrics) -> PhpResult<HashMap<&'static str, Zval>> {
    let mut values = HashMap::new();
    macro_rules! field {
        ($name:ident) => {
            values.insert(stringify!($name), m.$name.into_zval(false)?);
        };
    }
    field!(files);
    field!(engine);
    field!(pages);
    field!(input_bytes);
    field!(output_bytes);
    field!(threads);
    field!(batch_size);
    field!(discovery_ms);
    field!(load_import_ms);
    field!(stream_write_ms);
    field!(write_sync_ms);
    field!(total_ms);
    Ok(values)
}

/// Merge PDFs in filename order. Returns counts, bytes and timings as a PHP array.
#[php_function]
#[php(defaults(threads = 8, batch_size = 32, engine = "\"streaming\""))]
pub fn pdf_concater_merge_dir(
    directory: String,
    output: String,
    threads: i64,
    batch_size: i64,
    engine: String,
) -> PhpResult<HashMap<&'static str, Zval>> {
    check_path(&directory)?;
    check_path(&output)?;
    if threads <= 0 || batch_size <= 0 {
        return Err(PhpException::from_message(
            "threads and batch_size must be positive".into(),
        ));
    }
    let engine = engine
        .parse::<pdf_concater::Engine>()
        .map_err(|error| PhpException::from_message(error.to_string()))?;
    // Only owned Rust values enter the worker threads. All Zend interaction stays here.
    let result = catch_unwind(AssertUnwindSafe(|| {
        merge_directory_with_engine(
            Path::new(&directory),
            Path::new(&output),
            threads as usize,
            batch_size as usize,
            engine,
        )
    }));
    match result {
        Ok(Ok(metrics)) => metrics_array(metrics),
        Ok(Err(error)) => Err(PhpException::from_message(format!(
            "PDF merge failed: {error:#}"
        ))),
        Err(_) => Err(PhpException::from_message(
            "PDF merge aborted due to an internal Rust panic".into(),
        )),
    }
}

/// Merge an explicit PHP list of filenames in the supplied order.
#[php_function]
#[php(defaults(threads = 8, batch_size = 32, engine = "\"streaming\""))]
pub fn pdf_concater_merge_files(
    files: Vec<String>,
    output: String,
    threads: i64,
    batch_size: i64,
    engine: String,
) -> PhpResult<HashMap<&'static str, Zval>> {
    check_path(&output)?;
    for path in &files {
        check_path(path)?;
    }
    if threads <= 0 || batch_size <= 0 {
        return Err("threads and batch_size must be positive".into());
    }
    let engine = engine
        .parse::<pdf_concater::Engine>()
        .map_err(|error| PhpException::from_message(error.to_string()))?;
    let paths: Vec<_> = files.into_iter().map(PathBuf::from).collect();
    let result = catch_unwind(AssertUnwindSafe(|| {
        merge_files_with_engine(
            &paths,
            Path::new(&output),
            threads as usize,
            batch_size as usize,
            engine,
        )
    }));
    match result {
        Ok(Ok(metrics)) => metrics_array(metrics),
        Ok(Err(error)) => Err(format!("PDF merge failed: {error:#}").into()),
        Err(_) => Err("PDF merge aborted due to an internal Rust panic".into()),
    }
}

#[php_module]
pub fn module(module: ModuleBuilder) -> ModuleBuilder {
    module
        .name("pdf_concater")
        .function(wrap_function!(pdf_concater_merge_dir))
        .function(wrap_function!(pdf_concater_merge_files))
}
