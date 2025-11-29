<?php

use App\Http\Controllers\FileUploadController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', static function () {
    $cachedLanguages = FileUploadController::getTesseractInstalledLanguages();
    return view('welcome', ['installedLanguages' => $cachedLanguages]);
});

Route::get('/milko/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';

Route::middleware('throttle:2000,1')->post('/upload', [FileUploadController::class, 'upload'])->name('file.upload');
Route::middleware('throttle:10000,1')->get('/status/{uuid}', [FileUploadController::class, 'status'])->name('status');
// Route::get('/result/{uuid}', [FileUploadController::class, 'result'])->name('result');
Route::middleware('throttle:5000,1')->get('/result/{uuid}/page/{pageNum}', [FileUploadController::class, 'resultPage'])->name('result.page');
Route::middleware('throttle:500,1')->get('/results/{uuid}', [FileUploadController::class, 'processing'])->name('processing');
Route::view('/privacy-policy', 'privacy-policy')->name('privacy.policy');
Route::middleware('throttle:10000,1')->post('/exportPfd', [FileUploadController::class, 'exportPdf'])->name('export.pdf');
Route::middleware('throttle:10000,1')->post('/exportPptx', [FileUploadController::class, 'exportPptx'])->name('export.pptx');
Route::middleware('throttle:10000,1')->get('results-explanation', static function (){
	return view('processing-explanation');
})->name('resultsExplanation');