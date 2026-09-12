<?php

use App\Models\User;
use App\Notifications\VisitorCheckedIn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();
});

test('host receives notification when a visitor checks in with a host selected', function () {
    $host = User::factory()->create();

    Livewire::test('pages::welcome')
        ->set('firstname', 'John')
        ->set('lastname', 'Doe')
        ->set('email', 'john@example.com')
        ->set('phone', '555-0123')
        ->set('company', 'Acme Inc')
        ->set('host', $host->name)
        ->set('hostUserId', (string) $host->id)
        ->set('purpose', 'Meeting')
        ->call('checkIn');

    Notification::assertSentTo($host, VisitorCheckedIn::class);
});

test('no notification sent when visitor checks in without a host', function () {
    Livewire::test('pages::welcome')
        ->set('firstname', 'Jane')
        ->set('lastname', 'Doe')
        ->set('host', '')
        ->set('hostUserId', null)
        ->set('purpose', 'Delivery')
        ->call('checkIn');

    Notification::assertNothingSent();
});

test('notification contains correct visitor details', function () {
    $host = User::factory()->create();

    Livewire::test('pages::welcome')
        ->set('firstname', 'Alice')
        ->set('lastname', 'Smith')
        ->set('email', 'alice@example.com')
        ->set('phone', '555-9999')
        ->set('company', 'Widget Co')
        ->set('host', $host->name)
        ->set('hostUserId', (string) $host->id)
        ->set('purpose', 'Interview')
        ->call('checkIn');

    Notification::assertSentTo($host, VisitorCheckedIn::class, function ($notification) {
        expect($notification->visit->visitor->name)->toBe('Alice Smith');
        expect($notification->visit->visitor->company)->toBe('Widget Co');
        expect($notification->visit->purpose)->toBe('Interview');

        return true;
    });
});
