<?php

use App\Models\User;
use App\Models\Visitor;
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
    $visitor = Visitor::factory()->create([
        'host_user_id' => $host->id,
        'status' => 'checked_in',
        'checked_in_at' => now()->subHours(2),
    ]);

    Livewire::test('pages::welcome')
        ->call('confirmCheckOut', (string) $visitor->id)
        ->call('executeCheckOut');

    Notification::assertSentTo($host, VisitorCheckedOut::class);
});

test('host receives notification when visitor checks out via QR code', function () {
    $host = User::factory()->create();
    $visitor = Visitor::factory()->create([
        'host_user_id' => $host->id,
        'status' => 'checked_in',
        'checked_in_at' => now()->subHours(2),
        'qr_code_token' => 'test-qr-token-123',
    ]);

    Livewire::test('pages::welcome')
        ->call('checkOutByToken', 'test-qr-token-123')
        ->call('confirmQrCheckOut');

    Notification::assertSentTo($host, VisitorCheckedOut::class);
});

test('no notification sent when visitor checks out without a host', function () {
    $visitor = Visitor::factory()->create([
        'host_user_id' => null,
        'status' => 'checked_in',
        'checked_in_at' => now()->subHours(2),
    ]);

    Livewire::test('pages::welcome')
        ->call('confirmCheckOut', (string) $visitor->id)
        ->call('executeCheckOut');

    Notification::assertNothingSent();
});

test('no notification sent when visitor without host checks out via QR', function () {
    $visitor = Visitor::factory()->create([
        'host_user_id' => null,
        'status' => 'checked_in',
        'checked_in_at' => now()->subHours(2),
        'qr_code_token' => 'qr-no-host-456',
    ]);

    Livewire::test('pages::welcome')
        ->call('checkOutByToken', 'qr-no-host-456')
        ->call('confirmQrCheckOut');

    Notification::assertNothingSent();
});

test('check-out notification contains correct visitor details', function () {
    $host = User::factory()->create();
    $visitor = Visitor::factory()->create([
        'host_user_id' => $host->id,
        'status' => 'checked_in',
        'checked_in_at' => now()->subHours(2),
    ]);

    Livewire::test('pages::welcome')
        ->call('confirmCheckOut', (string) $visitor->id)
        ->call('executeCheckOut');

    Notification::assertSentTo($host, VisitorCheckedOut::class, function ($notification) use ($visitor) {
        expect($notification->visitor->id)->toBe($visitor->id);

        return true;
    });
});
