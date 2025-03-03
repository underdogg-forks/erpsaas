<?php

use App\Http\Controllers\DocumentPrintController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['auth'])->group(function () {
    Route::get('documents/{documentType}/{id}/print', [DocumentPrintController::class, 'show'])
        ->name('documents.print');
});
