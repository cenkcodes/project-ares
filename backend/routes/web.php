<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\MonetizationRuntimeController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SeoController;
use App\Http\Controllers\VideoController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])
    ->name('home');

Route::get('/videos', [VideoController::class, 'index'])
    ->name('videos.index');

Route::get('/categories/{slug}', [VideoController::class, 'category'])
    ->name('videos.category');

Route::get('/videos/{slug}', [VideoController::class, 'show'])
    ->name('videos.show');

Route::prefix('monetization')
    ->name('monetization.')
    ->group(function () {
        Route::post(
            '/interaction',
            [MonetizationRuntimeController::class, 'interaction']
        )
            ->middleware(
                'throttle:monetization-interaction'
            )
            ->name('interaction');

        Route::post(
            '/decision',
            [MonetizationRuntimeController::class, 'decision']
        )
            ->middleware(
                'throttle:monetization-decision'
            )
            ->name('decision');

        Route::post(
            '/event',
            [MonetizationRuntimeController::class, 'event']
        )
            ->middleware(
                'throttle:monetization-event'
            )
            ->name('event');
    });

Route::view('/about', 'pages.about')
    ->name('pages.about');

Route::view('/contact', 'pages.contact')
    ->name('pages.contact');

Route::view('/privacy', 'pages.privacy')
    ->name('pages.privacy');

Route::view('/terms', 'pages.terms')
    ->name('pages.terms');

Route::view('/content-removal', 'pages.content-removal')
    ->name('pages.content-removal');

Route::get('/sitemap.xml', [SeoController::class, 'sitemap'])
    ->name('seo.sitemap');

Route::get('/robots.txt', [SeoController::class, 'robots'])
    ->name('seo.robots');

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware([
    'auth',
    'verified',
])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get(
        '/profile',
        [ProfileController::class, 'edit']
    )->name('profile.edit');

    Route::patch(
        '/profile',
        [ProfileController::class, 'update']
    )->name('profile.update');

    Route::delete(
        '/profile',
        [ProfileController::class, 'destroy']
    )->name('profile.destroy');
});

require __DIR__.'/auth.php';
