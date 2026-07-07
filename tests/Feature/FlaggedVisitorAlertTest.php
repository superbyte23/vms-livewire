<?php

use App\Models\User;
use App\Models\Visitor;
use App\Notifications\VisitorFlagged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();
});

test('flagged visitor check-in marks the visitor as flagged', function () {
    Visitor::factory()->flagged()->create(['name' => 'John Doe']);

    Livewire::test('pages::welcome')
        ->set('name', 'John Doe')
        ->set('purpose', 'Meeting')
        ->call('checkIn');

    $this->assertDatabaseHas('visitors', [
        'name' => 'John Doe',
        'is_flagged' => true,
    ]);
});

test('all users are notified when a flagged visitor checks in', function () {
    $admin = User::factory()->create();
    $user = User::factory()->create();
    Visitor::factory()->flagged()->create(['name' => 'John Doe']);

    Livewire::test('pages::welcome')
        ->set('name', 'John Doe')
        ->set('purpose', 'Meeting')
        ->call('checkIn');

    Notification::assertSentTo($admin, VisitorFlagged::class);
    Notification::assertSentTo($user, VisitorFlagged::class);
});

test('non-flagged visitor check-in does not send alert', function () {
    User::factory()->create();

    Livewire::test('pages::welcome')
        ->set('name', 'Jane Doe')
        ->set('purpose', 'Delivery')
        ->call('checkIn');

    Notification::assertNothingSent();
});
