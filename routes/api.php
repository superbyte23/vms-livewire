<?php

use App\Http\Controllers\Api\Kiosk\KioskController;
use Illuminate\Support\Facades\Route;

Route::prefix('kiosk')->middleware('kiosk')->group(function () {
    Route::get('health', [KioskController::class, 'health'])->name('api.kiosk.health');

    Route::get('hosts/search', [KioskController::class, 'searchHosts'])->name('api.kiosk.hosts.search');
    Route::get('visitors/search', [KioskController::class, 'searchVisitors'])->name('api.kiosk.visitors.search');
    Route::post('visitors', [KioskController::class, 'storeVisitor'])->name('api.kiosk.visitors.store');
    Route::get('visitors/token/{token}', [KioskController::class, 'showVisitorByToken'])->name('api.kiosk.visitors.showByToken');

    Route::get('bookings/pending', [KioskController::class, 'pendingBookings'])->name('api.kiosk.bookings.pending');
    Route::get('bookings/token/{token}', [KioskController::class, 'showBookingByToken'])->name('api.kiosk.bookings.showByToken');

    Route::get('on-site', [KioskController::class, 'onSite'])->name('api.kiosk.on-site');

    Route::post('check-in', [KioskController::class, 'checkIn'])->name('api.kiosk.check-in');
    Route::post('check-out', [KioskController::class, 'checkOut'])->name('api.kiosk.check-out');
});
