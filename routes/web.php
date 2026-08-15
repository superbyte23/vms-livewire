<?php

use App\Models\PreRegistration;
use App\Models\Visitor;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::welcome')->name('home');

Route::livewire('pre-register', 'pages::pre-register')->name('pre-register');

Route::livewire('pre-register/complete/{preRegistration}', 'pages::pre-register-complete')->name('pre-register.complete');

Route::get('/locale/{locale}', function (string $locale) {
    if (in_array($locale, ['en', 'es', 'ph'])) {
        session()->put('locale', $locale);
        App::setLocale($locale);
    }

    return redirect()->back();
})->name('locale.switch');

Route::get('/qr/{token}', function (string $token) {
    $data = null;

    if (Visitor::where('qr_code_token', $token)->exists()) {
        $data = route('home').'?checkout='.$token;
    } elseif (PreRegistration::where('qr_code_token', $token)->exists()) {
        $data = route('home').'?pre='.$token;
    }

    abort_unless($data, 404);

    $result = new Builder(
        writer: new PngWriter,
        data: $data,
        encoding: new Encoding('UTF-8'),
        errorCorrectionLevel: ErrorCorrectionLevel::Low,
        size: 400,
        margin: 10,
    );

    return response($result->build()->getString())
        ->header('Content-Type', 'image/png')
        ->header('Cache-Control', 'public, max-age=3600');
})->name('qr.code');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');
    Route::livewire('visitors', 'pages::visitors')->name('visitors');
    Route::livewire('pre-registrations', 'pages::pre-registrations')->name('pre-registrations');
    Route::livewire('visitor-logs', 'pages::visitor-logs')->name('visitor-logs');
    Route::livewire('users', 'pages::users')->name('users');
    Route::livewire('watchlist', 'pages::watchlist')->name('watchlist');
});

require __DIR__.'/settings.php';
