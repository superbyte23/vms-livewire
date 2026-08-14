<?php

use App\Models\VisitorLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('qr token is generated on check-in', function () {
    Livewire::test('pages::welcome')
        ->set('name', 'John Doe')
        ->set('purpose', 'Meeting')
        ->call('checkIn');

    $this->assertDatabaseHas('visitor_logs', ['purpose' => 'Meeting']);

    $log = VisitorLog::where('purpose', 'Meeting')->first();
    expect($log->qr_code_token)->not->toBeNull();
    expect(strlen($log->qr_code_token))->toBe(32);
});

test('qr code route returns png image for valid token', function () {
    VisitorLog::factory()->create([
        'qr_code_token' => 'test-token-123',
    ]);

    $response = $this->get(route('qr.code', 'test-token-123'));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'image/png');
});

test('qr code route returns 404 for invalid token', function () {
    $response = $this->get(route('qr.code', 'non-existent-token'));
    $response->assertNotFound();
});

test('visiting kiosk with checkout token shows confirmation', function () {
    $log = VisitorLog::factory()->create([
        'qr_code_token' => 'checkout-token-456',
        'status' => 'checked_in',
    ]);

    $this->get(route('home', ['checkout' => 'checkout-token-456']))
        ->assertSee('A QR code was scanned for '.$log->visitor->name);

    $this->assertDatabaseHas('visitor_logs', [
        'qr_code_token' => 'checkout-token-456',
        'status' => 'checked_in',
    ]);
});

test('confirming qr checkout checks out the visitor', function () {
    $log = VisitorLog::factory()->create([
        'qr_code_token' => 'confirm-token',
        'status' => 'checked_in',
        'checked_in_at' => now(),
    ]);

    Livewire::test('pages::welcome')
        ->call('checkOutByToken', 'confirm-token')
        ->assertSet('showQrCheckoutModal', true)
        ->call('confirmQrCheckOut');

    $this->assertDatabaseHas('visitor_logs', [
        'id' => $log->id,
        'status' => 'checked_out',
    ]);

    $log->refresh();
    expect($log->checked_out_at)->not->toBeNull();
});

test('cancelling qr checkout does not check out the visitor', function () {
    $log = VisitorLog::factory()->create([
        'qr_code_token' => 'cancel-token',
        'status' => 'checked_in',
        'checked_in_at' => now(),
    ]);

    Livewire::test('pages::welcome')
        ->call('checkOutByToken', 'cancel-token')
        ->assertSet('showQrCheckoutModal', true)
        ->call('cancelQrCheckOut');

    $this->assertDatabaseHas('visitor_logs', [
        'id' => $log->id,
        'status' => 'checked_in',
    ]);

    $log->refresh();
    expect($log->checked_out_at)->toBeNull();
});

test('confirming invalid qr token does not check anyone out', function () {
    $log = VisitorLog::factory()->create([
        'qr_code_token' => 'real-token',
        'status' => 'checked_in',
        'checked_in_at' => now(),
    ]);

    Livewire::test('pages::welcome')
        ->call('checkOutByToken', 'fake-token')
        ->call('confirmQrCheckOut');

    $this->assertDatabaseHas('visitor_logs', [
        'id' => $log->id,
        'status' => 'checked_in',
    ]);
});
