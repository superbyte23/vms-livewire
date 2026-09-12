<?php

use App\Models\User;
use App\Models\Visit;
use App\Models\Visitor;
use App\Notifications\VisitBooked;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

uses()->group('feature')->group('booking');

beforeEach(function () {
    Notification::fake();
});

// ── Public booking (formerly pre-register) ──

test('guests can access the public booking page', function () {
    $this->get(route('pre-register'))->assertOk();
});

test('public booking creates one visitor plus one scheduled log', function () {
    $countBefore = Visitor::count();

    Livewire::test('pages::pre-register')
        ->set('firstname', 'Booked')
        ->set('lastname', 'Betty')
        ->set('email', 'betty@example.com')
        ->set('phone', '555-0123')
        ->set('company', 'Acme Inc')
        ->set('purpose', 'Meeting')
        ->call('submit');

    expect(Visitor::count())->toBe($countBefore + 1);

    $visitor = Visitor::where('email', 'betty@example.com')->first();
    $booking = $visitor ? Visit::scheduled()->where('visitor_id', $visitor->id)->first() : null;

    expect($visitor)->not->toBeNull()
        ->and($visitor->name)->toBe('Booked Betty')
        ->and($booking)->not->toBeNull()
        ->and($booking->purpose)->toBe('Meeting')
        ->and($booking->qr_code_token)->not->toBeNull();

    Notification::assertNothingSent();
});

test('public booking with an already-booked email is blocked', function () {
    $visitor = Visitor::factory()->create(['email' => 'booked@example.com']);
    Visit::factory()->scheduled()->create(['visitor_id' => $visitor->id]);

    $visitorsBefore = Visitor::count();
    $visitsBefore = Visit::count();

    Livewire::test('pages::pre-register')
        ->set('firstname', 'Duplicate')
        ->set('lastname', 'Deb')
        ->set('email', 'booked@example.com')
        ->set('phone', '555-0123')
        ->call('submit')
        ->assertHasErrors(['email']);

    expect(Visitor::count())->toBe($visitorsBefore)
        ->and(Visit::count())->toBe($visitsBefore);
});

test('public booking requires a name and creates nothing otherwise', function () {
    Livewire::test('pages::pre-register')
        ->set('firstname', '')
        ->set('lastname', '')
        ->call('submit')
        ->assertHasErrors(['firstname' => 'required']);

    expect(Visitor::count())->toBe(0)
        ->and(Visit::count())->toBe(0);
});

test('public booking stores the government ID capture separately from the profile photo', function () {
    $idPhoto = 'data:image/jpeg;base64,'.base64_encode('fake-image-bytes');

    Livewire::test('pages::pre-register')
        ->set('firstname', 'Photo')
        ->set('lastname', 'Phil')
        ->set('phone', '555-0123')
        ->set('validIdPhoto', $idPhoto)
        ->call('submit');

    $visitor = Visitor::where('name', 'Photo Phil')->first();

    expect($visitor)->not->toBeNull()
        ->and($visitor->government_id_photo)->toBe($idPhoto)
        ->and($visitor->photo)->toBeNull();
});

test('completion page shows the pass for a scheduled booking', function () {
    $visitor = Visitor::factory()->create(['firstname' => 'Pass', 'lastname' => 'Pam', 'company' => 'Acme Inc']);
    $booking = Visit::factory()->scheduled()->create(['visitor_id' => $visitor->id]);

    $this->get(route('pre-register.complete', $booking))
        ->assertOk()
        ->assertSee('Pass Pam')
        ->assertSee('Acme Inc')
        ->assertSee('Download Card');
});

test('completion page shows a notice once the booking is checked in', function () {
    $visitor = Visitor::factory()->create();
    $booking = Visit::factory()->create(['visitor_id' => $visitor->id, 'status' => 'checked_in']);

    $this->get(route('pre-register.complete', $booking))
        ->assertOk()
        ->assertSee('no longer valid')
        ->assertDontSee('Download Card');
});

// ── Kiosk booking flow ──

test('kiosk search finds booking matches without duplicating the visitor row', function () {
    $booked = Visitor::factory()->create(['firstname' => 'Booked', 'lastname' => 'Boris']);
    $plain = Visitor::factory()->create(['firstname' => 'Booked', 'lastname' => 'Olivia']);
    Visit::factory()->scheduled()->create(['visitor_id' => $booked->id]);
    Visit::factory()->create(['visitor_id' => $booked->id, 'status' => 'checked_out']);

    $component = Livewire::test('pages::welcome')->set('search', 'Booked');

    expect($component->get('bookingMatches'))->toHaveCount(1)
        ->and($component->get('searchResults')->pluck('id')->all())
        ->toContain((string) $plain->id)
        ->not->toContain((string) $booked->id);
});

test('kiosk lists today scheduled visits below the search', function () {
    $today = Visitor::factory()->create(['firstname' => 'Today', 'lastname' => 'Tess', 'photo' => 'data:image/jpeg;base64,todayphoto']);
    $future = Visitor::factory()->create(['firstname' => 'Future', 'lastname' => 'Fred']);
    Visit::factory()->scheduled()->create([
        'visitor_id' => $today->id,
        'expected_date' => now()->toDateString(),
    ]);
    Visit::factory()->scheduled()->create([
        'visitor_id' => $future->id,
        'expected_date' => now()->addDays(5)->toDateString(),
    ]);

    $component = Livewire::test('pages::welcome');

    expect($component->get('todayScheduled')->pluck('visitor_id')->all())
        ->toContain((string) $today->id)
        ->not->toContain((string) $future->id);

    $component
        ->assertSee('Today')
        ->assertSee('Today Tess')
        ->assertSee('data:image/jpeg;base64,todayphoto')
        ->call('selectBooking', Visit::scheduled()->where('visitor_id', $today->id)->first()->id)
        ->assertSet('selectedVisitorId', (string) $today->id);
});

test('selecting a booking links the visitor and prefills details', function () {
    $host = User::factory()->create();
    $visitor = Visitor::factory()->create(['firstname' => 'Alice', 'lastname' => 'Smith', 'company' => 'Widget Co']);
    $booking = Visit::factory()->scheduled()->create([
        'visitor_id' => $visitor->id,
        'host' => $host->name,
        'host_user_id' => $host->id,
        'purpose' => 'Interview',
        'visit_type' => 'Interviewee',
    ]);

    Livewire::test('pages::welcome')
        ->call('selectBooking', $booking->id)
        ->assertSet('step', 2)
        ->assertSet('selectedBookingId', $booking->id)
        ->assertSet('selectedVisitorId', (string) $visitor->id)
        ->assertSet('firstname', 'Alice')
        ->assertSet('lastname', 'Smith')
        ->assertSet('company', 'Widget Co')
        ->assertSet('purpose', 'Interview')
        ->assertSet('visitType', 'Interviewee');
});

test('selecting a visitor skips straight to step 2', function () {
    $visitor = Visitor::factory()->create(['firstname' => 'Skip', 'lastname' => 'Sam']);

    Livewire::test('pages::welcome')
        ->set('search', 'Skip Sam')
        ->call('selectVisitor', $visitor->id)
        ->assertSet('step', 2)
        ->assertSet('selectedVisitorId', $visitor->id)
        ->assertSee('Checking in as')
        ->assertSee('Skip Sam');
});

test('kiosk check-in with a booking flips the row instead of creating one', function () {
    $visitor = Visitor::factory()->create(['firstname' => 'Repeat', 'lastname' => 'Ron']);
    $booking = Visit::factory()->scheduled()->create([
        'visitor_id' => $visitor->id,
        'purpose' => 'Booked review',
    ]);

    $visitsBefore = Visit::count();
    $visitorsBefore = Visitor::count();

    Livewire::test('pages::welcome')
        ->call('selectBooking', $booking->id)
        ->call('checkIn');

    expect(Visitor::count())->toBe($visitorsBefore)
        ->and(Visit::count())->toBe($visitsBefore)
        ->and($booking->fresh()->status)->toBe('checked_in')
        ->and($booking->fresh()->badge_number)->not->toBeNull()
        ->and($booking->fresh()->checked_in_at)->not->toBeNull();
});

test('switching visitors clears prefilled booking details', function () {
    $booked = Visitor::factory()->create(['firstname' => 'Booked', 'lastname' => 'Betty']);
    $booking = Visit::factory()->scheduled()->create([
        'visitor_id' => $booked->id,
        'host' => 'Helen',
        'purpose' => 'Interview',
        'visit_type' => 'Meeting',
    ]);
    $other = Visitor::factory()->create(['firstname' => 'Other', 'lastname' => 'Ollie']);

    Livewire::test('pages::welcome')
        ->call('selectBooking', $booking->id)
        ->assertSet('purpose', 'Interview')
        ->assertSet('host', 'Helen')
        ->assertSet('visitType', 'Meeting')
        ->call('switchVisitor')
        ->assertSet('step', 1)
        ->assertSet('purpose', '')
        ->assertSet('host', '')
        ->assertSet('visitType', '')
        ->call('selectVisitor', $other->id)
        ->assertSet('step', 2)
        ->assertSet('purpose', '')
        ->assertSet('host', '')
        ->assertSet('visitType', '');
});

test('back then reselecting clears prefilled booking details', function () {
    $booked = Visitor::factory()->create(['firstname' => 'Booked', 'lastname' => 'Bonnie']);
    $booking = Visit::factory()->scheduled()->create([
        'visitor_id' => $booked->id,
        'host' => 'Helen',
        'purpose' => 'Interview',
        'visit_type' => 'Meeting',
    ]);
    $other = Visitor::factory()->create(['firstname' => 'Other', 'lastname' => 'Otis']);

    Livewire::test('pages::welcome')
        ->call('selectBooking', $booking->id)
        ->assertSet('step', 2)
        ->assertSet('purpose', 'Interview')
        ->call('prevStep')
        ->assertSet('step', 1)
        ->set('search', 'Other Otis')
        ->call('selectVisitor', $other->id)
        ->assertSet('step', 2)
        ->assertSet('selectedVisitorId', $other->id)
        ->assertSet('purpose', '')
        ->assertSet('host', '')
        ->assertSet('visitType', '');
});

test('check-in with an existing record stores the visit type', function () {
    $visitor = Visitor::factory()->create(['firstname' => 'Typed', 'lastname' => 'Tess']);

    Livewire::test('pages::welcome')
        ->call('selectVisitor', $visitor->id)
        ->assertSet('step', 2)
        ->set('visitType', 'Interviewee')
        ->set('purpose', 'Final round')
        ->call('checkIn');

    $visit = Visit::where('visitor_id', $visitor->id)->first();

    expect($visit)->not->toBeNull()
        ->and($visit->visit_type)->toBe('Interviewee')
        ->and($visit->purpose)->toBe('Final round')
        ->and($visit->status)->toBe('checked_in');
});

test('kiosk registration hands the booked visitor back to check-in', function () {
    $visitor = Visitor::factory()->create(['firstname' => 'Handed', 'lastname' => 'Hank']);
    $booking = Visit::factory()->scheduled()->create(['visitor_id' => $visitor->id]);

    Livewire::test('pages::welcome')
        ->call('startCreating')
        ->call('registerFromWizard', $booking->id)
        ->assertSet('showCreateForm', false)
        ->assertSet('step', 2)
        ->assertSet('selectedBookingId', $booking->id)
        ->assertSet('selectedVisitorId', (string) $visitor->id);

    Livewire::test('pages::welcome')
        ->call('registerFromWizard', '00000000-0000-0000-0000-000000000000')
        ->assertSet('step', 1);
});

test('review requires a visit type', function () {
    $visitor = Visitor::factory()->create(['firstname' => 'Gated', 'lastname' => 'Gary']);

    Livewire::test('pages::welcome')
        ->call('selectVisitor', $visitor->id)
        ->assertSet('step', 2)
        ->call('nextStep')
        ->assertHasErrors(['visitType', 'purpose'])
        ->assertSet('step', 2)
        ->set('visitType', 'Meeting')
        ->call('nextStep')
        ->assertHasErrors(['purpose'])
        ->assertSet('step', 2)
        ->set('purpose', 'Roadmap review')
        ->call('nextStep')
        ->assertSet('step', 3)
        ->assertHasNoErrors();
});

test('visit detail errors clear reactively while typing', function () {
    $visitor = Visitor::factory()->create(['firstname' => 'Reactive', 'lastname' => 'Rita']);

    Livewire::test('pages::welcome')
        ->call('selectVisitor', $visitor->id)
        ->call('nextStep')
        ->assertHasErrors(['visitType', 'purpose'])
        ->set('visitType', 'Meeting')
        ->assertHasErrors(['purpose'])
        ->set('purpose', 'Roadmap review')
        ->assertHasNoErrors();
});

test('scanning a booking token selects it, invalid tokens select nothing', function () {
    $visitor = Visitor::factory()->create(['firstname' => 'Scanner', 'lastname' => 'Sam']);
    $booking = Visit::factory()->scheduled()->create([
        'visitor_id' => $visitor->id,
        'qr_code_token' => 'book-scan-token',
    ]);

    Livewire::test('pages::welcome')
        ->call('scanBookingByToken', 'book-scan-token')
        ->assertSet('selectedBookingId', $booking->id)
        ->assertSet('selectedVisitorId', (string) $visitor->id)
        ->assertSet('activeTab', 'checkin');

    Livewire::test('pages::welcome')
        ->call('scanBookingByToken', 'fake-token')
        ->assertSet('selectedBookingId', null);
});

test('kiosk booking token in url prefills the visitor', function () {
    $visitor = Visitor::factory()->create(['firstname' => 'QR', 'lastname' => 'Quinn']);
    Visit::factory()->scheduled()->create([
        'visitor_id' => $visitor->id,
        'qr_code_token' => 'book-url-token',
    ]);

    $this->get(route('home', ['booking' => 'book-url-token']))
        ->assertOk()
        ->assertSee('Booked visit found')
        ->assertSee('QR Quinn');
});

// ── Staff booking from the visitors page ──

test('visitors page books without duplicating the visitor', function () {
    $user = User::factory()->create();
    $visitor = Visitor::factory()->create(['firstname' => 'Schedule', 'lastname' => 'Sam']);
    $host = User::factory()->create();
    $this->actingAs($user);

    $visitorsBefore = Visitor::count();

    Livewire::test('pages::visitors')
        ->call('openScheduleModal', $visitor->id)
        ->set('scheduleExpectedDate', now()->addDay()->format('Y-m-d'))
        ->set('scheduleVisitType', 'Consultation')
        ->set('schedulePurpose', 'Quarterly check')
        ->set('scheduleHostUserId', (string) $host->id)
        ->call('scheduleVisit');

    expect(Visitor::count())->toBe($visitorsBefore);

    $booking = Visit::scheduled()->where('visitor_id', $visitor->id)->first();

    expect($booking)->not->toBeNull()
        ->and($booking->purpose)->toBe('Quarterly check')
        ->and($booking->visit_type)->toBe('Consultation');

    Notification::assertSentTo($host, VisitBooked::class);
});

test('visitors page warns and blocks a second active booking', function () {
    $user = User::factory()->create();
    $visitor = Visitor::factory()->create(['firstname' => 'Double', 'lastname' => 'Dan']);
    $this->actingAs($user);

    Visit::factory()->scheduled()->create(['visitor_id' => $visitor->id]);
    $visitsBefore = Visit::count();

    Livewire::test('pages::visitors')
        ->call('openScheduleModal', $visitor->id)
        ->assertSee('Already scheduled')
        ->set('scheduleExpectedDate', now()->addDay()->format('Y-m-d'))
        ->set('scheduleVisitType', 'Consultation')
        ->call('scheduleVisit')
        ->assertHasErrors(['scheduleExpectedDate']);

    expect(Visit::count())->toBe($visitsBefore);
});

test('cancelled or past visits do not block a new booking', function () {
    $user = User::factory()->create();
    $cancelled = Visitor::factory()->create(['firstname' => 'Cancel', 'lastname' => 'Cara']);
    $past = Visitor::factory()->create(['firstname' => 'Past', 'lastname' => 'Pete']);
    $this->actingAs($user);

    Visit::factory()->cancelled()->create(['visitor_id' => $cancelled->id]);
    Visit::factory()->checkedOut()->create(['visitor_id' => $past->id]);

    foreach ([$cancelled, $past] as $visitor) {
        Livewire::test('pages::visitors')
            ->call('openScheduleModal', $visitor->id)
            ->assertDontSee('Already scheduled')
            ->set('scheduleExpectedDate', now()->addDay()->format('Y-m-d'))
            ->set('scheduleVisitType', 'Event Attendance')
            ->call('scheduleVisit')
            ->assertHasNoErrors();

        expect(Visit::scheduled()->where('visitor_id', $visitor->id)->exists())->toBeTrue();
    }
});

// ── Booking management in the visitor log ──

test('visits list, filters and cancels bookings', function () {
    $user = User::factory()->create();
    $visitor = Visitor::factory()->create(['firstname' => 'Log', 'lastname' => 'Larry']);
    $this->actingAs($user);

    $booking = Visit::factory()->scheduled()->create(['visitor_id' => $visitor->id]);

    Livewire::test('pages::visits')
        ->set('search', 'Larry')
        ->assertSee('Log Larry')
        ->call('clearFilters')
        ->set('statusFilter', 'scheduled')
        ->assertSee('Log Larry')
        ->call('cancelBooking', $booking->id);

    expect($booking->fresh()->status)->toBe('cancelled');
});
