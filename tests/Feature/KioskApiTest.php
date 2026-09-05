<?php

use App\Models\PreRegistration;
use App\Models\User;
use App\Models\Visitor;
use App\Models\VisitorLog;
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
    Visitor::factory()->create(['name' => 'Alice Johnson']);
    Visitor::factory()->create(['name' => 'Bob Smith']);

    $response = kiosk('get', '/api/kiosk/visitors/search?q=alice');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Alice Johnson');
});

test('store visitor creates a visitor with a qr token', function () {
    $response = kiosk('post', '/api/kiosk/visitors', [
        'name' => 'Jane Roe',
        'email' => 'jane@example.com',
        'phone' => '555-0100',
        'company' => 'Acme',
        'valid_id_number' => 'DL-123',
    ]);

    $response->assertCreated()
        ->assertJsonPath('visitor.name', 'Jane Roe')
        ->assertJsonPath('visitor.email', 'jane@example.com');

    $visitor = Visitor::where('name', 'Jane Roe')->first();

    expect($visitor)->not->toBeNull()
        ->and($visitor->qr_code_token)->not->toBeNull()
        ->and(strlen($visitor->qr_code_token))->toBe(32);
});

test('show visitor by token returns active log when on-site', function () {
    $visitor = Visitor::factory()->create(['qr_code_token' => 'tok-123']);
    VisitorLog::factory()->create(['visitor_id' => $visitor->id, 'status' => 'checked_in']);

    kiosk('get', '/api/kiosk/visitors/token/tok-123')
        ->assertOk()
        ->assertJsonPath('visitor.qr_code_token', 'tok-123')
        ->assertJsonPath('log.status', 'checked_in');
});

test('show visitor by token returns null log when not on-site', function () {
    Visitor::factory()->create(['qr_code_token' => 'tok-456']);

    kiosk('get', '/api/kiosk/visitors/token/tok-456')
        ->assertOk()
        ->assertJsonPath('visitor.qr_code_token', 'tok-456')
        ->assertJsonPath('log', null);
});

test('show visitor by token returns 404 for unknown token', function () {
    kiosk('get', '/api/kiosk/visitors/token/nope')->assertNotFound();
});

test('pending pre-registrations are searchable', function () {
    PreRegistration::factory()->pending()->create(['name' => 'Carlos Garcia']);
    PreRegistration::factory()->used()->create(['name' => 'Dina Park']);

    $response = kiosk('get', '/api/kiosk/pre-registrations/pending?q=carlos');

    $response->assertOk()->assertJsonCount(1, 'data');
});

test('pre-registration by token returns the pending record', function () {
    PreRegistration::factory()->pending()->create(['qr_code_token' => 'pre-tok-1']);

    kiosk('get', '/api/kiosk/pre-registrations/token/pre-tok-1')
        ->assertOk()
        ->assertJsonPath('pre_registration.qr_code_token', null)
        ->assertJsonPath('pre_registration.name', fn ($name) => is_string($name) && $name !== '');
});

test('pre-registration by token rejects used or unknown records', function () {
    PreRegistration::factory()->used()->create(['qr_code_token' => 'used-tok']);

    kiosk('get', '/api/kiosk/pre-registrations/token/used-tok')->assertNotFound();
    kiosk('get', '/api/kiosk/pre-registrations/token/unknown')->assertNotFound();
});

test('on-site returns only checked-in visitors', function () {
    $in = Visitor::factory()->create();
    $out = Visitor::factory()->create();
    VisitorLog::factory()->create(['visitor_id' => $in->id, 'status' => 'checked_in']);
    VisitorLog::factory()->checkedOut()->create(['visitor_id' => $out->id]);

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
        ->assertJsonPath('log.status', 'checked_in')
        ->assertJsonPath('is_flagged', false)
        ->assertJsonPath('badge_number', fn ($badge) => str_starts_with($badge, 'V-'));

    $log = VisitorLog::where('visitor_id', $response->json('visitor.id'))->first();

    expect($log)->not->toBeNull()
        ->and($log->host)->toBe($host->name)
        ->and($log->badge_number)->toBe($response->json('badge_number'));

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

test('check-in consumes a pending pre-registration', function () {
    $pre = PreRegistration::factory()->pending()->create(['qr_code_token' => 'pre-tok']);

    $response = kiosk('post', '/api/kiosk/check-in', [
        'pre_registration_id' => $pre->id,
        'name' => $pre->name,
        'email' => $pre->email,
        'phone' => $pre->phone,
        'company' => $pre->company,
        'purpose' => $pre->purpose,
    ]);

    $response->assertCreated();

    expect(PreRegistration::find($pre->id)->status)->toBe('used');

    $visitor = Visitor::where('name', $pre->name)->first();

    expect($visitor->qr_code_token)->toBe('pre-tok');
});

test('check-in flags a visitor matching the watchlist', function () {
    Visitor::factory()->flagged()->create(['name' => 'Susan Black']);
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
    $log = VisitorLog::factory()->create([
        'visitor_id' => $visitor->id,
        'host_user_id' => $host->id,
        'status' => 'checked_in',
    ]);

    $response = kiosk('post', '/api/kiosk/check-out', ['token' => 'out-tok']);

    $response->assertOk()->assertJsonPath('log.status', 'checked_out');

    expect($log->refresh()->checked_out_at)->not->toBeNull();

    Notification::assertSentTo($host, VisitorCheckedOut::class);
});

test('check-out accepts an optional checkout photo', function () {
    $visitor = Visitor::factory()->create(['qr_code_token' => 'photo-tok']);
    $log = VisitorLog::factory()->create([
        'visitor_id' => $visitor->id,
        'status' => 'checked_in',
    ]);

    kiosk('post', '/api/kiosk/check-out', [
        'token' => 'photo-tok',
        'checkout_photo' => 'data:image/jpeg;base64,abc123',
    ])->assertOk();

    expect($log->refresh()->checkout_photo)->toBe('data:image/jpeg;base64,abc123');
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
