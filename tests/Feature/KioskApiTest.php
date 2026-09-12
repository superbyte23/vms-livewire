<?php

use App\Models\User;
use App\Models\Visit;
use App\Models\Visitor;
use App\Notifications\VisitorCheckedIn;
use App\Notifications\VisitorCheckedOut;
use App\Notifications\VisitorFlagged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const KIOSK_TOKEN = 'test-kiosk-token';

beforeEach(function () {
    config(['kiosk.token' => KIOSK_TOKEN]);
    Notification::fake();
});

function kiosk(string $method, string $uri, array $data = []): TestResponse
{
    return match (strtoupper($method)) {
        'GET' => test()->withHeader('X-Kiosk-Token', KIOSK_TOKEN)->getJson($uri),
        'POST' => test()->withHeader('X-Kiosk-Token', KIOSK_TOKEN)->postJson($uri, $data),
        default => throw new InvalidArgumentException('Unsupported method '.$method),
    };
}

test('kiosk endpoints require a valid token', function () {
    $this->getJson('/api/kiosk/health')->assertUnauthorized();
    $this->withHeader('X-Kiosk-Token', 'wrong-token')->getJson('/api/kiosk/health')->assertUnauthorized();
});

test('health endpoint responds when authenticated', function () {
    kiosk('get', '/api/kiosk/health')
        ->assertOk()
        ->assertJson(['status' => 'ok']);
});

test('search visitors returns matching visitors only', function () {
    Visitor::factory()->create(['firstname' => 'Alice', 'lastname' => 'Johnson']);
    Visitor::factory()->create(['firstname' => 'Bob', 'lastname' => 'Smith']);

    $response = kiosk('get', '/api/kiosk/visitors/search?q=alice');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Alice Johnson');
});

test('store visitor creates a visitor with a qr token', function () {
    $response = kiosk('post', '/api/kiosk/visitors', [
        'firstname' => 'Jane',
        'lastname' => 'Roe',
        'email' => 'jane@example.com',
        'phone' => '555-0100',
        'company' => 'Acme',
        'government_id' => 'DL-123',
    ]);

    $response->assertCreated()
        ->assertJsonPath('visitor.name', 'Jane Roe')
        ->assertJsonPath('visitor.firstname', 'Jane')
        ->assertJsonPath('visitor.lastname', 'Roe')
        ->assertJsonPath('visitor.email', 'jane@example.com')
        ->assertJsonPath('visitor.government_id', 'DL-123');

    $visitor = Visitor::where('name', 'Jane Roe')->first();

    expect($visitor)->not->toBeNull()
        ->and($visitor->qr_code_token)->not->toBeNull()
        ->and(strlen($visitor->qr_code_token))->toBe(32);
});

test('show visitor by token returns active visit when on-site', function () {
    $visitor = Visitor::factory()->create(['qr_code_token' => 'tok-123']);
    Visit::factory()->create(['visitor_id' => $visitor->id, 'status' => 'checked_in']);

    kiosk('get', '/api/kiosk/visitors/token/tok-123')
        ->assertOk()
        ->assertJsonPath('visitor.qr_code_token', 'tok-123')
        ->assertJsonPath('visit.status', 'checked_in');
});

test('show visitor by token returns null visit when not on-site', function () {
    Visitor::factory()->create(['qr_code_token' => 'tok-456']);

    kiosk('get', '/api/kiosk/visitors/token/tok-456')
        ->assertOk()
        ->assertJsonPath('visitor.qr_code_token', 'tok-456')
        ->assertJsonPath('visit', null);
});

test('show visitor by token returns 404 for unknown token', function () {
    kiosk('get', '/api/kiosk/visitors/token/nope')->assertNotFound();
});

test('pending bookings are searchable', function () {
    $carlos = Visitor::factory()->create(['firstname' => 'Carlos', 'lastname' => 'Garcia']);
    $dina = Visitor::factory()->create(['firstname' => 'Dina', 'lastname' => 'Park']);
    Visit::factory()->scheduled()->create(['visitor_id' => $carlos->id]);
    Visit::factory()->create(['visitor_id' => $dina->id, 'status' => 'checked_in']);

    $response = kiosk('get', '/api/kiosk/bookings/pending?q=carlos');

    $response->assertOk()->assertJsonCount(1, 'data');
});

test('booking by token returns the scheduled record', function () {
    $visitor = Visitor::factory()->create();
    Visit::factory()->scheduled()->create([
        'visitor_id' => $visitor->id,
        'qr_code_token' => 'book-tok-1',
    ]);

    kiosk('get', '/api/kiosk/bookings/token/book-tok-1')
        ->assertOk()
        ->assertJsonPath('booking.visitor_id', $visitor->id)
        ->assertJsonPath('booking.status', 'scheduled');
});

test('booking by token rejects used or unknown records', function () {
    $visitor = Visitor::factory()->create();
    Visit::factory()->create([
        'visitor_id' => $visitor->id,
        'qr_code_token' => 'used-tok',
        'status' => 'checked_in',
    ]);

    kiosk('get', '/api/kiosk/bookings/token/used-tok')->assertNotFound();
    kiosk('get', '/api/kiosk/bookings/token/unknown')->assertNotFound();
});

test('on-site returns only checked-in visitors', function () {
    $in = Visitor::factory()->create();
    $out = Visitor::factory()->create();
    Visit::factory()->create(['visitor_id' => $in->id, 'status' => 'checked_in']);
    Visit::factory()->checkedOut()->create(['visitor_id' => $out->id]);

    $response = kiosk('get', '/api/kiosk/on-site');

    $response->assertOk()->assertJsonCount(1, 'data');
});

test('check-in registers a new visitor and returns badge details', function () {
    $host = User::factory()->create();

    $response = kiosk('post', '/api/kiosk/check-in', [
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'phone' => '555-0101',
        'company' => 'Acme',
        'host' => $host->name,
        'host_user_id' => $host->id,
        'purpose' => 'Meeting',
    ]);

    $response->assertCreated()
        ->assertJsonPath('visitor.name', 'John Doe')
        ->assertJsonPath('visit.status', 'checked_in')
        ->assertJsonPath('is_flagged', false)
        ->assertJsonPath('badge_number', fn ($badge) => str_starts_with($badge, 'V-'));

    $visit = Visit::where('visitor_id', $response->json('visitor.id'))->first();

    expect($visit)->not->toBeNull()
        ->and($visit->host)->toBe($host->name)
        ->and($visit->badge_number)->toBe($response->json('badge_number'));

    Notification::assertSentTo($host, VisitorCheckedIn::class);
});

test('check-in with existing visitor reuses their qr token', function () {
    $visitor = Visitor::factory()->create(['qr_code_token' => 'existing-tok']);

    $response = kiosk('post', '/api/kiosk/check-in', [
        'visitor_id' => $visitor->id,
        'purpose' => 'Delivery',
    ]);

    $response->assertCreated()->assertJsonPath('visitor.qr_code_token', 'existing-tok');

    $visitor->refresh();

    expect($visitor->qr_code_token)->toBe('existing-tok');
});

test('check-in with a booking flips the booked visit to checked in', function () {
    $visitor = Visitor::factory()->create();
    $booking = Visit::factory()->scheduled()->create([
        'visitor_id' => $visitor->id,
        'purpose' => 'Booked meeting',
    ]);

    $countBefore = Visit::count();

    $response = kiosk('post', '/api/kiosk/check-in', [
        'booking_id' => $booking->id,
        'visitor_id' => $visitor->id,
    ]);

    $response->assertCreated()->assertJsonPath('visit.status', 'checked_in');

    expect(Visit::count())->toBe($countBefore)
        ->and($booking->fresh()->status)->toBe('checked_in')
        ->and($booking->fresh()->checked_in_at)->not->toBeNull()
        ->and($booking->fresh()->badge_number)->not->toBeNull();
});

test('check-in flags a visitor matching the watchlist', function () {
    Visitor::factory()->flagged()->create(['firstname' => 'Susan', 'lastname' => 'Black']);
    $admin = User::factory()->create();

    $response = kiosk('post', '/api/kiosk/check-in', [
        'name' => 'Susan Black',
        'purpose' => 'Meeting',
    ]);

    $response->assertCreated()
        ->assertJsonPath('is_flagged', true)
        ->assertJsonCount(1, 'warnings');

    $visitor = Visitor::find($response->json('visitor.id'));

    expect($visitor->is_flagged)->toBeTrue();

    Notification::assertSentTo($admin, VisitorFlagged::class);
});

test('check-in requires a name when not referencing an existing visitor', function () {
    kiosk('post', '/api/kiosk/check-in', [
        'purpose' => 'Meeting',
    ])->assertUnprocessable()->assertJsonValidationErrors('name');
});

test('check-out checks out a visitor by qr token', function () {
    $visitor = Visitor::factory()->create(['qr_code_token' => 'out-tok']);
    $host = User::factory()->create();
    $visit = Visit::factory()->create([
        'visitor_id' => $visitor->id,
        'host_user_id' => $host->id,
        'status' => 'checked_in',
    ]);

    $response = kiosk('post', '/api/kiosk/check-out', ['token' => 'out-tok']);

    $response->assertOk()->assertJsonPath('visit.status', 'checked_out');

    expect($visit->refresh()->checked_out_at)->not->toBeNull();

    Notification::assertSentTo($host, VisitorCheckedOut::class);
});

test('check-out accepts an optional checkout photo', function () {
    $visitor = Visitor::factory()->create(['qr_code_token' => 'photo-tok']);
    $visit = Visit::factory()->create([
        'visitor_id' => $visitor->id,
        'status' => 'checked_in',
    ]);

    kiosk('post', '/api/kiosk/check-out', [
        'token' => 'photo-tok',
        'checkout_photo' => 'data:image/jpeg;base64,abc123',
    ])->assertOk();

    expect($visit->refresh()->checkout_photo)->toBe('data:image/jpeg;base64,abc123');
});

test('check-out rejects a token for a visitor who is not on-site', function () {
    Visitor::factory()->create(['qr_code_token' => 'gone-tok']);

    kiosk('post', '/api/kiosk/check-out', ['token' => 'gone-tok'])->assertStatus(422);
});

test('check-out stores a badge on the qr token generated at check-in', function () {
    $response = kiosk('post', '/api/kiosk/check-in', [
        'name' => 'Sam Quick',
        'purpose' => 'Visit',
    ]);

    $qrUrl = $response->json('qr_url');
    $qrContent = $response->json('qr_content');

    expect(Str::contains($qrUrl, '/qr/'))->toBeTrue()
        ->and(Str::contains($qrContent, 'checkout='))->toBeTrue();

    $token = $response->json('visitor.qr_code_token');

    kiosk('post', '/api/kiosk/check-out', ['token' => $token])->assertOk();
});
