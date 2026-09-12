<?php

use App\Models\Visit;
use App\Models\Visitor;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

uses()->group('feature')->group('public-schedule');

beforeEach(function () {
    Notification::fake();
});

test('guests can access the public schedule page', function () {
    $this->get(route('schedule-visit'))
        ->assertOk()
        ->assertSee('Schedule Your Visit');
});

test('wizard enforces date, type and visitor lookup in order', function () {
    Livewire::test('pages::components.booking-wizard')
        ->call('next')
        ->assertHasErrors(['expectedDate' => 'required'])
        ->assertSet('step', 1)
        ->set('expectedDate', now()->addDay()->format('Y-m-d'))
        ->call('next')
        ->assertSet('step', 2)
        ->call('next')
        ->assertHasErrors(['visitType' => 'required'])
        ->assertSet('step', 2)
        ->set('visitType', 'Consultation')
        ->call('next')
        ->assertSet('step', 3)
        // Step 3 starts at search: nothing selected, not a new visitor.
        ->call('next')
        ->assertHasErrors(['selectedVisitorId' => 'required'])
        ->assertSet('step', 3)
        // Skip to the new-visitor form: name becomes required.
        ->call('startNewVisitor')
        ->call('next')
        ->assertHasErrors(['firstname' => 'required'])
        ->assertSet('step', 3);

    expect(Visitor::count())->toBe(0)
        ->and(Visit::count())->toBe(0);
});

test('step 3 finds an existing record or skips to a new visitor', function () {
    $visitor = Visitor::factory()->create(['firstname' => 'Found', 'lastname' => 'Fiona', 'email' => 'fiona@example.com']);
    Visitor::factory()->create(['firstname' => 'Unrelated', 'lastname' => 'Ulysses']);

    // Search narrows to the matching record; selecting links it.
    $component = Livewire::test('pages::components.booking-wizard')
        ->set('expectedDate', now()->addDay()->format('Y-m-d'))
        ->call('next')
        ->set('visitType', 'Consultation')
        ->call('next')
        ->set('visitorSearch', 'Fiona');

    expect($component->get('visitorMatches')->pluck('id')->all())
        ->toBe([(string) $visitor->id]);

    $component
        ->call('selectVisitor', $visitor->id)
        ->assertSet('selectedVisitorId', (string) $visitor->id)
        ->call('next')
        ->assertSet('step', 4)
        ->assertSee('Found Fiona');

    // Back to search and skip when there is no match.
    $component
        ->call('back')
        ->call('clearSelectedVisitor')
        ->set('visitorSearch', 'Nobody Here')
        ->assertSee('No record found');

    expect($component->get('visitorMatches'))->toBeEmpty();

    $component
        ->call('startNewVisitor')
        ->set('firstname', 'Fresh')
        ->set('lastname', 'Fred')
        ->call('next')
        ->assertSet('step', 4)
        ->assertSee('Fresh Fred');
});

test('error helpers react while typing without advancing', function () {
    $component = Livewire::test('pages::components.booking-wizard')
        ->set('expectedDate', now()->addDay()->format('Y-m-d'))
        ->call('next')
        ->set('visitType', 'Consultation')
        ->call('next')
        ->call('startNewVisitor')
        // Typing an invalid email flags the field immediately.
        ->set('email', 'not-an-email')
        ->assertHasErrors(['email'])
        ->assertSee('valid email')
        ->assertSet('step', 3)
        // Fixing it clears the helper, still on the same step.
        ->set('email', 'fine@example.com')
        ->assertHasNoErrors()
        ->assertSet('step', 3);

    expect($component->get('step'))->toBe(3);
});

test('selecting an already-booked visitor warns and blocks', function () {
    $visitor = Visitor::factory()->create(['firstname' => 'Booked', 'lastname' => 'Brenda']);
    Visit::factory()->scheduled()->create(['visitor_id' => $visitor->id]);
    $visitsBefore = Visit::count();

    Livewire::test('pages::components.booking-wizard')
        ->set('expectedDate', now()->addDay()->format('Y-m-d'))
        ->call('next')
        ->set('visitType', 'Consultation')
        ->call('next')
        ->set('visitorSearch', 'Booked Brenda')
        ->call('selectVisitor', $visitor->id)
        ->assertSee('Already scheduled')
        ->call('next')
        ->assertHasErrors(['selectedVisitorId'])
        ->assertSet('step', 3);

    expect(Visit::count())->toBe($visitsBefore);
});

test('selecting an on-site visitor warns and blocks scheduling', function () {
    $visitor = Visitor::factory()->create(['firstname' => 'Onsite', 'lastname' => 'Ollie']);
    Visit::factory()->create(['visitor_id' => $visitor->id, 'status' => 'checked_in']);
    $visitsBefore = Visit::count();

    Livewire::test('pages::components.booking-wizard')
        ->set('expectedDate', now()->addDay()->format('Y-m-d'))
        ->call('next')
        ->set('visitType', 'Consultation')
        ->call('next')
        ->set('visitorSearch', 'Onsite Ollie')
        ->call('selectVisitor', $visitor->id)
        ->assertSee('Already on-site')
        ->call('next')
        ->assertHasErrors(['selectedVisitorId'])
        ->assertSet('step', 3);

    expect(Visit::count())->toBe($visitsBefore);
});

test('confirm is blocked for a booked email in new-visitor mode', function () {
    $visitor = Visitor::factory()->create(['email' => 'booked@example.com']);
    Visit::factory()->scheduled()->create(['visitor_id' => $visitor->id]);

    Livewire::test('pages::components.booking-wizard')
        ->set('expectedDate', now()->addDay()->format('Y-m-d'))
        ->call('next')
        ->set('visitType', 'Event Attendance')
        ->call('next')
        ->call('startNewVisitor')
        ->set('firstname', 'Imposter')
        ->set('lastname', 'Ian')
        ->set('email', 'booked@example.com')
        ->call('next')
        ->assertSet('step', 4)
        ->call('confirm')
        ->assertHasErrors(['email'])
        ->assertSet('step', 4);

    expect(Visit::scheduled()->count())->toBe(1);
});

test('selecting an existing record books without creating a visitor', function () {
    $visitor = Visitor::factory()->create(['firstname' => 'Regular', 'lastname' => 'Rita']);
    $countBefore = Visitor::count();

    Livewire::test('pages::components.booking-wizard')
        ->set('expectedDate', now()->addDay()->format('Y-m-d'))
        ->call('next')
        ->set('visitType', 'Consultation')
        ->call('next')
        ->set('visitorSearch', 'Regular Rita')
        ->call('selectVisitor', $visitor->id)
        ->call('next')
        ->call('confirm')
        ->assertSet('step', 5)
        ->assertSee('Regular Rita');

    expect(Visitor::count())->toBe($countBefore);

    $booking = Visit::scheduled()->where('visitor_id', $visitor->id)->first();

    expect($booking)->not->toBeNull()
        ->and($booking->visit_type)->toBe('Consultation');
});

test('wizard rejects past dates', function () {
    Livewire::test('pages::components.booking-wizard')
        ->set('expectedDate', now()->subDay()->format('Y-m-d'))
        ->call('next')
        ->assertHasErrors(['expectedDate'])
        ->assertSet('step', 1);
});

test('full wizard creates one visitor, one booking and shows the pass', function () {
    $component = Livewire::test('pages::components.booking-wizard')
        ->set('expectedDate', now()->addDays(2)->format('Y-m-d'))
        ->call('next')
        ->set('visitType', 'Interviewee')
        ->set('purpose', 'Final round')
        ->call('next')
        ->call('startNewVisitor')
        ->set('firstname', 'Wally')
        ->set('lastname', 'Wizard')
        ->set('email', 'wally@example.com')
        ->set('phone', '555-0142')
        ->set('company', 'Acme Inc')
        ->set('profilePhoto', 'data:image/jpeg;base64,profile')
        ->set('validIdPhoto', 'data:image/jpeg;base64,idcard')
        ->call('next')
        ->assertSet('step', 4)
        ->call('confirm')
        ->assertSet('step', 5)
        ->assertSee('Visit scheduled')
        ->assertSee('Scheduled Visit Pass')
        ->assertDontSee('Check in now');

    expect(Visitor::count())->toBe(1);

    $visitor = Visitor::where('email', 'wally@example.com')->first();
    $booking = Visit::scheduled()->where('visitor_id', $visitor->id)->first();

    expect($booking)->not->toBeNull()
        ->and($booking->visit_type)->toBe('Interviewee')
        ->and($booking->purpose)->toBe('Final round')
        ->and($booking->expected_date->toDateString())->toBe(now()->addDays(2)->toDateString())
        ->and($booking->qr_code_token)->not->toBeNull()
        ->and($visitor->photo)->toBe('data:image/jpeg;base64,profile')
        ->and($visitor->government_id_photo)->toBe('data:image/jpeg;base64,idcard');

    // Back still works from confirm without duplicating anything.
    $component->call('back')->assertSet('step', 4);

    expect(Visitor::count())->toBe(1)
        ->and(Visit::count())->toBe(1);
});

test('booking scheduled for today can check in right away', function () {
    Livewire::test('pages::components.booking-wizard')
        ->set('expectedDate', now()->toDateString())
        ->call('next')
        ->set('visitType', 'Meeting')
        ->call('next')
        ->call('startNewVisitor')
        ->set('firstname', 'Today')
        ->set('lastname', 'Tina')
        ->set('email', 'tina@example.com')
        ->call('next')
        ->call('confirm')
        ->assertSet('step', 5)
        ->assertDispatched('visitor-registered')
        ->assertSee('Check in now')
        ->set('checkinPhoto', 'data:image/jpeg;base64,checkin')
        ->call('checkInNow')
        ->assertSee('Checked in!');

    $visitor = Visitor::where('email', 'tina@example.com')->first();
    $visit = Visit::where('visitor_id', $visitor->id)->first();

    expect($visit->status)->toBe('checked_in')
        ->and($visit->badge_number)->not->toBeNull()
        ->and($visit->checked_in_at)->not->toBeNull()
        ->and($visit->photo)->toBe('data:image/jpeg;base64,checkin');
});

test('known email reuses the visitor instead of duplicating', function () {
    $visitor = Visitor::factory()->create(['firstname' => 'Known', 'lastname' => 'Kelly', 'email' => 'kelly@example.com']);
    $countBefore = Visitor::count();

    Livewire::test('pages::components.booking-wizard')
        ->set('expectedDate', now()->addDay()->format('Y-m-d'))
        ->call('next')
        ->set('visitType', 'Event Attendance')
        ->call('next')
        ->call('startNewVisitor')
        ->set('firstname', 'Someone')
        ->set('lastname', 'Else')
        ->set('email', 'kelly@example.com')
        ->call('next')
        ->call('confirm')
        ->assertSet('step', 5);

    expect(Visitor::count())->toBe($countBefore)
        ->and(Visit::scheduled()->where('visitor_id', $visitor->id)->count())->toBe(1);
});

test('scheduled booking appears in the kiosk flow', function () {
    Livewire::test('pages::components.booking-wizard')
        ->set('expectedDate', now()->addDay()->format('Y-m-d'))
        ->call('next')
        ->set('visitType', 'Consultation')
        ->call('next')
        ->call('startNewVisitor')
        ->set('firstname', 'Kiosk')
        ->set('lastname', 'Kate')
        ->set('phone', '555-0160')
        ->call('next')
        ->call('confirm');

    $visitor = Visitor::where('name', 'Kiosk Kate')->first();

    Livewire::test('pages::welcome')
        ->set('search', 'Kiosk Kate')
        ->assertCount('bookingMatches', 1)
        ->call('selectBooking', Visit::scheduled()->where('visitor_id', $visitor->id)->first()->id)
        ->assertSet('selectedVisitorId', (string) $visitor->id)
        ->call('checkIn');

    expect($visitor->fresh())->not->toBeNull()
        ->and(Visit::where('visitor_id', $visitor->id)->where('status', 'checked_in')->exists())->toBeTrue();
});
