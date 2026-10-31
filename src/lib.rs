use anyhow::{Context, Result, bail, ensure};
use lopdf::{Document, Object, Stream, dictionary};
use rayon::prelude::*;
use serde::Serialize;
use std::{
    fs::{self, File},
    io::{BufWriter, Write},
    path::{Path, PathBuf},
    time::Instant,
};
mod streaming;

#[derive(Clone, Copy, PartialEq, Eq)]
pub enum Engine {
    Streaming,
    StreamingCompressed,
    Memory,
}

impl std::str::FromStr for Engine {
    type Err = anyhow::Error;
    fn from_str(value: &str) -> Result<Self> {
        match value {
            "streaming" => Ok(Self::Streaming),
            "streaming-compressed" => Ok(Self::StreamingCompressed),
            "memory" => Ok(Self::Memory),
            _ => bail!("engine must be streaming, streaming-compressed or memory"),
        }
    }
}

#[derive(Serialize)]
pub struct Metrics {
    pub engine: &'static str,
    pub files: usize,
    pub pages: u32,
    pub input_bytes: u64,
    pub output_bytes: u64,
    pub threads: usize,
    pub batch_size: usize,
    pub discovery_ms: f64,
    pub load_import_ms: f64,
    pub stream_write_ms: f64,
    pub write_sync_ms: f64,
    pub total_ms: f64,
}

fn marker(file: usize, page: usize) -> String {
    format!("Fixture {file:06} / Page {page:04}")
}

pub fn generate(
    dir: &Path,
    count: usize,
    pages: usize,
    content_bytes: usize,
    file_bytes: Option<usize>,
) -> Result<()> {
    ensure!(count > 0 && pages > 0, "count and pages must be positive");
    fs::create_dir_all(dir)?;
    // Preflight before creating any fixture: existing user PDFs must never be overwritten.
    for i in 1..=count {
        ensure!(
            !dir.join(format!("{i:06}.pdf")).exists(),
            "fixture {i:06}.pdf already exists; use a fresh directory"
        );
    }
    let started = Instant::now();
    let mut bytes = 0;
    for i in 1..=count {
        // lopdf writes cross-reference streams by default (PDF 1.5+).
        let mut doc = Document::with_version("1.7");
        let root = doc.new_object_id();
        let font = doc.add_object(
            dictionary! {"Type" => "Font", "Subtype" => "Type1", "BaseFont" => "Helvetica"},
        );
        let resources = doc.add_object(dictionary! {"Font" => dictionary! {"F1" => font}});
        let mut kids = Vec::with_capacity(pages);
        let mut last_stream = None;
        for p in 1..=pages {
            let mut content = format!("BT /F1 16 Tf 50 790 Td ({}) Tj ET\n", marker(i, p));
            // Valid PDF drawing instructions: deterministic, slightly different on every page.
            let mut line = 0;
            while content.len() < content_bytes {
                let y = 755 - (line % 48) * 14;
                content.push_str(&format!("BT /F1 9 Tf 50 {y} Td (Document {i:06}, page {p:04}, text line {line:04}) Tj ET\n"));
                line += 1;
            }
            let stream = doc.add_object(Stream::new(dictionary! {}, content.into_bytes()));
            last_stream = Some(stream);
            // MediaBox and Resources deliberately inherited from each source Pages node.
            let page = doc
                .add_object(dictionary! {"Type" => "Page", "Parent" => root, "Contents" => stream});
            kids.push(Object::Reference(page));
        }
        doc.objects.insert(root, Object::Dictionary(dictionary! {
            "Type" => "Pages", "Kids" => kids, "Count" => pages as i64,
            "Resources" => resources, "MediaBox" => vec![0.into(), 0.into(), 595.into(), 842.into()],
        }));
        let catalog = doc.add_object(dictionary! {"Type" => "Catalog", "Pages" => root});
        doc.trailer.set("Root", catalog);
        let mut encoded = Vec::new();
        let initial_max_id = doc.max_id;
        let initial_trailer = doc.trailer.clone();
        let last_stream = last_stream.context("missing content stream")?;
        let base_len = doc.get_object(last_stream)?.as_stream()?.content.len();
        let mut padding = 0_usize;
        for attempt in 0..8 {
            // Saving synthesizes an xref stream and modifies max_id/trailer. Reset for sizing.
            doc.max_id = initial_max_id;
            doc.trailer = initial_trailer.clone();
            encoded.clear();
            doc.save_to(&mut encoded)?;
            let Some(target) = file_bytes else { break };
            if encoded.len() == target {
                break;
            }
            ensure!(attempt < 7, "could not reach exact file size");
            padding = if encoded.len() < target {
                padding
                    .checked_add(target - encoded.len())
                    .context("file size overflow")?
            } else {
                padding
                    .checked_sub(encoded.len() - target)
                    .context("--file-bytes is smaller than the generated PDF")?
            };
            // PDF whitespace is valid after the last drawing instruction. This is synthetic
            // byte-volume padding, not a realistic scan/image or object-complexity fixture.
            let stream = doc.get_object_mut(last_stream)?.as_stream_mut()?;
            stream.content.resize(base_len + padding, b' ');
            stream.dict.set("Length", stream.content.len() as i64);
        }
        let path = dir.join(format!("{i:06}.pdf"));
        let mut file = File::options().write(true).create_new(true).open(&path)?;
        file.write_all(&encoded)?;
        bytes += file.metadata()?.len();
    }
    println!(
        "{}",
        serde_json::json!({"files": count, "pages": count * pages, "input_bytes": bytes, "file_bytes": file_bytes, "generation_ms": started.elapsed().as_secs_f64() * 1000.0, "dir": dir})
    );
    Ok(())
}

pub fn merge_directory(
    dir: &Path,
    output: &Path,
    threads: usize,
    batch_size: usize,
) -> Result<Metrics> {
    merge_directory_with_engine(dir, output, threads, batch_size, Engine::Streaming)
}

pub fn merge_directory_with_engine(
    dir: &Path,
    output: &Path,
    threads: usize,
    batch_size: usize,
    engine: Engine,
) -> Result<Metrics> {
    let started = Instant::now();
    ensure!(
        threads > 0 && batch_size > 0,
        "threads and batch-size must be positive"
    );
    let mut paths = Vec::new();
    for entry in fs::read_dir(dir).with_context(|| format!("reading {}", dir.display()))? {
        let entry = entry?;
        let path = entry.path();
        if entry.file_type()?.is_file()
            && path
                .extension()
                .is_some_and(|e| e.eq_ignore_ascii_case("pdf"))
        {
            paths.push(path);
        }
    }
    paths.sort();
    ensure!(!paths.is_empty(), "no PDF inputs in {}", dir.display());
    // Keep output outside input directory; also prevents accidentally consuming a previous merge.
    let input_dir = dir.canonicalize()?;
    let parent = output
        .parent()
        .filter(|p| !p.as_os_str().is_empty())
        .unwrap_or(Path::new("."));
    fs::create_dir_all(parent)?;
    ensure!(
        parent.canonicalize()? != input_dir,
        "output must be outside the input directory"
    );
    // The parent-directory check above already excludes all directory inputs.
    merge_paths(&paths, output, threads, batch_size, started, engine, false)
}

/// Merge an explicit file list in its supplied order, including repeated files.
pub fn merge_files(
    paths: &[PathBuf],
    output: &Path,
    threads: usize,
    batch_size: usize,
) -> Result<Metrics> {
    merge_files_with_engine(paths, output, threads, batch_size, Engine::Streaming)
}

pub fn merge_files_with_engine(
    paths: &[PathBuf],
    output: &Path,
    threads: usize,
    batch_size: usize,
    engine: Engine,
) -> Result<Metrics> {
    merge_paths(
        paths,
        output,
        threads,
        batch_size,
        Instant::now(),
        engine,
        true,
    )
}

fn merge_paths(
    paths: &[PathBuf],
    output: &Path,
    threads: usize,
    batch_size: usize,
    started: Instant,
    engine: Engine,
    check_inputs: bool,
) -> Result<Metrics> {
    ensure!(!paths.is_empty(), "input file list must not be empty");
    ensure!(
        threads > 0 && batch_size > 0,
        "threads and batch-size must be positive"
    );
    let parent = output
        .parent()
        .filter(|p| !p.as_os_str().is_empty())
        .unwrap_or(Path::new("."));
    fs::create_dir_all(parent)?;
    if check_inputs && output.exists() {
        let output_path = output.canonicalize()?;
        for input in paths {
            ensure!(
                input.canonicalize()? != output_path,
                "output must not overwrite an input PDF"
            );
        }
    }
    if engine != Engine::Memory {
        return streaming::merge(
            paths,
            output,
            threads,
            batch_size,
            started,
            engine == Engine::StreamingCompressed,
        );
    }
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
    let mut merged = Document::with_version("1.7");
    let root = merged.new_object_id();
    let mut kids = Vec::with_capacity(paths.len());
    let mut pages = 0_u32;
    let mut input_bytes = 0_u64;
    let load = |path: &PathBuf| -> Result<(Document, u64)> {
        let bytes = fs::read(path).with_context(|| format!("reading {}", path.display()))?;
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
    for batch in paths.chunks(batch_size) {
        let documents: Vec<_> = match &pool {
            Some(pool) => pool.install(|| batch.par_iter().map(load).collect()),
            None => batch.iter().map(load).collect(),
        };
        for loaded in documents {
            let (mut doc, size) = loaded?;
            input_bytes += size;
            // Remap all references once per source; never clone the accumulated output.
            doc.renumber_objects_with(
                merged
                    .max_id
                    .checked_add(1)
                    .context("PDF object id overflow")?,
            );
            let catalog = doc.catalog()?;
            ensure!(
                !catalog.has(b"AcroForm"),
                "interactive forms require a dedicated form merge"
            );
            let source_root = catalog.get(b"Pages")?.as_reference()?;
            let source_pages = doc.get_object_mut(source_root)?.as_dict_mut()?;
            let count = source_pages.get(b"Count")?.as_i64()?;
            ensure!(
                count > 0 && count <= u32::MAX as i64,
                "invalid source page count"
            );
            pages = pages
                .checked_add(count as u32)
                .context("page count overflow")?;
            source_pages.set("Parent", root);
            kids.push(Object::Reference(source_root));
            merged.max_id = doc.max_id;
            // BTreeMap::append can rebuild the entire accumulated tree per source.
            // Insert just this source's objects: O(m log N), with ownership moved.
            merged.objects.extend(doc.objects);
        }
    }
    merged.objects.insert(
        root,
        Object::Dictionary(dictionary! {"Type" => "Pages", "Kids" => kids, "Count" => pages}),
    );
    let catalog = merged.add_object(dictionary! {"Type" => "Catalog", "Pages" => root});
    merged.trailer.set("Root", catalog);
    let load_import_ms = load_started.elapsed().as_secs_f64() * 1000.0;
    let write_started = Instant::now();
    let mut temporary = tempfile::NamedTempFile::new_in(parent)?;
    {
        let mut writer = BufWriter::with_capacity(1024 * 1024, temporary.as_file_mut());
        merged.save_to(&mut writer)?;
        writer.flush().context("flushing output")?;
    }
    temporary.as_file().sync_all().context("syncing output")?;
    let output_bytes = temporary.as_file().metadata()?.len();
    temporary
        .persist(output)
        .map_err(|e| e.error)
        .context("publishing output")?;
    let write_sync_ms = write_started.elapsed().as_secs_f64() * 1000.0;
    Ok(Metrics {
        engine: "memory",
        files: paths.len(),
        pages,
        input_bytes,
        output_bytes,
        threads,
        batch_size,
        discovery_ms,
        load_import_ms,
        stream_write_ms: 0.0,
        write_sync_ms,
        total_ms: started.elapsed().as_secs_f64() * 1000.0,
    })
}

/// Check the full decompressed content of every page, outside performance measurements.
/// This checks page streams; document metadata and resources are not byte comparisons.
pub fn verify_content(dir: &Path, input: &Path) -> Result<()> {
    let merged = Document::load(input)?;
    ensure!(
        merged.xref_recovery.is_none(),
        "output cross-reference data required repair"
    );
    let output_pages: Vec<_> = merged.get_pages().into_values().collect();
    let mut sources = Vec::new();
    for entry in fs::read_dir(dir)? {
        let entry = entry?;
        let path = entry.path();
        if entry.file_type()?.is_file()
            && path
                .extension()
                .is_some_and(|extension| extension.eq_ignore_ascii_case("pdf"))
        {
            sources.push(path);
        }
    }
    sources.sort();
    ensure!(!sources.is_empty(), "no source PDFs");
    let mut checked = 0;
    for source in &sources {
        let original = Document::load(source)?;
        ensure!(
            original.xref_recovery.is_none(),
            "source cross-reference data required repair: {}",
            source.display()
        );
        for id in original.get_pages().values() {
            let output_id = output_pages.get(checked).context("missing output page")?;
            ensure!(
                original.get_page_content(*id) == merged.get_page_content(*output_id),
                "page {} content differs from {}",
                checked + 1,
                source.display()
            );
            checked += 1;
        }
    }
    ensure!(
        checked == output_pages.len(),
        "unexpected extra output pages"
    );
    println!(
        "Verified all {checked} decompressed page content streams: byte-for-byte equal to {} source PDFs; no xref repair needed.",
        sources.len()
    );
    Ok(())
}

pub fn verify(input: &Path, count: usize, pages_per_file: usize) -> Result<()> {
    ensure!(
        count > 0 && pages_per_file > 0,
        "count and pages must be positive"
    );
    let doc = Document::load(input)?;
    let pages = doc.get_pages();
    ensure!(
        pages.len() == count * pages_per_file,
        "expected {} pages, got {}",
        count * pages_per_file,
        pages.len()
    );
    for (index, (_, id)) in pages.iter().enumerate() {
        let expected = marker(index / pages_per_file + 1, index % pages_per_file + 1);
        let content = String::from_utf8(doc.get_page_content(*id))?;
        if !content.contains(&expected) {
            bail!("incorrect page {}: missing {expected}", index + 1);
        }
    }
    println!(
        "Verified all {} pages: unique markers and order correct.",
        pages.len()
    );
    Ok(())
}
