<?php

return [
    'binary' => env('PDF_CONCATER_BINARY'),
    'threads' => (int) env('PDF_CONCATER_THREADS', 8),
    'batch_size' => (int) env('PDF_CONCATER_BATCH_SIZE', 32),
    'compress' => (bool) env('PDF_CONCATER_COMPRESS', true),
    'temporary_directory' => env('PDF_CONCATER_TEMP_DIRECTORY'),
];
