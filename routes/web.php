<?php

use App\Models\VisitorLog;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::welcome')->name('home');

Route::get('/locale/{locale}', function (string $locale) {
    if (in_array($locale, ['en', 'es', 'ph'])) {
        session()->put('locale', $locale);
        App::setLocale($locale);
    }

    return redirect()->back();
})->name('locale.switch');

Route::get('/qr/{token}', function (string $token) {
    $visitorLog = VisitorLog::where('qr_code_token', $token)->firstOrFail();

    $result = new \Endroid\QrCode\Builder\Builder(
        writer: new \Endroid\QrCode\Writer\PngWriter,
        data: route('home') . '?checkout=' . $token,
        encoding: new \Endroid\QrCode\Encoding\Encoding('UTF-8'),
        errorCorrectionLevel: \Endroid\QrCode\ErrorCorrectionLevel::Low,
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
    Route::livewire('users', 'pages::users')->name('users');
    Route::livewire('watchlist', 'pages::watchlist')->name('watchlist');
});

require __DIR__.'/settings.php';
