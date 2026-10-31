<?php

namespace App\Http\Controllers;

use App\Http\Requests\MergePdfsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use SushiDev\PdfConcater\MergeException;
use SushiDev\PdfConcater\Merger;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MergePdfsController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(MergePdfsRequest $request, Merger $merger): BinaryFileResponse|RedirectResponse
    {
        $paths = array_map(fn (UploadedFile $file): string => $file->getPathname(), $request->file('files'));
        $output = Storage::disk('local')->path('pdf-concater/'.Str::uuid().'.pdf');

        try {
            $merger->merge($paths, $output);
        } catch (MergeException $error) {
            return back()->withErrors(['files' => $error->getMessage()]);
        }

        return response()->download($output, 'merged.pdf', ['Content-Type' => 'application/pdf'])
            ->deleteFileAfterSend();
    }
}
