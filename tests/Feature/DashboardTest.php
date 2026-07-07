<?php

use App\Models\User;
use App\Models\Visitor;
use App\Models\VisitorLog;
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
});

test('dashboard shows today visitor count', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $visitor = Visitor::factory()->create();
    VisitorLog::factory()->count(3)->create([
        'visitor_id' => $visitor->id,
        'created_at' => now(),
    ]);

    Livewire::test('pages::dashboard')
        ->assertSet('todayCount', 3);
});

test('dashboard shows on-site count', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $visitor = Visitor::factory()->create();
    VisitorLog::factory()->count(2)->create([
        'visitor_id' => $visitor->id,
        'status' => 'checked_in',
    ]);
    VisitorLog::factory()->count(3)->create([
        'visitor_id' => $visitor->id,
        'status' => 'checked_out',
        'checked_out_at' => now(),
    ]);

    Livewire::test('pages::dashboard')
        ->assertSet('onSiteCount', 2);
});

test('dashboard visitors per day returns chart data', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $visitor = Visitor::factory()->create();
    VisitorLog::factory()->count(5)->create([
        'visitor_id' => $visitor->id,
        'created_at' => now(),
    ]);
    VisitorLog::factory()->count(3)->create([
        'visitor_id' => $visitor->id,
        'created_at' => now()->subDay(),
    ]);

    $component = Livewire::test('pages::dashboard');

    $data = $component->visitorsPerDay;
    expect($data)->toHaveKeys(['labels', 'data']);
    expect($data['data'])->toBeArray();
    expect(array_sum($data['data']))->toBeGreaterThanOrEqual(8);
});

test('dashboard status distribution returns chart data', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $visitor = Visitor::factory()->create();
    VisitorLog::factory()->count(2)->create([
        'visitor_id' => $visitor->id,
        'status' => 'checked_in',
    ]);
    VisitorLog::factory()->count(3)->create([
        'visitor_id' => $visitor->id,
        'status' => 'checked_out',
        'checked_out_at' => now(),
    ]);

    $component = Livewire::test('pages::dashboard');

    $data = $component->statusDistribution;
    expect($data['labels'])->toBe(['On-Site', 'Checked Out']);
    expect($data['data'])->toBe([2, 3]);
});

test('dashboard chart period filter changes data scope', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $visitor = Visitor::factory()->create();
    VisitorLog::factory()->count(10)->create([
        'visitor_id' => $visitor->id,
        'created_at' => now()->subDays(20),
    ]);

    $component = Livewire::test('pages::dashboard')
        ->set('chartPeriod', '7days');

    $data = $component->visitorsPerDay;
    expect(count($data['labels']))->toBe(7);

    $component->set('chartPeriod', '30days');

    $data = $component->visitorsPerDay;
    expect(count($data['labels']))->toBe(30);
});
