<?php

use App\Models\User;
use App\Models\Visit;
use App\Models\Visitor;
use Livewire\Livewire;

uses()->group('feature')->group('reports');

test('guests are redirected to the login page', function () {
    $response = $this->get(route('reports'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the reports page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('reports'));
    $response->assertOk();

    Livewire::test('pages::reports')
        ->assertSee('Reports')
        ->assertSee('Check-ins per day')
        ->assertSee('Status mix')
        ->assertSee('Peak check-in hours');
});

test('kpis reflect visits in the selected range', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $visitor = Visitor::factory()->create(['firstname' => 'Report', 'lastname' => 'Rita']);
    Visit::factory()->checkedOut()->create([
        'visitor_id' => $visitor->id,
        'host' => 'Hosty H',
        'checked_in_at' => now()->subDay()->setTime(9, 0),
        'checked_out_at' => now()->subDay()->setTime(11, 30),
    ]);

    Livewire::test('pages::reports')
        ->assertSee('Hosty H')
        ->assertSee('2h 30m');
});

test('preset change narrows the daily buckets', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $visitor = Visitor::factory()->create();
    Visit::factory()->create([
        'visitor_id' => $visitor->id,
        'status' => 'checked_in',
        'checked_in_at' => now()->subDays(20)->setTime(10, 0),
    ]);

    $oldLabel = now()->subDays(20)->format('M j');

    Livewire::test('pages::reports')
        ->assertSee($oldLabel)
        ->call('selectPreset', '7')
        ->assertDontSee($oldLabel);
});

test('export csv downloads bucket rows', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = Livewire::test('pages::reports')->call('exportCsv');

    $response->assertFileDownloaded();
});
