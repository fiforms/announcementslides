<?php

use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\EntityConsoleController;
use App\Http\Controllers\Admin\SlideController as AdminSlideController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\ChunkedUploadController;
use App\Http\Controllers\EntityController;
use App\Http\Controllers\EntitySlideController;
use App\Http\Controllers\LocalSlideController;
use App\Http\Controllers\MySlideController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SlideController;
use App\Http\Controllers\SubmitSlideController;
use App\Http\Middleware\EnsureAdmin;
use Illuminate\Support\Facades\Route;

// ── Public ────────────────────────────────────────────────────────────────────

Route::get('/', [SlideController::class, 'index'])->name('slides.index');
Route::get('/archive', [SlideController::class, 'archive'])->name('slides.archive');
Route::get('/slides/{slide}/download', [SlideController::class, 'download'])->name('slides.download');
Route::get('/slides/download-zip', [SlideController::class, 'downloadZip'])->name('slides.download-zip');
Route::get('/slides/download-pptx', [SlideController::class, 'downloadPowerPoint'])->name('slides.download-pptx');

// ── Auth (Breeze) ─────────────────────────────────────────────────────────────

require __DIR__.'/auth.php';

// ── Authenticated routes ───────────────────────────────────────────────────────

Route::middleware(['auth', 'not-banned'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::patch('/profile/settings', [ProfileController::class, 'updateSettings'])->name('profile.settings.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/entities/search', [EntityController::class, 'search'])->name('entities.search');
    Route::post('/entities/{entity}/subscribe', [EntityController::class, 'subscribe'])->name('entities.subscribe');
    Route::delete('/entities/{entity}/unsubscribe', [EntityController::class, 'unsubscribe'])->name('entities.unsubscribe');

    // ── Chunked uploads (all authenticated users; role determines outcome) ──
    Route::post('/uploads/chunk', [ChunkedUploadController::class, 'chunk'])->name('uploads.chunk');
    Route::post('/uploads/finalize', [ChunkedUploadController::class, 'finalize'])->name('uploads.finalize');

    // ── Contributor: own unscoped slide management ─────────────────────────
    Route::prefix('my-slides')->name('my-slides.')->group(function () {
        Route::get('/', [MySlideController::class, 'index'])->name('index');
        Route::get('/{slide}/edit', [MySlideController::class, 'edit'])->name('edit');
        Route::patch('/{slide}', [MySlideController::class, 'update'])->name('update');
        Route::post('/{slide}/archive', [MySlideController::class, 'archive'])->name('archive');
    });

    // ── Entity member: entity-scoped slide management (local slides) ────────
    Route::prefix('local-slides')->name('local-slides.')->group(function () {
        Route::get('/', [LocalSlideController::class, 'index'])->name('index');
        Route::get('/{slide}/edit', [LocalSlideController::class, 'edit'])->name('edit');
        Route::patch('/{slide}', [LocalSlideController::class, 'update'])->name('update');
        Route::post('/{slide}/archive', [LocalSlideController::class, 'archive'])->name('archive');
        Route::post('/{slide}/unarchive', [LocalSlideController::class, 'unarchive'])->name('unarchive');
        Route::post('/{slide}/share-nearby', [LocalSlideController::class, 'shareNearby'])->name('share-nearby');
        Route::post('/{slide}/unshare-nearby', [LocalSlideController::class, 'unshareNearby'])->name('unshare-nearby');
        Route::post('/reorder', [LocalSlideController::class, 'reorder'])->name('reorder');
    });

    // ── Entity leader: entity-scoped slide management ──────────────────────
    Route::prefix('entity/{entity}/slides')->name('entity.slides.')->group(function () {
        Route::get('/', [EntitySlideController::class, 'index'])->name('index');
        Route::get('/{slide}/edit', [EntitySlideController::class, 'edit'])->name('edit');
        Route::patch('/{slide}', [EntitySlideController::class, 'update'])->name('update');
        Route::post('/{slide}/archive', [EntitySlideController::class, 'archive'])->name('archive');
    });

    // ── Viewer: pending slide submission ───────────────────────────────────
    Route::get('/submit', [SubmitSlideController::class, 'index'])->name('slides.submit');
});

// ── Admin ─────────────────────────────────────────────────────────────────────

Route::middleware(['auth', EnsureAdmin::class])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');
    Route::get('/slides', [AdminSlideController::class, 'index'])->name('slides.index');
    Route::post('/slides', [AdminSlideController::class, 'store'])->name('slides.store');
    Route::get('/slides/{slide}/edit', [AdminSlideController::class, 'edit'])->name('slides.edit');
    Route::patch('/slides/{slide}', [AdminSlideController::class, 'update'])->name('slides.update');
    Route::delete('/slides/{slide}', [AdminSlideController::class, 'destroy'])->name('slides.destroy');
    Route::post('/slides/{slide}/approve', [AdminSlideController::class, 'approve'])->name('slides.approve');
    Route::post('/slides/{slide}/reject', [AdminSlideController::class, 'reject'])->name('slides.reject');
    Route::post('/slides/{slide}/archive', [AdminSlideController::class, 'archive'])->name('slides.archive');
    Route::post('/slides/{slide}/unarchive', [AdminSlideController::class, 'unarchive'])->name('slides.unarchive');
    Route::post('/slides/reorder', [AdminSlideController::class, 'reorder'])->name('slides.reorder');

    Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
    Route::patch('/users/{user}', [AdminUserController::class, 'update'])->name('users.update');
    Route::post('/users/{user}/entities', [AdminUserController::class, 'attachEntity'])->name('users.entities.attach');
    Route::patch('/users/{user}/entities/{entity}', [AdminUserController::class, 'updateEntityRole'])->name('users.entities.update');
    Route::delete('/users/{user}/entities/{entity}', [AdminUserController::class, 'detachEntity'])->name('users.entities.detach');

    Route::post('/invitations', [AdminUserController::class, 'storeInvitation'])->name('invitations.store');
    Route::delete('/invitations/{invitation}', [AdminUserController::class, 'destroyInvitation'])->name('invitations.destroy');

    // ── Entity console (admin oversight of entity-scoped slides) ──────────
    Route::get('/entities', [EntityConsoleController::class, 'index'])->name('entities.index');
    Route::get('/entities/{entity}/slides', [EntityConsoleController::class, 'slides'])->name('entities.slides');
});
