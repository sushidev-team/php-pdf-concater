use std::panic::{AssertUnwindSafe, catch_unwind};

#[cfg(test)]
#[path = "boundary_tests.rs"]
mod tests;

// Keep unwinding on the Rust side of the Zend boundary. Tests exercise recovery
// without provoking a parser panic or exposing a test hook in the PHP extension.
pub fn run(
    operation: &mut dyn FnMut() -> anyhow::Result<crate::Metrics>,
) -> Result<crate::Metrics, String> {
    match catch_unwind(AssertUnwindSafe(operation)) {
        Ok(Ok(value)) => Ok(value),
        Ok(Err(error)) => Err(format!("PDF merge failed: {error:#}")),
        Err(_) => Err("PDF merge aborted due to an internal Rust panic".into()),
    }
}
