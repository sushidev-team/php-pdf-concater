//! Bounded-batch PDF import: stream each remapped source straight to the output.
//! Only source page-root IDs and byte offsets live for the entire merge.
use crate::Metrics;
use anyhow::{Context, Result, ensure};
use lopdf::{Dictionary, Document, Object, ObjectId, Stream, dictionary};
use rayon::prelude::*;
use std::{
    io::{self, BufWriter, Write},
    path::{Path, PathBuf},
    sync::mpsc::sync_channel,
    time::Instant,
};

#[cfg(test)]
#[path = "streaming_tests.rs"]
mod tests;

struct PdfWriter<'a> {
    output: &'a mut dyn Write,
    position: u64,
    offsets: Vec<Option<(u64, u16)>>,
}

impl Write for PdfWriter<'_> {
    fn write(&mut self, bytes: &[u8]) -> io::Result<usize> {
        let size = self.output.write(bytes)?;
        self.position += size as u64;
        Ok(size)
    }
    fn write_all(&mut self, bytes: &[u8]) -> io::Result<()> {
        self.output.write_all(bytes)?;
        self.position += bytes.len() as u64;
        Ok(())
    }
    fn flush(&mut self) -> io::Result<()> {
        self.output.flush()
    }
}

impl PdfWriter<'_> {
    fn indirect(&mut self, id: ObjectId, object: &Object) -> Result<()> {
        let index = id.0 as usize;
        if self.offsets.len() <= index {
            self.offsets.resize(index + 1, None);
        }
        ensure!(self.offsets[index].is_none(), "duplicate output object ID");
        self.offsets[index] = Some((self.position, id.1));
        writeln!(self, "{} {} obj", id.0, id.1)?;
        object_bytes(self, object)?;
        self.write_all(b"\nendobj\n")?;
        Ok(())
    }

    fn finish(&mut self, max_id: u32, catalog: ObjectId) -> Result<()> {
        let xref_id = max_id.checked_add(1).context("object ID overflow")?;
        let size = xref_id.checked_add(1).context("object ID overflow")? as usize;
        self.offsets.resize(size, None);
        let xref_start = self.position;
        // 64-bit offsets support outputs larger than 4 GiB; all objects are uncompressed.
        self.offsets[xref_id as usize] = Some((xref_start, 0));
        let mut data = Vec::with_capacity(size * 11);
        for (index, entry) in self.offsets.iter().enumerate() {
            match entry {
                Some((offset, generation)) => {
                    data.push(1);
                    data.extend_from_slice(&offset.to_be_bytes());
                    data.extend_from_slice(&generation.to_be_bytes());
                }
                None => {
                    data.push(0);
                    data.extend_from_slice(&0_u64.to_be_bytes());
                    data.extend_from_slice(
                        &(if index == 0 { 65535_u16 } else { 0_u16 }).to_be_bytes(),
                    );
                }
            }
        }
        let xref = Object::Stream(Stream::new(
            dictionary! {
                "Type" => "XRef", "Size" => size as i64, "Root" => catalog,
                "W" => vec![1.into(), 8.into(), 2.into()],
            },
            data,
        ));
        // Its offset has to be included in its own stream before writing it.
        self.offsets[xref_id as usize] = None;
        self.indirect((xref_id, 0), &xref)?;
        write!(self, "startxref\n{xref_start}\n%%EOF\n")?;
        self.flush()?;
        Ok(())
    }
}

// Serialize the parsed object model, never debug formatting or source-byte substitution.
// Strings use hexadecimal notation, preserving every byte without escaping ambiguities.
fn object_bytes(out: &mut dyn Write, object: &Object) -> Result<()> {
    match object {
        Object::Null => out.write_all(b"null")?,
        Object::Boolean(value) => out.write_all(if *value { b"true" } else { b"false" })?,
        Object::Integer(value) => write!(out, "{value}")?,
        Object::Real(value) => {
            ensure!(value.is_finite(), "non-finite PDF number");
            write!(out, "{value}")?;
        }
        Object::Reference((id, generation)) => write!(out, "{id} {generation} R")?,
        Object::Name(name) => name_bytes(out, name)?,
        Object::String(bytes, _) => {
            out.write_all(b"<")?;
            for byte in bytes {
                write!(out, "{byte:02X}")?;
            }
            out.write_all(b">")?;
        }
        Object::Array(items) => {
            out.write_all(b"[")?;
            for item in items {
                object_bytes(out, item)?;
                out.write_all(b" ")?;
            }
            out.write_all(b"]")?;
        }
        Object::Dictionary(dictionary) => dictionary_bytes(out, dictionary)?,
        Object::Stream(stream) => {
            let mut dictionary = stream.dict.clone();
            dictionary.set("Length", i64::try_from(stream.content.len())?);
            dictionary_bytes(out, &dictionary)?;
            out.write_all(b"\nstream\n")?;
            out.write_all(&stream.content)?;
            out.write_all(b"\nendstream")?;
        }
    }
    Ok(())
}

fn dictionary_bytes(out: &mut dyn Write, dictionary: &Dictionary) -> Result<()> {
    out.write_all(b"<<")?;
    for (key, value) in dictionary {
        name_bytes(out, key)?;
        out.write_all(b" ")?;
        object_bytes(out, value)?;
        out.write_all(b" ")?;
    }
    out.write_all(b">>")?;
    Ok(())
}

fn name_bytes(out: &mut dyn Write, name: &[u8]) -> Result<()> {
    out.write_all(b"/")?;
    for byte in name {
        if (33..=126).contains(byte) && !b"()<>[]{}/%#".contains(byte) {
            out.write_all(&[*byte])?;
        } else {
            write!(out, "#{byte:02X}")?;
        }
    }
    Ok(())
}

struct Prepared {
    data: Vec<u8>,
    offsets: Vec<(ObjectId, u64)>,
    root: ObjectId,
    pages: u32,
    max_id: u32,
    input_bytes: u64,
}

fn join_producer(producer: std::thread::ScopedJoinHandle<'_, Result<()>>) -> Result<()> {
    producer
        .join()
        .map_err(|_| anyhow::anyhow!("PDF producer thread panicked"))?
}

fn prepare(
    mut doc: Document,
    input_bytes: u64,
    starting_id: u32,
    compress: bool,
) -> Result<Prepared> {
    doc.renumber_objects_with(starting_id);
    let catalog = doc.catalog()?;
    ensure!(
        !catalog.has(b"AcroForm"),
        "interactive forms require a dedicated form merge"
    );
    let root = catalog.get(b"Pages")?.as_reference()?;
    let source_pages = doc.get_object_mut(root)?.as_dict_mut()?;
    let pages = source_pages.get(b"Count")?.as_i64()?;
    ensure!(
        pages > 0 && pages <= u32::MAX as i64,
        "invalid source page count"
    );
    source_pages.set("Parent", (1, 0));
    if compress {
        for object in doc.objects.values_mut() {
            let Ok(stream) = object.as_stream_mut() else {
                continue;
            };
            if stream.allows_compression && !stream.dict.has(b"Filter") && stream.content.len() > 64
            {
                let mut encoder =
                    flate2::write::ZlibEncoder::new(Vec::new(), flate2::Compression::fast());
                encoder.write_all(&stream.content)?;
                let bytes = encoder.finish()?;
                if bytes.len() + 32 < stream.content.len() {
                    stream.dict.set("Filter", "FlateDecode");
                    stream.set_content(bytes);
                }
            }
        }
    }
    let mut data = Vec::with_capacity(usize::try_from(input_bytes)?);
    let mut offsets = Vec::with_capacity(doc.objects.len());
    for (id, object) in &doc.objects {
        if matches!(object.type_name(), Ok(b"ObjStm" | b"XRef" | b"Linearized")) {
            continue;
        }
        offsets.push((*id, data.len() as u64));
        writeln!(data, "{} {} obj", id.0, id.1)?;
        object_bytes(&mut data, object)?;
        data.write_all(b"\nendobj\n")?;
    }
    Ok(Prepared {
        data,
        offsets,
        root,
        pages: pages as u32,
        max_id: doc.max_id,
        input_bytes,
    })
}

pub(crate) fn merge(
    paths: &[PathBuf],
    output: &Path,
    threads: usize,
    batch_size: usize,
    started: Instant,
    compress: bool,
) -> Result<Metrics> {
    let parent = output
        .parent()
        .filter(|p| !p.as_os_str().is_empty())
        .unwrap_or(Path::new("."));
    let mut temporary = tempfile::NamedTempFile::new_in(parent)?;
    let mut metrics = merge_into(
        paths,
        &mut BufWriter::with_capacity(1024 * 1024, temporary.as_file_mut()),
        threads,
        batch_size,
        started,
        compress,
    )?;
    let publish_started = Instant::now();
    temporary.as_file().sync_all()?;
    temporary.persist(output).map_err(|error| error.error)?;
    metrics.write_sync_ms += publish_started.elapsed().as_secs_f64() * 1000.0;
    metrics.total_ms = started.elapsed().as_secs_f64() * 1000.0;
    Ok(metrics)
}

// Keeping serialization independent of the filesystem also lets tests exercise
// short writes, disk-full errors and producer cancellation deterministically.
fn merge_into(
    paths: &[PathBuf],
    output: &mut dyn Write,
    threads: usize,
    batch_size: usize,
    started: Instant,
    compress: bool,
) -> Result<Metrics> {
    let mut writer = PdfWriter {
        output,
        position: 0,
        offsets: Vec::new(),
    };
    writer.write_all(b"%PDF-1.7\n%\xBB\xAD\xC0\xDE\n")?;
    let discovery_ms = started.elapsed().as_secs_f64() * 1000.0;
    let load_started = Instant::now();
    let pool = if threads > 1 {
        Some(
            rayon::ThreadPoolBuilder::new()
                .num_threads(threads)
                .build()?,
        )
    } else {
        None
    };
    let root = (1, 0);
    let mut max_id = 1_u32;
    let mut kids = Vec::with_capacity(paths.len());
    let mut pages = 0_u32;
    let mut input_bytes = 0_u64;
    let mut stream_write_ms = 0.0;
    let load = |path: &PathBuf| -> Result<(Document, u64)> {
        let bytes = std::fs::read(path).with_context(|| format!("reading {}", path.display()))?;
        let size = bytes.len() as u64;
        let doc =
            Document::load_mem(&bytes).with_context(|| format!("parsing {}", path.display()))?;
        ensure!(
            !doc.is_encrypted(),
            "encrypted input unsupported: {}",
            path.display()
        );
        Ok((doc, size))
    };
    std::thread::scope(|scope| -> Result<()> {
        // A bounded queue overlaps parsing/serialization with file writes while retaining
        // at most one queued batch. It also preserves the requested source order.
        let (sender, receiver) = sync_channel(1);
        let producer = scope.spawn(move || -> Result<()> {
            let mut next_id = 2_u32;
            for batch in paths.chunks(batch_size) {
                let loaded: Vec<_> = match &pool {
                    Some(pool) => pool.install(|| batch.par_iter().map(load).collect()),
                    None => batch.iter().map(load).collect(),
                };
                let mut numbered = Vec::with_capacity(loaded.len());
                for item in loaded {
                    let (doc, size) = item?;
                    let start = next_id;
                    next_id = next_id
                        .checked_add(u32::try_from(doc.objects.len())?)
                        .context("object ID overflow")?;
                    numbered.push((doc, size, start));
                }
                let serialize = |(doc, size, start)| prepare(doc, size, start, compress);
                let prepared: Vec<Result<Prepared>> = match &pool {
                    Some(pool) => {
                        pool.install(|| numbered.into_par_iter().map(serialize).collect())
                    }
                    None => numbered.into_iter().map(serialize).collect(),
                };
                let prepared: Vec<_> = prepared.into_iter().collect::<Result<_>>()?;
                if sender.send(prepared).is_err() {
                    return Ok(());
                }
            }
            Ok(())
        });
        for batch in receiver {
            for source in batch {
                input_bytes += source.input_bytes;
                pages = pages
                    .checked_add(source.pages)
                    .context("page count overflow")?;
                kids.push(Object::Reference(source.root));
                max_id = source.max_id;
                let write_started = Instant::now();
                let base = writer.position;
                for (id, relative) in source.offsets {
                    let index = id.0 as usize;
                    if writer.offsets.len() <= index {
                        writer.offsets.resize(index + 1, None);
                    }
                    ensure!(
                        writer.offsets[index].is_none(),
                        "duplicate output object ID"
                    );
                    writer.offsets[index] = Some((base + relative, id.1));
                }
                writer.write_all(&source.data)?;
                stream_write_ms += write_started.elapsed().as_secs_f64() * 1000.0;
            }
        }
        join_producer(producer)?;
        Ok(())
    })?;
    let load_import_ms = load_started.elapsed().as_secs_f64() * 1000.0 - stream_write_ms;
    let write_started = Instant::now();
    writer.indirect(
        root,
        &Object::Dictionary(dictionary! {"Type" => "Pages", "Kids" => kids, "Count" => pages}),
    )?;
    let catalog = (max_id.checked_add(1).context("object ID overflow")?, 0);
    writer.indirect(
        catalog,
        &Object::Dictionary(dictionary! {"Type" => "Catalog", "Pages" => root}),
    )?;
    writer.finish(catalog.0, catalog)?;
    let output_bytes = writer.position;
    let write_sync_ms = write_started.elapsed().as_secs_f64() * 1000.0;
    Ok(Metrics {
        engine: if compress {
            "streaming-compressed"
        } else {
            "streaming"
        },
        files: paths.len(),
        pages,
        input_bytes,
        output_bytes,
        threads,
        batch_size,
        discovery_ms,
        load_import_ms,
        stream_write_ms,
        write_sync_ms,
        total_ms: started.elapsed().as_secs_f64() * 1000.0,
    })
}
