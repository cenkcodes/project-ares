<?php

use App\Http\Controllers\AgeGateController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\GuideController;
use App\Http\Controllers\LegalContactController;
use App\Http\Controllers\MonetizationRuntimeController;
use App\Http\Controllers\PrivacyConsentController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SeoController;
use App\Http\Controllers\VideoController;
use App\Http\Middleware\RequireAdultConsent;
use Illuminate\Support\Facades\Route;

Route::get('/age-check', [AgeGateController::class, 'show'])
    ->name('age-gate.show');
Route::post('/age-check/accept', [AgeGateController::class, 'accept'])
    ->name('age-gate.accept');
Route::post('/age-check/deny', [AgeGateController::class, 'deny'])
    ->name('age-gate.deny');
Route::view('/age-restricted', 'age-gate.denied')
    ->name('age-gate.denied');

Route::get('/', [HomeController::class, 'index'])
    ->middleware(RequireAdultConsent::class)
    ->name('home');
Route::get('/videos', [VideoController::class, 'index'])
    ->middleware(RequireAdultConsent::class)
    ->name('videos.index');

Route::get('/guides', [GuideController::class, 'index'])
    ->middleware(RequireAdultConsent::class)
    ->name('guides.index');
Route::get('/guides/{slug}', [GuideController::class, 'show'])
    ->middleware(RequireAdultConsent::class)
    ->name('guides.show');

Route::redirect('/categories/latin', '/categories/latina', 301);

Route::get('/categories/{slug}', [VideoController::class, 'category'])
    ->middleware(RequireAdultConsent::class)
    ->name('videos.category');
Route::get('/videos/{slug}', [VideoController::class, 'show'])
    ->middleware(RequireAdultConsent::class)
    ->name('videos.show');

Route::prefix('monetization')
    ->name('monetization.')
    ->middleware(RequireAdultConsent::class)
    ->group(function () {
        Route::post('/interaction', [MonetizationRuntimeController::class, 'interaction'])
            ->middleware('throttle:monetization-interaction')
            ->name('interaction');
        Route::post('/decision', [MonetizationRuntimeController::class, 'decision'])
            ->middleware('throttle:monetization-decision')
            ->name('decision');
        Route::post('/event', [MonetizationRuntimeController::class, 'event'])
            ->middleware('throttle:monetization-event')
            ->name('event');
    });

Route::view('/about', 'pages.about')->name('pages.about');

Route::get('/contact', [LegalContactController::class, 'show'])
    ->name('pages.contact');
Route::post('/contact', [LegalContactController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('pages.contact.submit');

Route::post('/privacy/consent', [PrivacyConsentController::class, 'store'])
    ->middleware('throttle:60,1')
    ->name('privacy.consent.store');

Route::view('/privacy', 'pages.privacy')->name('pages.privacy');
Route::view('/terms', 'pages.terms')->name('pages.terms');
Route::view('/cookie-policy', 'pages.cookie-policy')->name('pages.cookie-policy');
Route::view('/2257', 'pages.record-keeping')->name('pages.record-keeping');
Route::view('/content-removal', 'pages.content-removal')->name('pages.content-removal');

Route::redirect('/2257-compliance', '/2257', 301);
Route::redirect('/dmca', '/content-removal#copyright-dmca', 301);
Route::redirect('/abuse', '/content-removal#illegal-content', 301);

Route::get('/sitemap.xml', [SeoController::class, 'sitemap'])->name('seo.sitemap');
Route::get('/video-sitemap.xml', [SeoController::class, 'videoSitemap'])->name('seo.video-sitemap');
Route::get('/robots.txt', [SeoController::class, 'robots'])->name('seo.robots');

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
