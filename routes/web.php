<?php

use App\Http\Controllers\Admin\ChunkedUploadController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\SlideController as AdminSlideController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SlideController;
use App\Http\Middleware\EnsureAdmin;
use Illuminate\Support\Facades\Route;

// ── Public ────────────────────────────────────────────────────────────────────

Route::get('/', [SlideController::class, 'index'])->name('slides.index');
Route::get('/archive', [SlideController::class, 'archive'])->name('slides.archive');
Route::get('/slides/{slide}/download', [SlideController::class, 'download'])->name('slides.download');
Route::get('/slides/download-zip', [SlideController::class, 'downloadZip'])->name('slides.download-zip');

// ── Auth (Breeze) ─────────────────────────────────────────────────────────────

require __DIR__.'/auth.php';

// ── Profile (Breeze) ──────────────────────────────────────────────────────────

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// ── Admin ─────────────────────────────────────────────────────────────────────

Route::middleware(['auth', EnsureAdmin::class])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');
    Route::post('/uploads/chunk', [ChunkedUploadController::class, 'chunk'])->name('uploads.chunk');
    Route::post('/uploads/finalize', [ChunkedUploadController::class, 'finalize'])->name('uploads.finalize');
    Route::get('/slides', [AdminSlideController::class, 'index'])->name('slides.index');
    Route::post('/slides', [AdminSlideController::class, 'store'])->name('slides.store');
    Route::get('/slides/{slide}/edit', [AdminSlideController::class, 'edit'])->name('slides.edit');
    Route::patch('/slides/{slide}', [AdminSlideController::class, 'update'])->name('slides.update');
    Route::delete('/slides/{slide}', [AdminSlideController::class, 'destroy'])->name('slides.destroy');
    Route::post('/slides/{slide}/approve', [AdminSlideController::class, 'approve'])->name('slides.approve');
    Route::post('/slides/{slide}/reject', [AdminSlideController::class, 'reject'])->name('slides.reject');
    Route::post('/slides/reorder', [AdminSlideController::class, 'reorder'])->name('slides.reorder');
});
