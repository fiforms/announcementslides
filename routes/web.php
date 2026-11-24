<?php

use App\Http\Controllers\Admin\LibraryController as AdminLibraryController;
use App\Http\Controllers\LibraryBuildController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\DirectoryImportController;
use App\Http\Controllers\Admin\EntityConsoleController;
use App\Http\Controllers\Admin\PromoShowController as AdminPromoShowController;
use App\Http\Controllers\Admin\SlideAnnouncerConsoleController;
use App\Http\Controllers\Admin\SlideAnnouncerReleaseController;
use App\Http\Controllers\Admin\SlideController as AdminSlideController;
use App\Http\Controllers\Admin\UpscalerSettingsController as AdminUpscalerSettingsController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Admin\WidgetController as AdminWidgetController;
use App\Http\Controllers\Admin\LiveChannelController as AdminLiveChannelController;
use App\Http\Controllers\Admin\BackupController as AdminBackupController;
use App\Http\Controllers\ChunkedUploadController;
use App\Http\Controllers\EntityController;
use App\Http\Controllers\EntityPeopleController;
use App\Http\Controllers\EntitySlideAnnouncerController;
use App\Http\Controllers\EntitySlideController;
use App\Http\Controllers\LocalSlideController;
use App\Http\Controllers\MySlideController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PlayController;
use App\Http\Controllers\PlayLinkController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ShowController;
use App\Http\Controllers\SlideController;
use App\Http\Controllers\SubmitSlideController;
use App\Http\Controllers\WidgetAssetController;
use App\Http\Controllers\WidgetDataController;
use App\Http\Middleware\EnsureAdmin;
use Illuminate\Support\Facades\Route;

// ── Public ────────────────────────────────────────────────────────────────────

// First-run wizard: exists only while there are no users (then 404s).
Route::middleware(\App\Http\Middleware\EnsureSetupIsOpen::class)->group(function () {
    Route::get('/setup', [\App\Http\Controllers\SetupController::class, 'show'])->name('setup.show');
    Route::post('/setup', [\App\Http\Controllers\SetupController::class, 'store'])
        ->middleware('throttle:10,1')->name('setup.store');
});

// Public: what this software is, its licence and where the source lives.
Route::get('/about', [\App\Http\Controllers\AboutController::class, 'show'])->name('about');

// Public in-app help (Markdown in resources/help/<locale>/); topics can restrict their audience.
Route::get('/help', [\App\Http\Controllers\HelpController::class, 'index'])->name('help.index');
Route::get('/help/{topic}', [\App\Http\Controllers\HelpController::class, 'show'])
    ->where('topic', '[a-z0-9-]+')->name('help.show');
Route::get('/help-media/{path}', [\App\Http\Controllers\HelpController::class, 'media'])
    ->where('path', '[A-Za-z0-9._/-]+')->name('help.media');

Route::get('/', [SlideController::class, 'index'])->name('slides.index');
Route::get('/archive', [SlideController::class, 'archive'])->name('slides.archive');
Route::get('/slides/{slide:token}/download', [SlideController::class, 'download'])->name('slides.download');
Route::get('/slides/{slide:token}/media/{media}/download', [SlideController::class, 'downloadMedia'])->name('slides.media.download');
Route::get('/slides/download-zip', [SlideController::class, 'downloadZip'])->name('slides.download-zip');
Route::get('/slides/download-pptx', [SlideController::class, 'downloadPowerPoint'])->name('slides.download-pptx');
Route::get('/slides/download-revelation', [SlideController::class, 'downloadRevelation'])->name('slides.download-revelation');
Route::get('/slides/{slide:token}', [SlideController::class, 'index'])->name('slides.show');

// Overlay widgets: bundle files (only those in the installed manifest), and
// server-fetched data for a saved placement — never a caller-supplied URL.
// Public because public slides' widgets run for guests too.
Route::get('/widget-assets/{slug}/{version}/{path}', [WidgetAssetController::class, 'show'])
    ->where('path', '.*')->name('widgets.asset');
Route::get('/widget-data/{slideMedia}/{element}/{endpoint}', [WidgetDataController::class, 'show'])
    ->middleware('throttle:120,1')->name('widget-data.show');

// Shared-link player: the unguessable token is the only credential, so no
// login or session is needed. Throttled per IP against token guessing
// (generous, since one church's screens share an IP).
Route::prefix('play/{token}')->name('play.')->middleware('throttle:240,1')->group(function () {
    Route::get('/', [PlayController::class, 'show'])->name('show');
    Route::get('/slides', [PlayController::class, 'slides'])->name('slides');
    Route::get('/widget-data/{slideMedia}/{element}/{endpoint}', [WidgetDataController::class, 'playLink'])->name('widget-data');
});

// Signed "stop emails like this" links from notification emails (no sign-in needed).
Route::middleware(['signed', 'throttle:30,1'])->group(function () {
    Route::get('/notifications/unsubscribe/{user}/{category}', [\App\Http\Controllers\NotificationUnsubscribeController::class, 'show'])
        ->name('notifications.unsubscribe');
    Route::post('/notifications/unsubscribe/{user}/{category}', [\App\Http\Controllers\NotificationUnsubscribeController::class, 'store']);
});

// ── Auth (Breeze) ─────────────────────────────────────────────────────────────

require __DIR__.'/auth.php';

// ── Authenticated routes ───────────────────────────────────────────────────────

Route::middleware(['auth', 'not-banned'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    // Fully client-side QR Code Creator (nothing is posted back).
    Route::inertia('/qr-code', 'QrCode')->name('qr-code');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::patch('/profile/settings', [ProfileController::class, 'updateSettings'])->name('profile.settings.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::patch('/profile/notifications', [ProfileController::class, 'updateNotifications'])->name('profile.notifications.update');

    // ── Notifications: inbox, archive, bell ──────────────────────────────────
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/recent', [NotificationController::class, 'recent'])->name('notifications.recent');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('/notifications/archive-all', [NotificationController::class, 'archiveAll'])->name('notifications.archive-all');
    Route::get('/notifications/{notification}/open', [NotificationController::class, 'open'])->name('notifications.open');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');
    Route::post('/notifications/{notification}/archive', [NotificationController::class, 'archive'])->name('notifications.archive');
    Route::post('/notifications/{notification}/restore', [NotificationController::class, 'restore'])->name('notifications.restore');

    Route::get('/entities/search', [EntityController::class, 'search'])->name('entities.search');
    Route::post('/entities/{entity}/subscribe', [EntityController::class, 'subscribe'])->name('entities.subscribe');
    Route::delete('/entities/{entity}/unsubscribe', [EntityController::class, 'unsubscribe'])->name('entities.unsubscribe');

    // ── Entity leader: restore an archived slide from /archive ─────────────
    Route::post('/archive/{slide}/unarchive', [SlideController::class, 'unarchive'])->name('slides.unarchive');

    // ── Chunked uploads (all authenticated users; role determines outcome) ──
    Route::post('/uploads/chunk', [ChunkedUploadController::class, 'chunk'])->name('uploads.chunk');
    Route::post('/uploads/finalize', [ChunkedUploadController::class, 'finalize'])->name('uploads.finalize');

    // ── Slide library: build a slide from a shared background + overlay template ──
    Route::get('/library/catalog', [LibraryBuildController::class, 'catalog'])->name('library.catalog');
    Route::post('/library/build', [LibraryBuildController::class, 'build'])->name('library.build');

    // ── Contributor: own unscoped slide management ─────────────────────────
    Route::prefix('my-slides')->name('my-slides.')->group(function () {
        Route::get('/', [MySlideController::class, 'index'])->name('index');
        Route::get('/{slide}/edit', [MySlideController::class, 'edit'])->name('edit');
        Route::patch('/{slide}', [MySlideController::class, 'update'])->name('update');
        Route::post('/{slide}/archive', [MySlideController::class, 'archive'])->name('archive');
        Route::post('/{slide}/media', [MySlideController::class, 'storeMedia'])->name('media.store');
        Route::delete('/{slide}/media/{media}', [MySlideController::class, 'destroyMedia'])->name('media.destroy');
        Route::post('/{slide}/media/{media}/resize', [MySlideController::class, 'resizeMedia'])->name('media.resize');
        Route::post('/{slide}/media/{media}/version', [MySlideController::class, 'setMediaVersion'])->name('media.version');
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
        Route::post('/{slide}/media', [LocalSlideController::class, 'storeMedia'])->name('media.store');
        Route::post('/{slide}/media/library', [LocalSlideController::class, 'attachLibraryMedia'])->name('media.library');
        Route::delete('/{slide}/media/{media}', [LocalSlideController::class, 'destroyMedia'])->name('media.destroy');
        Route::post('/{slide}/media/{media}/resize', [LocalSlideController::class, 'resizeMedia'])->name('media.resize');
        Route::post('/{slide}/media/{media}/version', [LocalSlideController::class, 'setMediaVersion'])->name('media.version');
        Route::get('/{slide}/overlay', [LocalSlideController::class, 'showOverlay'])->name('overlay.show');
        Route::put('/{slide}/overlay', [LocalSlideController::class, 'saveOverlay'])->name('overlay.save');
    });

    // ── Entity leader: manage per-site "shows" (playlists) ──────────────────
    Route::prefix('shows')->name('shows.')->group(function () {
        Route::get('/', [ShowController::class, 'index'])->name('index');
        Route::post('/', [ShowController::class, 'store'])->name('store');
        Route::patch('/{show}', [ShowController::class, 'update'])->name('update');
        Route::delete('/{show}', [ShowController::class, 'destroy'])->name('destroy');
        Route::post('/{show}/slides', [ShowController::class, 'attach'])->name('slides.attach');
        Route::delete('/{show}/slides/{slide}', [ShowController::class, 'detach'])->name('slides.detach');
        Route::post('/{show}/slides/detach-expired', [ShowController::class, 'detachExpired'])->name('slides.detachExpired');
        Route::post('/{show}/reorder', [ShowController::class, 'reorder'])->name('reorder');
        Route::get('/{show}/overlay', [ShowController::class, 'showOverlay'])->name('overlay.show');
        Route::put('/{show}/overlay', [ShowController::class, 'saveOverlay'])->name('overlay.save');
        Route::put('/{show}/youtube', [ShowController::class, 'saveYoutube'])->name('youtube.save');
        Route::put('/{show}/channel', [ShowController::class, 'saveChannel'])->name('channel.save');
        Route::put('/{show}/audio', [ShowController::class, 'saveAudio'])->name('audio.save');
        Route::post('/{show}/media', [ShowController::class, 'storeMedia'])->name('media.store');
        Route::post('/{show}/media/library', [ShowController::class, 'attachLibraryBackground'])->name('media.library');
        Route::delete('/{show}/media/{media}', [ShowController::class, 'destroyMedia'])->name('media.destroy');
    });

    // ── Entity leader: entity-scoped slide management ──────────────────────
    Route::prefix('entity/{entity}/slides')->name('entity.slides.')->group(function () {
        Route::get('/', [EntitySlideController::class, 'index'])->name('index');
        Route::patch('/settings', [EntitySlideController::class, 'updateSettings'])->name('settings');
        Route::get('/{slide}/edit', [EntitySlideController::class, 'edit'])->name('edit');
        Route::patch('/{slide}', [EntitySlideController::class, 'update'])->name('update');
        Route::post('/{slide}/archive', [EntitySlideController::class, 'archive'])->name('archive');
        Route::post('/{slide}/media', [EntitySlideController::class, 'storeMedia'])->name('media.store');
        Route::post('/{slide}/media/library', [EntitySlideController::class, 'attachLibraryMedia'])->name('media.library');
        Route::delete('/{slide}/media/{media}', [EntitySlideController::class, 'destroyMedia'])->name('media.destroy');
        Route::post('/{slide}/media/{media}/resize', [EntitySlideController::class, 'resizeMedia'])->name('media.resize');
        Route::post('/{slide}/media/{media}/version', [EntitySlideController::class, 'setMediaVersion'])->name('media.version');
    });

    // ── Entity leader: paired Slide Announcer devices ───────────────────────
    Route::prefix('slide-announcers')->name('slide-announcers.')->group(function () {
        Route::get('/', [EntitySlideAnnouncerController::class, 'index'])->name('index');
        Route::post('/pairing-codes', [EntitySlideAnnouncerController::class, 'storePairingCode'])->name('pairing-codes.store');
        Route::patch('/live-channels', [EntitySlideAnnouncerController::class, 'updateLiveChannels'])->name('live-channels.update');
        Route::get('/{slideAnnouncer}', [EntitySlideAnnouncerController::class, 'show'])->name('show');
        Route::patch('/{slideAnnouncer}', [EntitySlideAnnouncerController::class, 'update'])->name('update');
        Route::delete('/{slideAnnouncer}', [EntitySlideAnnouncerController::class, 'destroy'])->name('destroy');
    });

    // ── Entity leader: see the team, add/revoke editors ─────────────────────
    Route::prefix('entity/{entity}/people')->name('entity.people.')->group(function () {
        Route::get('/', [EntityPeopleController::class, 'index'])->name('index');
        Route::post('/', [EntityPeopleController::class, 'store'])->name('store');
        Route::delete('/invitations/{invitation}', [EntityPeopleController::class, 'destroyInvitation'])->name('invitations.destroy');
        Route::delete('/{user}', [EntityPeopleController::class, 'destroy'])->name('destroy');
    });

    // ── Entity leader: shareable no-login playback links ────────────────────
    Route::prefix('play-links')->name('play-links.')->group(function () {
        Route::get('/', [PlayLinkController::class, 'index'])->name('index');
        Route::post('/', [PlayLinkController::class, 'store'])->name('store');
        Route::patch('/{playLink}', [PlayLinkController::class, 'update'])->name('update');
        Route::delete('/{playLink}', [PlayLinkController::class, 'destroy'])->name('destroy');
    });

    // ── Overlay editor: live widget preview with unsaved parameters ────────
    Route::post('/widget-data/preview', [WidgetDataController::class, 'preview'])
        ->middleware('throttle:30,1')->name('widget-data.preview');

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
    Route::post('/slides/{slide}/media', [AdminSlideController::class, 'storeMedia'])->name('slides.media.store');
    Route::post('/slides/{slide}/media/library', [AdminSlideController::class, 'attachLibraryMedia'])->name('slides.media.library');
    Route::delete('/slides/{slide}/media/{media}', [AdminSlideController::class, 'destroyMedia'])->name('slides.media.destroy');
    Route::post('/slides/{slide}/media/{media}/resize', [AdminSlideController::class, 'resizeMedia'])->name('slides.media.resize');
    Route::post('/slides/{slide}/media/{media}/version', [AdminSlideController::class, 'setMediaVersion'])->name('slides.media.version');
    Route::get('/slides/{slide}/overlay', [AdminSlideController::class, 'showOverlay'])->name('slides.overlay.show');
    Route::put('/slides/{slide}/overlay', [AdminSlideController::class, 'saveOverlay'])->name('slides.overlay.save');

    Route::prefix('library')->name('library.')->group(function () {
        Route::get('/', [AdminLibraryController::class, 'index'])->name('index');
        Route::post('/backgrounds', [AdminLibraryController::class, 'storeBackground'])->name('backgrounds.store');
        Route::post('/audio', [AdminLibraryController::class, 'storeAudio'])->name('audio.store');
        Route::post('/templates', [AdminLibraryController::class, 'storeTemplate'])->name('templates.store');
        Route::get('/templates/{item}/edit', [AdminLibraryController::class, 'editTemplate'])->name('templates.edit');
        Route::get('/templates/{item}/overlay', [AdminLibraryController::class, 'showOverlay'])->name('overlay.show');
        Route::put('/templates/{item}/overlay', [AdminLibraryController::class, 'saveOverlay'])->name('overlay.save');
        Route::delete('/templates/{item}/overlay', [AdminLibraryController::class, 'destroyOverlay'])->name('overlay.destroy');
        Route::patch('/{item}', [AdminLibraryController::class, 'update'])->name('update');
        Route::delete('/{item}', [AdminLibraryController::class, 'destroy'])->name('destroy');
    });

    Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
    Route::patch('/users/{user}', [AdminUserController::class, 'update'])->name('users.update');
    Route::post('/users/{user}/entities', [AdminUserController::class, 'attachEntity'])->name('users.entities.attach');
    Route::patch('/users/{user}/entities/{entity}', [AdminUserController::class, 'updateEntityRole'])->name('users.entities.update');
    Route::delete('/users/{user}/entities/{entity}', [AdminUserController::class, 'detachEntity'])->name('users.entities.detach');

    Route::post('/invitations', [AdminUserController::class, 'storeInvitation'])->name('invitations.store');
    Route::delete('/invitations/{invitation}', [AdminUserController::class, 'destroyInvitation'])->name('invitations.destroy');

    // ── Promo shows (globally-distributed one-off shows) ──────────────────
    Route::get('/playlists', [AdminPromoShowController::class, 'index'])->name('playlists.index');
    Route::get('/playlists/{promo}/upload', [AdminPromoShowController::class, 'upload'])->name('playlists.upload');
    Route::patch('/playlists/{promo}', [AdminPromoShowController::class, 'update'])->name('playlists.update');
    Route::delete('/playlists/{promo}/slides/{slide}', [AdminPromoShowController::class, 'detachSlide'])->name('playlists.slides.detach');
    Route::delete('/playlists/{promo}', [AdminPromoShowController::class, 'destroy'])->name('playlists.destroy');

    // ── Entity console (admin oversight of entity-scoped slides) ──────────
    Route::get('/entities', [EntityConsoleController::class, 'index'])->name('entities.index');
    Route::get('/entities/{entity}/slides', [EntityConsoleController::class, 'slides'])->name('entities.slides');
    Route::get('/entities/{entity}', [EntityConsoleController::class, 'show'])->name('entities.show');

    // ── Directory import (churches + pastors from files) ───────────────────
    Route::get('/directory-import', [DirectoryImportController::class, 'index'])->name('directory-import.index');
    Route::get('/directory-import/sample/{kind}', [DirectoryImportController::class, 'sample'])->name('directory-import.sample');
    Route::post('/directory-import/entities', [DirectoryImportController::class, 'previewEntities'])->name('directory-import.entities');
    Route::post('/directory-import/pastors', [DirectoryImportController::class, 'previewPastors'])->name('directory-import.pastors');
    Route::post('/directory-import/apply', [DirectoryImportController::class, 'apply'])->name('directory-import.apply');
    Route::post('/directory-import/geocode', [DirectoryImportController::class, 'geocode'])->name('directory-import.geocode');
    Route::delete('/directory-import', [DirectoryImportController::class, 'cancel'])->name('directory-import.cancel');

    // ── Slide Announcer fleet console (cross-entity device list) ──────────
    Route::get('/slide-announcers', [SlideAnnouncerConsoleController::class, 'index'])->name('slide-announcers.index');

    // ── In-browser AI upscaler options ────────────────────────────────────
    Route::get('/upscaler', [AdminUpscalerSettingsController::class, 'index'])->name('upscaler.index');
    Route::patch('/upscaler', [AdminUpscalerSettingsController::class, 'update'])->name('upscaler.update');

    // ── Notifications: email, digest, retention ────────────────────────────
    Route::get('/notifications', [\App\Http\Controllers\Admin\NotificationSettingsController::class, 'index'])->name('notifications.index');
    Route::patch('/notifications', [\App\Http\Controllers\Admin\NotificationSettingsController::class, 'update'])->name('notifications.update');
    Route::post('/notifications/test', [\App\Http\Controllers\Admin\NotificationSettingsController::class, 'test'])
        ->middleware('throttle:5,1')->name('notifications.test');

    // ── Overlay widgets (admin-installed code packages) ───────────────────
    Route::get('/widgets', [AdminWidgetController::class, 'index'])->name('widgets.index');
    Route::patch('/widgets/location', [AdminWidgetController::class, 'updateLocation'])->name('widgets.location');
    Route::post('/widgets', [AdminWidgetController::class, 'store'])->name('widgets.store');
    Route::patch('/widgets/{widget}', [AdminWidgetController::class, 'update'])->name('widgets.update');
    Route::delete('/widgets/{widget}', [AdminWidgetController::class, 'destroy'])->name('widgets.destroy');

    // ── Live channels (global catalog of show backgrounds) ────────────────
    Route::get('/live-channels', [AdminLiveChannelController::class, 'index'])->name('live-channels.index');
    Route::post('/live-channels', [AdminLiveChannelController::class, 'store'])->name('live-channels.store');
    Route::patch('/live-channels/{liveChannel}', [AdminLiveChannelController::class, 'update'])->name('live-channels.update');
    Route::delete('/live-channels/{liveChannel}', [AdminLiveChannelController::class, 'destroy'])->name('live-channels.destroy');

    Route::get('/about', [\App\Http\Controllers\Admin\AboutController::class, 'show'])->name('about');

    // ── Backups (database-only or full; restore is CLI-only) ──────────────
    Route::get('/backups', [AdminBackupController::class, 'index'])->name('backups.index');
    Route::post('/backups', [AdminBackupController::class, 'store'])->middleware('throttle:6,1')->name('backups.store');
    Route::get('/backups/{backup}/download', [AdminBackupController::class, 'download'])->middleware('throttle:10,1')->name('backups.download');
    Route::delete('/backups/{backup}', [AdminBackupController::class, 'destroy'])->name('backups.destroy');

    // ── Slide Announcer releases (OS bundles + local-app archives) ────────
    Route::prefix('slide-announcer-releases')->name('slide-announcer-releases.')->group(function () {
        Route::get('/', [SlideAnnouncerReleaseController::class, 'index'])->name('index');
        Route::post('/chunk', [SlideAnnouncerReleaseController::class, 'chunk'])->name('chunk');
        Route::post('/finalize', [SlideAnnouncerReleaseController::class, 'finalize'])->name('finalize');
        Route::post('/{slideAnnouncerRelease}/channels', [SlideAnnouncerReleaseController::class, 'tag'])->name('channels.tag');
        Route::delete('/{slideAnnouncerRelease}/channels/{channel}', [SlideAnnouncerReleaseController::class, 'untag'])->name('channels.untag');
        Route::delete('/{slideAnnouncerRelease}', [SlideAnnouncerReleaseController::class, 'destroy'])->name('destroy');
    });
});
