<?php
// IDE signatures only. Do not require/include this file at runtime.
/**
 * Merge PDF files directly inside a directory in filename order.
 * @return array{engine:string,files:int,pages:int,input_bytes:int,output_bytes:int,threads:int,batch_size:int,discovery_ms:float,load_import_ms:float,stream_write_ms:float,write_sync_ms:float,total_ms:float}
 * @throws Exception
 */
function pdf_concater_merge_dir(string $directory, string $output, int $threads = 8, int $batch_size = 32, string $engine = 'streaming'): array {}

/**
 * Merge the supplied filenames in their insertion order. Repeated files are allowed.
 * @param list<string> $files
 * @return array{engine:string,files:int,pages:int,input_bytes:int,output_bytes:int,threads:int,batch_size:int,discovery_ms:float,load_import_ms:float,stream_write_ms:float,write_sync_ms:float,total_ms:float}
 * @throws Exception
 */
function pdf_concater_merge_files(array $files, string $output, int $threads = 8, int $batch_size = 32, string $engine = 'streaming'): array {}
