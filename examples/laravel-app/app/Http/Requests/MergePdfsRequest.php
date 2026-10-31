<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class MergePdfsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'files' => ['required', 'array', 'min:2', 'max:20'],
            'files.*' => ['required', 'file', 'mimes:pdf', 'max:10240'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'files.required' => 'Choose at least two PDFs.',
            'files.array' => 'Choose PDF files using the upload field.',
            'files.min' => 'Choose at least two PDFs.',
            'files.max' => 'Choose at most 20 PDFs for a browser upload.',
            'files.*.file' => 'Every upload must be a file.',
            'files.*.mimes' => 'Every upload must be a PDF.',
            'files.*.max' => 'Each PDF must be 10 MB or smaller.',
        ];
    }
}
