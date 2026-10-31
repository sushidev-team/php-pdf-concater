use super::*;

#[test]
fn producer_panic_is_returned_as_an_error() {
    std::thread::scope(|scope| {
        let producer = scope.spawn(|| panic!("simulated parser panic"));
        let error = join_producer(producer).unwrap_err();
        assert!(error.to_string().contains("PDF producer thread panicked"));
    });
}

struct FailingWriter {
    remaining: usize,
    fail_flush: bool,
}

impl Write for FailingWriter {
    fn write(&mut self, bytes: &[u8]) -> io::Result<usize> {
        if self.remaining == 0 {
            return Err(io::Error::other("simulated full disk"));
        }
        let written = bytes.len().min(self.remaining);
        self.remaining -= written;
        Ok(written)
    }

    fn flush(&mut self) -> io::Result<()> {
        if self.fail_flush {
            Err(io::Error::other("simulated flush failure"))
        } else {
            Ok(())
        }
    }
}

#[test]
fn serializer_handles_short_writes_and_rejects_invalid_objects() -> Result<()> {
    let mut output = Vec::new();
    let mut writer = PdfWriter {
        output: &mut output,
        position: 0,
        offsets: Vec::new(),
    };
    assert_eq!(writer.write(b"abc")?, 3);
    assert_eq!(writer.position, 3);
    writer.indirect((1, 0), &Object::Null)?;
    assert!(writer.indirect((1, 0), &Object::Null).is_err());
    assert!(writer.finish(u32::MAX, (1, 0)).is_err());
    assert!(writer.finish(u32::MAX - 1, (1, 0)).is_err());
    assert!(object_bytes(&mut Vec::new(), &Object::Real(f32::NAN)).is_err());
    let mut output = Vec::new();
    object_bytes(&mut output, &Object::Boolean(false))?;
    assert_eq!(output, b"false");
    let mut output = FailingWriter {
        remaining: 2,
        fail_flush: false,
    };
    let mut short = PdfWriter {
        output: &mut output,
        position: 0,
        offsets: Vec::new(),
    };
    assert_eq!(short.write(b"abcd")?, 2);
    assert_eq!(short.position, 2);
    assert!(short.write(b"x").is_err());
    assert_eq!(short.position, 2);
    Ok(())
}

#[test]
fn failed_stream_writes_and_flushes_cancel_the_producer_and_propagate_errors() -> Result<()> {
    let temp = tempfile::tempdir()?;
    let source = temp.path().join("source");
    crate::generate(&source, 1, 1, 64, None)?;
    let inputs = vec![source.join("000001.pdf"); 4];
    let mut bytes = Vec::new();
    merge_into(&inputs, &mut bytes, 1, 1, Instant::now(), false)?;
    let root = bytes
        .windows(9)
        .position(|part| part == b"\n1 0 obj\n")
        .unwrap()
        + 1;
    let root_end = root
        + bytes[root..]
            .windows(7)
            .position(|part| part == b"endobj\n")
            .unwrap()
        + 7;
    // Errors before/during import, in each final object and in final flushing.
    // Four one-file batches force the producer to observe receiver cancellation.
    for limit in [0, 16, root + 1, root_end + 1, bytes.len() - 1] {
        let error = merge_into(
            &inputs,
            &mut FailingWriter {
                remaining: limit,
                fail_flush: false,
            },
            1,
            1,
            Instant::now(),
            false,
        )
        .err()
        .context("expected write failure")?;
        assert!(error.to_string().contains("simulated full disk"));
    }
    let error = merge_into(
        &inputs,
        &mut FailingWriter {
            remaining: usize::MAX,
            fail_flush: true,
        },
        2,
        1,
        Instant::now(),
        true,
    )
    .err()
    .context("expected flush failure")?;
    assert!(error.to_string().contains("simulated flush failure"));
    Ok(())
}
