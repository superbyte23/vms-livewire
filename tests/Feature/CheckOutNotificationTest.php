<?php

use App\Models\User;
use App\Models\Visitor;
use App\Models\VisitorLog;
use App\Notifications\VisitorCheckedOut;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();
});

test('host receives notification when visitor checks out from on-site list', function () {
    $host = User::factory()->create();
    $log = VisitorLog::factory()->create([
        'host_user_id' => $host->id,
        'status' => 'checked_in',
        'checked_in_at' => now()->subHours(2),
    ]);

    Livewire::test('pages::welcome')
        ->call('confirmCheckOut', $log->id)
        ->call('executeCheckOut');

    Notification::assertSentTo($host, VisitorCheckedOut::class);
});

test('host receives notification when visitor checks out via QR code', function () {
    $host = User::factory()->create();
    $visitor = Visitor::factory()->create([
        'qr_code_token' => 'test-qr-token-123',
    ]);
    VisitorLog::factory()->create([
        'visitor_id' => $visitor->id,
        'host_user_id' => $host->id,
        'status' => 'checked_in',
        'checked_in_at' => now()->subHours(2),
    ]);

    Livewire::test('pages::welcome')
        ->call('checkOutByToken', 'test-qr-token-123')
        ->call('confirmQrCheckOut');

    Notification::assertSentTo($host, VisitorCheckedOut::class);
});

test('no notification sent when visitor checks out without a host', function () {
    VisitorLog::factory()->create([
        'host_user_id' => null,
        'status' => 'checked_in',
        'checked_in_at' => now()->subHours(2),
    ]);

    Livewire::test('pages::welcome')
        ->call('confirmCheckOut', VisitorLog::first()->id)
        ->call('executeCheckOut');

    Notification::assertNothingSent();
});

test('no notification sent when visitor without host checks out via QR', function () {
    $visitor = Visitor::factory()->create([
        'qr_code_token' => 'qr-no-host-456',
    ]);
    VisitorLog::factory()->create([
        'visitor_id' => $visitor->id,
        'host_user_id' => null,
        'status' => 'checked_in',
        'checked_in_at' => now()->subHours(2),
    ]);

    Livewire::test('pages::welcome')
        ->call('checkOutByToken', 'qr-no-host-456')
        ->call('confirmQrCheckOut');

    Notification::assertNothingSent();
});

test('check-out notification contains correct visitor details', function () {
    $host = User::factory()->create();
    $log = VisitorLog::factory()->create([
        'host_user_id' => $host->id,
        'status' => 'checked_in',
        'checked_in_at' => now()->subHours(2),
    ]);

    Livewire::test('pages::welcome')
        ->call('confirmCheckOut', $log->id)
        ->call('executeCheckOut');

    Notification::assertSentTo($host, VisitorCheckedOut::class, function ($notification) use ($log) {
        expect($notification->visitorLog->visitor_id)->toBe($log->visitor_id);

        return true;
    });
});

test('checkout photo is saved when checking out from on-site list', function () {
    $log = VisitorLog::factory()->create([
        'status' => 'checked_in',
        'checked_in_at' => now()->subHours(2),
    ]);

    Livewire::test('pages::welcome')
        ->call('confirmCheckOut', $log->id)
        ->set('checkoutPhoto', 'data:image/jpeg;base64,checkout-photo-data')
        ->call('executeCheckOut');

    expect($log->fresh()->checkout_photo)->toBe('data:image/jpeg;base64,checkout-photo-data');
});

test('checkout photo is saved when checking out via QR code', function () {
    $visitor = Visitor::factory()->create([
        'qr_code_token' => 'qr-photo-token-789',
    ]);
    VisitorLog::factory()->create([
        'visitor_id' => $visitor->id,
        'status' => 'checked_in',
        'checked_in_at' => now()->subHours(2),
    ]);

    Livewire::test('pages::welcome')
        ->call('checkOutByToken', 'qr-photo-token-789')
        ->set('checkoutPhoto', 'data:image/jpeg;base64,qr-checkout-photo')
        ->call('confirmQrCheckOut');

    expect(VisitorLog::first()->checkout_photo)->toBe('data:image/jpeg;base64,qr-checkout-photo');
});

test('confirming check out resets the checkout photo', function () {
    $log = VisitorLog::factory()->create([
        'status' => 'checked_in',
        'checked_in_at' => now()->subHours(2),
    ]);

    Livewire::test('pages::welcome')
        ->set('checkoutPhoto', 'data:image/jpeg;base64,stale')
        ->call('confirmCheckOut', $log->id)
        ->assertSet('checkoutPhoto', '');
});
