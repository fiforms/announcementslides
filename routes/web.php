<?php

use App\Http\Controllers\Admin\ChunkedUploadController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\SlideController as AdminSlideController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\EntityController;
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

Route::middleware(['auth', 'not-banned'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/entities/search', [EntityController::class, 'search'])->name('entities.search');
    Route::post('/entities/{entity}/subscribe', [EntityController::class, 'subscribe'])->name('entities.subscribe');
    Route::delete('/entities/{entity}/unsubscribe', [EntityController::class, 'unsubscribe'])->name('entities.unsubscribe');
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

    Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
    Route::patch('/users/{user}', [AdminUserController::class, 'update'])->name('users.update');
    Route::post('/users/{user}/entities', [AdminUserController::class, 'attachEntity'])->name('users.entities.attach');
    Route::patch('/users/{user}/entities/{entity}', [AdminUserController::class, 'updateEntityRole'])->name('users.entities.update');
    Route::delete('/users/{user}/entities/{entity}', [AdminUserController::class, 'detachEntity'])->name('users.entities.detach');

    Route::post('/invitations', [AdminUserController::class, 'storeInvitation'])->name('invitations.store');
    Route::delete('/invitations/{invitation}', [AdminUserController::class, 'destroyInvitation'])->name('invitations.destroy');
});
