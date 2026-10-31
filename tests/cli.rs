use std::{
    io::Write,
    process::{Command, Stdio},
};

#[test]
fn cli_generates_merges_and_verifies_through_every_command() -> anyhow::Result<()> {
    let temp = tempfile::tempdir()?;
    let run = |arguments: &[&str], input: Option<&str>| {
        let mut child = Command::new(env!("CARGO_BIN_EXE_pdf-concater"))
            .current_dir(temp.path())
            .args(arguments)
            .stdin(Stdio::piped())
            .stdout(Stdio::piped())
            .stderr(Stdio::piped())
            .spawn()
            .unwrap();
        if let Some(input) = input {
            child
                .stdin
                .take()
                .unwrap()
                .write_all(input.as_bytes())
                .unwrap();
        }
        let result = child.wait_with_output().unwrap();
        assert!(
            result.status.success(),
            "{}",
            String::from_utf8_lossy(&result.stderr)
        );
        result.stdout
    };
    run(&["--version"], None);
    run(
        &["generate", "--dir", "data", "--count", "2", "--pages", "2"],
        None,
    );
    let metrics = run(
        &[
            "merge",
            "--dir",
            "data",
            "--output",
            "merged.pdf",
            "--engine",
            "memory",
        ],
        None,
    );
    assert_eq!(
        serde_json::from_slice::<serde_json::Value>(&metrics)?["pages"],
        4
    );
    run(
        &[
            "verify",
            "--input",
            "merged.pdf",
            "--count",
            "2",
            "--pages",
            "2",
        ],
        None,
    );
    run(
        &["verify-content", "--dir", "data", "--input", "merged.pdf"],
        None,
    );
    run(
        &["merge-files", "--output", "list.pdf"],
        Some(r#"["data/000001.pdf","data/000002.pdf"]"#),
    );
    run(
        &["verify-content", "--dir", "data", "--input", "list.pdf"],
        None,
    );
    let failed = Command::new(env!("CARGO_BIN_EXE_pdf-concater"))
        .args(["merge", "--dir", "missing"])
        .current_dir(temp.path())
        .output()?;
    assert!(!failed.status.success());
    Ok(())
}
