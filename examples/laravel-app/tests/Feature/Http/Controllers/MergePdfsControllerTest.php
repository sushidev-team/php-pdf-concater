<?php

namespace Tests\Feature\Http\Controllers;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MergePdfsControllerTest extends TestCase
{
    public function test_downloads_a_real_merged_pdf_with_both_uploaded_contents(): void
    {
        Storage::fake('local');
        config(['pdf-concater.binary' => base_path('../../target/release/pdf-concater')]);
        $files = [
            UploadedFile::fake()->createWithContent('first.pdf', file_get_contents(base_path('tests/Fixtures/first.pdf'))),
            UploadedFile::fake()->createWithContent('second.pdf', file_get_contents(base_path('tests/Fixtures/second.pdf'))),
        ];

        $response = $this->post(route('pdf.merge'), ['files' => $files]);

        $response->assertOk()->assertDownload('merged.pdf')->assertHeader('content-type', 'application/pdf');
        $output = $response->baseResponse->getFile()->getPathname();
        $this->assertMergedPdfMatchesFixtures($output);
    }

    public function test_requires_at_least_two_uploaded_pdfs(): void
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->createWithContent('first.pdf', file_get_contents(base_path('tests/Fixtures/first.pdf')));

        $response = $this->from(route('home'))->post(route('pdf.merge'), ['files' => [$file]]);

        $response->assertRedirect(route('home'))->assertSessionHasErrors(['files' => 'Choose at least two PDFs.']);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_rejects_a_request_without_uploaded_files(): void
    {
        Storage::fake('local');

        $response = $this->from(route('home'))->post(route('pdf.merge'));

        $response->assertRedirect(route('home'))->assertSessionHasErrors(['files' => 'Choose at least two PDFs.']);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_rejects_a_non_pdf_upload(): void
    {
        Storage::fake('local');
        $files = [
            UploadedFile::fake()->createWithContent('first.pdf', file_get_contents(base_path('tests/Fixtures/first.pdf'))),
            UploadedFile::fake()->createWithContent('not-a-pdf.txt', 'This is plain text.'),
        ];

        $response = $this->from(route('home'))->post(route('pdf.merge'), ['files' => $files]);

        $response->assertRedirect(route('home'))->assertSessionHasErrors(['files.1' => 'Every upload must be a PDF.']);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_rejects_server_paths_in_place_of_uploaded_files(): void
    {
        Storage::fake('local');

        $response = $this->from(route('home'))->post(route('pdf.merge'), [
            'files' => [base_path('tests/Fixtures/first.pdf'), base_path('tests/Fixtures/second.pdf')],
        ]);

        $response->assertRedirect(route('home'))->assertSessionHasErrors(['files.0' => 'Every upload must be a file.']);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_rejects_a_scalar_instead_of_an_upload_list(): void
    {
        Storage::fake('local');

        $response = $this->from(route('home'))->post(route('pdf.merge'), ['files' => 'not-an-upload-list']);

        $response->assertRedirect(route('home'))->assertSessionHasErrors(['files' => 'Choose PDF files using the upload field.']);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_rejects_more_than_twenty_uploads(): void
    {
        Storage::fake('local');
        $files = [];
        for ($index = 0; $index < 21; $index++) {
            $files[] = UploadedFile::fake()->createWithContent("file-{$index}.pdf", file_get_contents(base_path('tests/Fixtures/first.pdf')));
        }

        $response = $this->from(route('home'))->post(route('pdf.merge'), ['files' => $files]);

        $response->assertRedirect(route('home'))->assertSessionHasErrors(['files' => 'Choose at most 20 PDFs for a browser upload.']);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_rejects_a_pdf_larger_than_ten_megabytes(): void
    {
        Storage::fake('local');
        $files = [
            UploadedFile::fake()->createWithContent('first.pdf', file_get_contents(base_path('tests/Fixtures/first.pdf'))),
            UploadedFile::fake()->create('large.pdf', 10241, 'application/pdf'),
        ];

        $response = $this->from(route('home'))->post(route('pdf.merge'), ['files' => $files]);

        $response->assertRedirect(route('home'))->assertSessionHasErrors(['files.1' => 'Each PDF must be 10 MB or smaller.']);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_shows_a_merge_error_without_leaving_an_output_file(): void
    {
        Storage::fake('local');
        config(['pdf-concater.binary' => base_path('missing-executable')]);
        $files = [
            UploadedFile::fake()->createWithContent('first.pdf', file_get_contents(base_path('tests/Fixtures/first.pdf'))),
            UploadedFile::fake()->createWithContent('second.pdf', file_get_contents(base_path('tests/Fixtures/second.pdf'))),
        ];

        $response = $this->from(route('home'))->post(route('pdf.merge'), ['files' => $files]);

        $response->assertRedirect(route('home'))->assertSessionHasErrors('files');
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}
