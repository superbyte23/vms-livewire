<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class)->group('feature')->group('dashboard');

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();

    Livewire::test('pages::dashboard')
        ->assertSee('Visit Calendar');
});

test('dashboard embeds the kiosk behind the launcher', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::dashboard')
        ->assertSee('Kiosk check-in')
        ->assertSee('Open kiosk')
        ->assertSee('Walk-in visitors use the full kiosk here.')
        ->assertDontSeeHtml('z-[100]')
        ->call('openKiosk')
        ->assertSet('showKioskModal', true)
        ->assertSet('kioskKey', 1)
        ->assertSee('Visita Kiosk')
        ->assertSee('Scan booking QR');
});
