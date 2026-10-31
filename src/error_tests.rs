use crate::{
    Engine, generate, merge_directory_with_engine, merge_files_with_engine, verify, verify_content,
};
use lopdf::{Document, Object, dictionary};
use std::{fs, path::Path};

fn save_changed(
    source: &Path,
    output: &Path,
    change: impl FnOnce(&mut Document),
) -> anyhow::Result<()> {
    let mut doc = Document::load(source)?;
    change(&mut doc);
    doc.save(output)?;
    Ok(())
}

#[test]
fn engines_and_invalid_inputs_fail_consistently_without_replacing_output() -> anyhow::Result<()> {
    for name in ["streaming", "streaming-compressed", "memory"] {
        assert!(name.parse::<Engine>().is_ok());
    }
    assert!("unknown".parse::<Engine>().is_err());
    let temp = tempfile::tempdir()?;
    let input = temp.path().join("input");
    generate(&input, 1, 1, 64, None)?;
    let source = input.join("000001.pdf");
    let bad = temp.path().join("bad.pdf");
    fs::write(&bad, b"not a PDF")?;
    let form = temp.path().join("form.pdf");
    save_changed(&source, &form, |doc| {
        doc.catalog_mut().unwrap().set("AcroForm", dictionary! {});
    })?;
    let empty = temp.path().join("empty.pdf");
    save_changed(&source, &empty, |doc| {
        let root = doc
            .catalog()
            .unwrap()
            .get(b"Pages")
            .unwrap()
            .as_reference()
            .unwrap();
        doc.get_object_mut(root)
            .unwrap()
            .as_dict_mut()
            .unwrap()
            .set("Count", 0);
    })?;
    let encrypted = temp.path().join("encrypted.pdf");
    // A structurally valid encryption dictionary is enough to exercise our explicit rejection.
    save_changed(&source, &encrypted, |doc| {
        let encryption = doc.add_object(dictionary!{"Filter" => "Standard", "V" => 1, "R" => 2,
            "O" => Object::string_literal(vec![0;32]), "U" => Object::string_literal(vec![0;32]), "P" => -4});
        doc.trailer.set("Encrypt", encryption);
        doc.trailer.set(
            "ID",
            vec![
                Object::string_literal(vec![0; 16]),
                Object::string_literal(vec![0; 16]),
            ],
        );
    })?;
    let out = temp.path().join("out.pdf");
    for engine in [
        Engine::Streaming,
        Engine::StreamingCompressed,
        Engine::Memory,
    ] {
        assert!(
            merge_files_with_engine(
                &[temp.path().join("missing.pdf")],
                &temp.path().join("never-created.pdf"),
                1,
                1,
                engine,
            )
            .is_err()
        );
    }
    for engine in [
        Engine::Streaming,
        Engine::StreamingCompressed,
        Engine::Memory,
    ] {
        for threads in [1, 2] {
            let metrics =
                merge_files_with_engine(std::slice::from_ref(&source), &out, threads, 1, engine)?;
            assert_eq!(metrics.pages, 1);
            for invalid in [
                &bad,
                &form,
                &empty,
                &encrypted,
                &temp.path().join("missing.pdf"),
            ] {
                fs::write(&out, b"existing")?;
                assert!(
                    merge_files_with_engine(
                        std::slice::from_ref(invalid),
                        &out,
                        threads,
                        1,
                        engine
                    )
                    .is_err()
                );
                assert_eq!(fs::read(&out)?, b"existing");
            }
        }
        assert!(
            merge_files_with_engine(std::slice::from_ref(&source), &out, 1, 0, engine).is_err()
        );
        assert!(merge_directory_with_engine(&input, &input.join("out.pdf"), 1, 1, engine).is_err());
    }
    assert!(generate(&input, 1, 1, 64, None).is_err());
    assert!(generate(&temp.path().join("zero"), 0, 1, 64, None).is_err());
    assert!(generate(&temp.path().join("tiny"), 1, 1, 64, Some(1)).is_err());
    Ok(())
}

#[test]
fn verification_detects_wrong_order_counts_changed_streams_and_repaired_sources()
-> anyhow::Result<()> {
    let temp = tempfile::tempdir()?;
    let input = temp.path().join("input");
    generate(&input, 2, 1, 64, None)?;
    let first = input.join("000001.pdf");
    let second = input.join("000002.pdf");
    let out = temp.path().join("out.pdf");
    merge_files_with_engine(&[second.clone(), first.clone()], &out, 1, 1, Engine::Memory)?;
    assert!(verify(&out, 2, 1).is_err());
    assert!(verify_content(&input, &out).is_err());
    assert!(verify(&out, 3, 1).is_err());
    assert!(verify(&out, 0, 1).is_err());
    merge_files_with_engine(std::slice::from_ref(&first), &out, 1, 1, Engine::Memory)?;
    assert!(verify_content(&input, &out).is_err());
    merge_files_with_engine(
        &[first.clone(), second, first.clone()],
        &out,
        1,
        1,
        Engine::Memory,
    )?;
    assert!(verify_content(&input, &out).is_err());
    let empty = temp.path().join("empty");
    fs::create_dir(&empty)?;
    fs::write(empty.join("ignored.txt"), b"ignored")?;
    fs::create_dir(empty.join("nested.pdf"))?;
    assert!(verify_content(&empty, &out).is_err());
    assert!(merge_directory_with_engine(&empty, &out, 1, 1, Engine::Streaming).is_err());
    // Classic xref fixture with a deliberately invalid startxref, repaired by lopdf.
    let repaired_dir = temp.path().join("repaired");
    fs::create_dir(&repaired_dir)?;
    let fixture = fs::read("php/tests/Fixtures/first.pdf")?;
    let position = fixture
        .windows(10)
        .rposition(|bytes| bytes == b"startxref\n")
        .unwrap()
        + 10;
    let end = position
        + fixture[position..]
            .iter()
            .position(|byte| *byte == b'\n')
            .unwrap();
    let mut broken = fixture[..position].to_vec();
    broken.extend_from_slice(b"1");
    broken.extend_from_slice(&fixture[end..]);
    fs::write(repaired_dir.join("first.pdf"), &broken)?;
    let repaired = Document::load(repaired_dir.join("first.pdf"))?;
    assert!(repaired.xref_recovery.is_some());
    merge_files_with_engine(
        &[repaired_dir.join("first.pdf")],
        &out,
        1,
        1,
        Engine::Streaming,
    )?;
    assert!(verify_content(&repaired_dir, &out).is_err());
    fs::write(&out, broken)?;
    assert!(verify_content(&input, &out).is_err());
    Ok(())
}
