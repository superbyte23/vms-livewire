<?php

use App\Models\Visit;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportEvents\SupportEvents;
use Livewire\Livewire;

uses(RefreshDatabase::class)->group('feature')->group('kiosk');

test('booking wizard dispatches the default visitor-registered event', function () {
    Livewire::test('pages::components.booking-wizard')
        ->set('expectedDate', now()->addDay()->format('Y-m-d'))
        ->call('next')
        ->set('visitType', 'Consultation')
        ->call('next')
        ->call('startNewVisitor')
        ->set('firstname', 'Default')
        ->set('lastname', 'Dana')
        ->call('next')
        ->call('confirm')
        ->assertDispatched('visitor-registered');
});

test('booking wizard dispatches a scoped event when configured', function () {
    Livewire::test('pages::components.booking-wizard', ['registeredEvent' => 'kiosk-visitor-registered'])
        ->set('expectedDate', now()->addDay()->format('Y-m-d'))
        ->call('next')
        ->set('visitType', 'Consultation')
        ->call('next')
        ->call('startNewVisitor')
        ->set('firstname', 'Scoped')
        ->set('lastname', 'Sonia')
        ->call('next')
        ->call('confirm')
        ->assertDispatched('kiosk-visitor-registered');
});

test('kiosk only reacts to its own registration event', function () {
    $visitor = Visitor::factory()->create();
    $booking = Visit::factory()->scheduled()->create([
        'visitor_id' => $visitor->id,
        'expected_date' => today()->format('Y-m-d'),
    ]);

    $component = Livewire::test('pages::welcome');

    $listenerNames = SupportEvents::getListenerEventNames($component->instance());

    expect($listenerNames)->toContain('kiosk-visitor-registered')
        ->and($listenerNames)->not->toContain('visitor-registered');

    $component->dispatch('kiosk-visitor-registered', bookingId: (string) $booking->id)
        ->assertSet('selectedBookingId', (string) $booking->id)
        ->assertSet('selectedVisitorId', (string) $visitor->id)
        ->assertSet('step', 2);
});

test('standalone kiosk keeps the full-screen pre-load skeleton', function () {
    Livewire::test('pages::welcome')
        ->assertSeeHtml('min-h-svh')
        ->assertSeeHtml('z-[100]');
});
