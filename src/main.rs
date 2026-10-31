use anyhow::Result;
use clap::{Parser, Subcommand};
use pdf_concater::{
    generate, merge_directory_with_engine, merge_files_with_engine, verify, verify_content,
};
use std::path::PathBuf;

#[derive(Parser)]
#[command(version, about = "Merge PDF files with a fast Rust engine")]
struct Cli {
    #[command(subcommand)]
    command: Command,
}

#[derive(Subcommand)]
enum Command {
    /// Generate uniquely numbered, deterministic text PDFs (never overwrites files).
    Generate {
        #[arg(long, default_value = "data")]
        dir: PathBuf,
        #[arg(long, default_value_t = 10_000)]
        count: usize,
        #[arg(long, default_value_t = 1)]
        pages: usize,
        /// Uncompressed content bytes per page, approximately. No random binary padding.
        #[arg(long, default_value_t = 1024)]
        content_bytes: usize,
        /// Exact complete file size in bytes, using uncompressed content-stream padding.
        #[arg(long)]
        file_bytes: Option<usize>,
    },
    /// Merge top-level .pdf files in filename order, preserving source page trees.
    Merge {
        #[arg(long, default_value = "data")]
        dir: PathBuf,
        #[arg(long, default_value = "output/merged.pdf")]
        output: PathBuf,
        #[arg(long, default_value_t = 1)]
        threads: usize,
        #[arg(long, default_value_t = 32)]
        batch_size: usize,
        #[arg(long, default_value = "streaming", value_parser = ["streaming", "streaming-compressed", "memory"])]
        engine: String,
    },
    /// Merge an ordered JSON list of filenames read from standard input.
    MergeFiles {
        #[arg(long)]
        output: PathBuf,
        #[arg(long, default_value_t = 8)]
        threads: usize,
        #[arg(long, default_value_t = 32)]
        batch_size: usize,
        #[arg(long, default_value = "streaming-compressed", value_parser = ["streaming", "streaming-compressed", "memory"])]
        engine: String,
    },
    /// Compare complete decompressed page streams against the source PDFs.
    VerifyContent {
        #[arg(long)]
        dir: PathBuf,
        #[arg(long)]
        input: PathBuf,
    },
    /// Verify generated page markers and order outside benchmark timing.
    Verify {
        #[arg(long, default_value = "output/merged.pdf")]
        input: PathBuf,
        #[arg(long, default_value_t = 10_000)]
        count: usize,
        #[arg(long, default_value_t = 1)]
        pages: usize,
    },
}

fn main() -> Result<()> {
    match Cli::parse().command {
        Command::Generate {
            dir,
            count,
            pages,
            content_bytes,
            file_bytes,
        } => generate(&dir, count, pages, content_bytes, file_bytes),
        Command::Merge {
            dir,
            output,
            threads,
            batch_size,
            engine,
        } => {
            let metrics =
                merge_directory_with_engine(&dir, &output, threads, batch_size, engine.parse()?)?;
            println!("{}", serde_json::to_string(&metrics)?);
            Ok(())
        }
        Command::VerifyContent { dir, input } => verify_content(&dir, &input),
        Command::MergeFiles {
            output,
            threads,
            batch_size,
            engine,
        } => {
            let files: Vec<PathBuf> = serde_json::from_reader(std::io::stdin().lock())?;
            let metrics =
                merge_files_with_engine(&files, &output, threads, batch_size, engine.parse()?)?;
            println!("{}", serde_json::to_string(&metrics)?);
            Ok(())
        }
        Command::Verify {
            input,
            count,
            pages,
        } => verify(&input, count, pages),
    }
}
