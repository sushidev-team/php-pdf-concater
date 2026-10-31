<?php

use App\Http\Controllers\MergePdfsController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('merge');
})->name('home');

Route::post('/merge', MergePdfsController::class)->name('pdf.merge');
