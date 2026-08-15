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

test('visitor can pre-register without a host', function () {
    Livewire::test('pages::pre-register')
        ->set('name', 'John Doe')
        ->set('email', 'john@example.com')
        ->set('phone', '555-0123')
        ->set('company', 'Acme Inc')
        ->set('purpose', 'Meeting')
        ->call('submit');

    $pre = PreRegistration::where('email', 'john@example.com')->first();

    expect($pre)->not->toBeNull()
        ->and($pre->status)->toBe('pending')
        ->and($pre->host)->toBeNull();

    Notification::assertNothingSent();
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
        ->set('phone', '555-0123')
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

// ── Pre-registration QR code ──

test('pre-registration generates a permanent qr token', function () {
    Livewire::test('pages::pre-register')
        ->set('name', 'QR Visitor')
        ->set('phone', '555-0123')
        ->set('purpose', 'Tour')
        ->call('submit');

    $pre = PreRegistration::where('name', 'QR Visitor')->first();

    expect($pre->qr_code_token)->not->toBeNull()
        ->and(strlen($pre->qr_code_token))->toBe(32);
});

test('kiosk with pre-registration token in url prefills the visitor', function () {
    $pre = PreRegistration::factory()->pending()->create([
        'name' => 'QR Match Person',
        'email' => 'qr@example.com',
        'qr_code_token' => 'pre-match-token',
    ]);

    $this->get(route('home', ['pre' => 'pre-match-token']))
        ->assertOk()
        ->assertSee('We found your pre-registration')
        ->assertSee('QR Match Person');

    $this->assertDatabaseHas('pre_registrations', [
        'id' => $pre->id,
        'status' => 'pending',
    ]);
});

test('kiosk with used pre-registration token does not prefill', function () {
    PreRegistration::factory()->used()->create([
        'name' => 'Old Person',
        'qr_code_token' => 'used-pre-token',
    ]);

    $this->get(route('home', ['pre' => 'used-pre-token']))
        ->assertOk()
        ->assertDontSee('Old Person');
});

test('scanning a valid pre-registration token matches the visitor', function () {
    $pre = PreRegistration::factory()->pending()->create([
        'name' => 'Scanner Person',
        'email' => 'scan@example.com',
        'qr_code_token' => 'scan-pre-token',
    ]);

    Livewire::test('pages::welcome')
        ->call('scanPreRegistrationByToken', 'scan-pre-token')
        ->assertSet('selectedPreRegistrationId', $pre->id)
        ->assertSet('name', 'Scanner Person')
        ->assertSet('email', 'scan@example.com')
        ->assertSet('activeTab', 'checkin');
});

test('scanning an invalid pre-registration token selects nothing', function () {
    Livewire::test('pages::welcome')
        ->call('scanPreRegistrationByToken', 'fake-pre-token')
        ->assertSet('selectedPreRegistrationId', null);
});

test('kiosk check-in transfers the pre-registration qr token to the visitor', function () {
    $pre = PreRegistration::factory()->pending()->create([
        'name' => 'Transfer Person',
        'email' => 'transfer@example.com',
        'qr_code_token' => 'transfer-token',
    ]);

    Livewire::test('pages::welcome')
        ->call('selectPreRegistration', $pre->id)
        ->call('checkIn');

    $visitor = Visitor::where('email', 'transfer@example.com')->first();

    expect($visitor)->not->toBeNull()
        ->and($visitor->qr_code_token)->toBe('transfer-token')
        ->and($pre->fresh()->status)->toBe('used');
});

// ── Valid ID photo ──

test('pre-registration stores a valid id photo', function () {
    $idPhoto = 'data:image/jpeg;base64,'.base64_encode('fake-image-bytes');

    Livewire::test('pages::pre-register')
        ->set('name', 'ID Photo Visitor')
        ->set('phone', '555-0123')
        ->set('validIdPhoto', $idPhoto)
        ->call('submit');

    $pre = PreRegistration::where('name', 'ID Photo Visitor')->first();

    expect($pre->valid_id_photo)->toBe($idPhoto);
});

test('pre-registration without valid id photo stores null', function () {
    Livewire::test('pages::pre-register')
        ->set('name', 'No Photo Visitor')
        ->set('phone', '555-0123')
        ->call('submit');

    $pre = PreRegistration::where('name', 'No Photo Visitor')->first();

    expect($pre->valid_id_photo)->toBeNull();
});

test('submitting a pre-registration redirects to the completion page', function () {
    Livewire::test('pages::pre-register')
        ->set('name', 'Redirect Person')
        ->set('phone', '555-0123')
        ->set('purpose', 'Tour')
        ->call('submit')
        ->assertRedirect();

    $pre = PreRegistration::where('name', 'Redirect Person')->first();

    expect($pre)->not->toBeNull();
});

test('completion page shows the pass for a pending pre-registration', function () {
    $pre = PreRegistration::factory()->pending()->create([
        'name' => 'Complete Page Person',
        'company' => 'Acme Inc',
        'purpose' => 'Meeting',
        'qr_code_token' => 'complete-page-token',
    ]);

    $this->get(route('pre-register.complete', $pre))
        ->assertOk()
        ->assertSee('Complete Page Person')
        ->assertSee('Acme Inc')
        ->assertSee('Download Card');
});

test('completion page stays valid after a refresh', function () {
    $pre = PreRegistration::factory()->pending()->create([
        'name' => 'Refresh Safe Person',
        'qr_code_token' => 'refresh-safe-token',
    ]);

    $this->get(route('pre-register.complete', $pre))->assertSee('Refresh Safe Person');
    $this->get(route('pre-register.complete', $pre))->assertSee('Refresh Safe Person');
});

test('completion page shows a notice for a used pre-registration', function () {
    $pre = PreRegistration::factory()->used()->create([
        'name' => 'Used Pass Person',
    ]);

    $this->get(route('pre-register.complete', $pre))
        ->assertOk()
        ->assertSee('no longer valid')
        ->assertDontSee('Download Card');
});

test('kiosk check-in transfers the pre-registration valid id photo to the visitor', function () {
    $idPhoto = 'data:image/jpeg;base64,'.base64_encode('fake-image-bytes');
    $pre = PreRegistration::factory()->pending()->create([
        'name' => 'Photo Transfer',
        'email' => 'phototransfer@example.com',
        'valid_id_photo' => $idPhoto,
    ]);

    Livewire::test('pages::welcome')
        ->call('selectPreRegistration', $pre->id)
        ->call('checkIn');

    $visitor = Visitor::where('email', 'phototransfer@example.com')->first();

    expect($visitor)->not->toBeNull()
        ->and($visitor->photo)->toBe($idPhoto);
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
