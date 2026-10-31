use crate::{
    Engine, generate, merge_directory, merge_files, merge_files_with_engine, verify, verify_content,
};
use lopdf::{Document, Object};
use std::{fs, path::PathBuf};

#[test]
fn exact_size_multipage_inheritance_and_file_order() -> anyhow::Result<()> {
    let temporary = tempfile::tempdir()?;
    let input = temporary.path().join("input");
    generate(&input, 3, 2, 1024, Some(250_000))?;
    for number in 1..=3 {
        let bytes = fs::read(input.join(format!("{number:06}.pdf")))?;
        let doc = Document::load_mem(&bytes)?;
        for object in doc.objects.values() {
            if let Ok(stream) = object.as_stream() {
                assert_eq!(
                    stream.dict.get(b"Length")?.as_i64()? as usize,
                    stream.content.len()
                );
            }
        }
        assert_eq!(
            fs::metadata(input.join(format!("{number:06}.pdf")))?.len(),
            250_000
        );
    }
    let output = temporary.path().join("merged.pdf");
    let metrics = merge_directory(&input, &output, 2, 2)?;
    assert_eq!(metrics.files, 3);
    assert_eq!(metrics.pages, 6);
    assert_eq!(metrics.input_bytes, 750_000);
    verify(&output, 3, 2)?;
    let compressed = temporary.path().join("compressed.pdf");
    let compressed_metrics = merge_files_with_engine(
        &[
            input.join("000001.pdf"),
            input.join("000002.pdf"),
            input.join("000003.pdf"),
        ],
        &compressed,
        2,
        2,
        Engine::StreamingCompressed,
    )?;
    assert!(compressed_metrics.output_bytes < metrics.output_bytes / 2);
    verify(&compressed, 3, 2)?;
    verify_content(&input, &compressed)?;
    let doc = Document::load(&output)?;
    for page in doc.get_pages().values() {
        assert!(
            !doc.get_page_fonts(*page)?.is_empty(),
            "inherited font must resolve"
        );
    }
    let reversed = [
        input.join("000003.pdf"),
        input.join("000001.pdf"),
        input.join("000003.pdf"),
    ];
    let metrics = merge_files(&reversed, &output, 1, 1)?;
    assert_eq!(metrics.pages, 6);
    let doc = Document::load(&output)?;
    let actual: Vec<_> = doc
        .get_pages()
        .values()
        .map(|id| String::from_utf8(doc.get_page_content(*id)).unwrap())
        .collect();
    for (index, file) in [3, 3, 1, 1, 3, 3].iter().enumerate() {
        assert!(actual[index].contains(&format!("Fixture {file:06} / Page {:04}", index % 2 + 1)));
    }
    Ok(())
}

#[test]
fn failed_merges_preserve_inputs_and_existing_output() -> anyhow::Result<()> {
    let temporary = tempfile::tempdir()?;
    let input = temporary.path().join("input");
    generate(&input, 1, 1, 1024, None)?;
    let source = input.join("000001.pdf");
    let original = fs::read(&source)?;
    assert!(merge_files(std::slice::from_ref(&source), &source, 1, 128).is_err());
    assert_eq!(fs::read(&source)?, original);
    let output = temporary.path().join("existing.pdf");
    fs::write(&output, b"existing output")?;
    let invalid = temporary.path().join("invalid.pdf");
    fs::write(&invalid, b"not a PDF")?;
    assert!(merge_files(&[source, invalid], &output, 2, 2).is_err());
    assert_eq!(fs::read(&output)?, b"existing output");
    assert!(merge_files(&Vec::<PathBuf>::new(), &output, 1, 128).is_err());
    assert!(merge_directory(&input, &output, 0, 128).is_err());
    Ok(())
}

#[test]
fn compressed_objects_streams_and_binary_values_survive_both_engines() -> anyhow::Result<()> {
    let temporary = tempfile::tempdir()?;
    let input = temporary.path().join("input");
    generate(&input, 1, 2, 2048, None)?;
    let source = input.join("000001.pdf");
    let mut doc = Document::load(&source)?;
    let binary = vec![0, 13, 10, b'(', b')', b'\\', 0x80, 0xff];
    let key = b"Custom /Name#\x80";
    let name = b"Value /Name#\xFF";
    for id in doc.get_pages().values() {
        let page = doc.get_object_mut(*id)?.as_dict_mut()?;
        page.set(key.to_vec(), Object::Name(name.to_vec()));
        page.set("BinaryString", Object::string_literal(binary.clone()));
        page.set(
            "Values",
            Object::Array(vec![
                Object::Null,
                true.into(),
                (-12).into(),
                0.125_f32.into(),
            ]),
        );
    }
    doc.compress();
    let mut file = fs::File::create(&source)?;
    doc.save_modern(&mut file)?;
    drop(file);
    for engine in [
        Engine::Streaming,
        Engine::StreamingCompressed,
        Engine::Memory,
    ] {
        let output = temporary.path().join("merged.pdf");
        let metrics =
            merge_files_with_engine(&[source.clone(), source.clone()], &output, 2, 1, engine)?;
        assert_eq!(metrics.pages, 4);
        let merged = Document::load(&output)?;
        assert!(
            merged.xref_recovery.is_none(),
            "xref must be valid without repair"
        );
        for (index, id) in merged.get_pages().values().enumerate() {
            let page = merged.get_object(*id)?.as_dict()?;
            assert_eq!(page.get(key)?.as_name()?, name);
            assert_eq!(page.get(b"BinaryString")?.as_str()?, binary);
            assert_eq!(page.get(b"Values")?.as_array()?.len(), 4);
            let content = String::from_utf8(merged.get_page_content(*id))?;
            assert!(content.contains(&format!("Fixture 000001 / Page {:04}", index % 2 + 1)));
            assert!(!merged.get_page_fonts(*id)?.is_empty());
        }
    }
    Ok(())
}
