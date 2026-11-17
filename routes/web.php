<?php

use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\GenerationController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    // The starter kit redirects here after login; the generator is the home screen.
    Route::redirect('dashboard', 'generate')->name('dashboard');

    Route::get('generate', [GenerationController::class, 'create'])->name('generations.create');
    Route::post('generate/estimate', [GenerationController::class, 'estimate'])->name('generations.estimate');
    Route::post('generations', [GenerationController::class, 'store'])->name('generations.store');
    Route::get('generations', [GenerationController::class, 'index'])->name('generations.index');

    Route::get('credits', [CreditController::class, 'index'])->name('credits.index');
    Route::post('credits', [CreditController::class, 'store'])->name('credits.store');

    Route::get('admin/report', ReportController::class)->middleware('can:admin')->name('admin.report');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
