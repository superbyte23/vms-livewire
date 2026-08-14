<?php

use App\Models\PreRegistration;
use App\Models\User;
use App\Models\Visitor;
use App\Models\VisitorLog;
use App\Notifications\VisitorCheckedIn;
use App\Notifications\VisitorPreRegistered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

uses(RefreshDatabase::class)->group('feature')->group('pre-registration');

beforeEach(function () {
    Notification::fake();
});

// ── Public pre-register page ──

test('guests can access the public pre-register page', function () {
    $this->get(route('pre-register'))->assertOk();
});

test('visitor can pre-register and host is notified', function () {
    $host = User::factory()->create();

    Livewire::test('pages::pre-register')
        ->set('name', 'John Doe')
        ->set('email', 'john@example.com')
        ->set('phone', '555-0123')
        ->set('company', 'Acme Inc')
        ->set('host', $host->name)
        ->set('hostUserId', (string) $host->id)
        ->set('purpose', 'Meeting')
        ->call('submit');

    $pre = PreRegistration::where('email', 'john@example.com')->first();

    expect($pre)->not->toBeNull()
        ->and($pre->status)->toBe('pending')
        ->and($pre->host_user_id)->toBe($host->id);

    Notification::assertSentTo($host, VisitorPreRegistered::class);
});

test('pre-registration requires a name', function () {
    Livewire::test('pages::pre-register')
        ->set('name', '')
        ->call('submit')
        ->assertHasErrors(['name' => 'required']);

    expect(PreRegistration::count())->toBe(0);
});

test('pre-registration without host sends no notification', function () {
    Livewire::test('pages::pre-register')
        ->set('name', 'Jane Doe')
        ->set('purpose', 'Delivery')
        ->call('submit');

    expect(PreRegistration::count())->toBe(1);
    Notification::assertNothingSent();
});

// ── Kiosk matching ──

test('kiosk search returns pending pre-registration matches', function () {
    PreRegistration::factory()->pending()->create(['name' => 'Mary Jane Doe', 'email' => 'mary@example.com']);
    PreRegistration::factory()->used()->create(['name' => 'Mary Old', 'email' => 'mary.old@example.com']);

    Livewire::test('pages::welcome')
        ->set('search', 'mary')
        ->assertCount('preRegistrationMatches', 1);
});

test('selecting a pre-registration prefills visitor details', function () {
    $host = User::factory()->create();
    $pre = PreRegistration::factory()->pending()->create([
        'name' => 'Alice Smith',
        'email' => 'alice@example.com',
        'company' => 'Widget Co',
        'host' => $host->name,
        'host_user_id' => $host->id,
        'purpose' => 'Interview',
    ]);

    Livewire::test('pages::welcome')
        ->call('selectPreRegistration', $pre->id)
        ->assertSet('selectedPreRegistrationId', $pre->id)
        ->assertSet('name', 'Alice Smith')
        ->assertSet('email', 'alice@example.com')
        ->assertSet('company', 'Widget Co')
        ->assertSet('host', $host->name)
        ->assertSet('hostUserId', (string) $host->id)
        ->assertSet('purpose', 'Interview');
});

test('kiosk check-in with pre-registration creates visitor and marks it used', function () {
    $host = User::factory()->create();
    $pre = PreRegistration::factory()->pending()->create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'company' => 'Acme Inc',
        'host' => $host->name,
        'host_user_id' => $host->id,
        'purpose' => 'Meeting',
    ]);

    Livewire::test('pages::welcome')
        ->call('selectPreRegistration', $pre->id)
        ->call('checkIn');

    $visitor = Visitor::where('email', 'john@example.com')->first();

    expect($visitor)->not->toBeNull();
    expect(VisitorLog::where('visitor_id', $visitor->id)->where('purpose', 'Meeting')->exists())->toBeTrue();
    expect($pre->fresh()->status)->toBe('used');
});

test('kiosk check-in with pre-registration still notifies the host of check-in', function () {
    $host = User::factory()->create();
    $pre = PreRegistration::factory()->pending()->create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'host' => $host->name,
        'host_user_id' => $host->id,
        'purpose' => 'Meeting',
    ]);

    Livewire::test('pages::welcome')
        ->call('selectPreRegistration', $pre->id)
        ->call('checkIn');

    Notification::assertSentTo($host, VisitorCheckedIn::class);
});

// ── Admin pre-registrations page ──

test('guests are redirected from the admin pre-registrations page', function () {
    $this->get(route('pre-registrations'))->assertRedirect(route('login'));
});

test('authenticated users can view pre-registrations', function () {
    $user = User::factory()->create();
    $pre = PreRegistration::factory()->pending()->create();

    $this->actingAs($user)
        ->get(route('pre-registrations'))
        ->assertOk()
        ->assertSee($pre->name);
});

test('admin can create a pre-registration and host is notified', function () {
    $user = User::factory()->create();
    $host = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::pre-registrations')
        ->set('createName', 'New Visitor')
        ->set('createEmail', 'new@example.com')
        ->set('createHost', $host->name)
        ->set('createHostUserId', (string) $host->id)
        ->set('createPurpose', 'Site visit')
        ->call('createPreRegistration');

    $pre = PreRegistration::where('email', 'new@example.com')->first();

    expect($pre)->not->toBeNull()
        ->and($pre->status)->toBe('pending');

    Notification::assertSentTo($host, VisitorPreRegistered::class);
});

test('admin can edit a pre-registration', function () {
    $user = User::factory()->create();
    $pre = PreRegistration::factory()->pending()->create(['name' => 'Before']);

    $this->actingAs($user);

    Livewire::test('pages::pre-registrations')
        ->call('openEditModal', $pre->id)
        ->set('editName', 'After')
        ->set('editCompany', 'New Co')
        ->call('updatePreRegistration');

    expect($pre->fresh()->name)->toBe('After')
        ->and($pre->fresh()->company)->toBe('New Co');
});

test('admin can cancel a pre-registration', function () {
    $user = User::factory()->create();
    $pre = PreRegistration::factory()->pending()->create();

    $this->actingAs($user);

    Livewire::test('pages::pre-registrations')
        ->call('confirmCancel', $pre->id)
        ->call('cancelPreRegistration');

    expect($pre->fresh()->status)->toBe('cancelled');
});

test('admin can delete a pre-registration', function () {
    $user = User::factory()->create();
    $pre = PreRegistration::factory()->pending()->create();

    $this->actingAs($user);

    Livewire::test('pages::pre-registrations')
        ->call('confirmDelete', $pre->id)
        ->call('deletePreRegistration');

    expect(PreRegistration::find($pre->id))->toBeNull();
});

test('admin can filter pre-registrations by status', function () {
    $user = User::factory()->create();
    PreRegistration::factory()->pending()->create(['name' => 'Pending Visitor']);
    PreRegistration::factory()->used()->create(['name' => 'Used Visitor']);

    $this->actingAs($user);

    Livewire::test('pages::pre-registrations')
        ->call('setStatusFilter', 'used')
        ->assertSee('Used Visitor')
        ->assertDontSee('Pending Visitor');
});

test('admin can search pre-registrations', function () {
    $user = User::factory()->create();
    PreRegistration::factory()->pending()->create(['name' => 'Alpha Person', 'company' => 'Acme']);
    PreRegistration::factory()->pending()->create(['name' => 'Beta Person', 'company' => 'Globex']);

    $this->actingAs($user);

    Livewire::test('pages::pre-registrations')
        ->set('search', 'globex')
        ->assertSee('Beta Person')
        ->assertDontSee('Alpha Person');
});
