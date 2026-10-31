#[test]
fn converts_errors_and_panics_without_poisoning_later_calls() {
    let error = super::run(&mut || anyhow::bail!("invalid input"));
    assert!(matches!(error, Err(message) if message.contains("PDF merge failed: invalid input")));
    let panic = super::run(&mut || panic!("simulated parser panic"));
    assert!(matches!(panic, Err(message) if message.contains("internal Rust panic")));
    let temp = tempfile::tempdir().unwrap();
    let source = temp.path().join("sources");
    crate::generate(&source, 1, 1, 64, None).unwrap();
    let result =
        super::run(&mut || crate::merge_directory(&source, &temp.path().join("out.pdf"), 1, 1));
    assert_eq!(result.unwrap().pages, 1);
}
