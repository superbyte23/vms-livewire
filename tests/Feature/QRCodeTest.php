<?php

use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('qr token is generated on check-in', function () {
    Livewire::test('pages::welcome')
        ->set('name', 'John Doe')
        ->set('purpose', 'Meeting')
        ->call('checkIn');

    $this->assertDatabaseHas('visitors', [
        'name' => 'John Doe',
    ]);

    $visitor = Visitor::where('name', 'John Doe')->first();
    expect($visitor->qr_code_token)->not->toBeNull();
    expect(strlen($visitor->qr_code_token))->toBe(32);
});

test('qr code route returns png image for valid token', function () {
    $visitor = Visitor::factory()->create([
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
    $visitor = Visitor::factory()->create([
        'qr_code_token' => 'checkout-token-456',
        'status' => 'checked_in',
    ]);

    $this->get(route('home', ['checkout' => 'checkout-token-456']));

    $this->assertDatabaseHas('visitors', [
        'id' => $visitor->id,
        'status' => 'checked_in',
    ]);
});

test('confirming qr checkout checks out the visitor', function () {
    $visitor = Visitor::factory()->create([
        'qr_code_token' => 'confirm-token',
        'status' => 'checked_in',
    ]);

    Livewire::test('pages::welcome')
        ->call('checkOutByToken', 'confirm-token')
        ->assertSet('showQrCheckoutModal', true)
        ->call('confirmQrCheckOut');

    $this->assertDatabaseHas('visitors', [
        'id' => $visitor->id,
        'status' => 'checked_out',
    ]);

    $visitor->refresh();
    expect($visitor->checked_out_at)->not->toBeNull();
});

test('cancelling qr checkout does not check out the visitor', function () {
    $visitor = Visitor::factory()->create([
        'qr_code_token' => 'cancel-token',
        'status' => 'checked_in',
    ]);

    Livewire::test('pages::welcome')
        ->call('checkOutByToken', 'cancel-token')
        ->assertSet('showQrCheckoutModal', true)
        ->call('cancelQrCheckOut');

    $this->assertDatabaseHas('visitors', [
        'id' => $visitor->id,
        'status' => 'checked_in',
    ]);
});

test('confirming invalid qr token does not check anyone out', function () {
    Visitor::factory()->create([
        'qr_code_token' => 'real-token',
        'status' => 'checked_in',
    ]);

    Livewire::test('pages::welcome')
        ->call('checkOutByToken', 'fake-token')
        ->call('confirmQrCheckOut');

    $this->assertDatabaseHas('visitors', [
        'qr_code_token' => 'real-token',
        'status' => 'checked_in',
    ]);
});
