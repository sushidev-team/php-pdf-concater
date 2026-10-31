<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use SushiDev\PdfConcater\MergeException;
use SushiDev\PdfConcater\Merger;

#[Signature('pdf:merge {input : Directory containing PDFs} {output : Output PDF path} {--json : Print JSON metrics}')]
#[Description('Merge PDFs using the PDF Concater Composer package and Rust engine')]
class MergePdfs extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(Merger $merger): int
    {
        try {
            $result = $merger->mergeDirectory($this->argument('input'), $this->argument('output'));
        } catch (MergeException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(sprintf('Merged %d PDFs into %d pages in %.1f ms using %s.',
                $result['files'], $result['pages'], $result['elapsed_ms'], $result['backend']));
            $this->line('Saved: '.$this->argument('output'));
        }

        return self::SUCCESS;
    }
}
