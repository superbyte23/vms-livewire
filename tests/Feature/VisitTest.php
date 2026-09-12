<?php

use App\Models\Visit;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class)->group('feature')->group('settings');

test('change status toggles visitor status', function () {
    $visitor = Visitor::factory()->create();
    $visit = Visit::factory()->create([
        'visitor_id' => $visitor->id,
        'status' => 'checked_in',
        'checked_in_at' => now(),
    ]);

    Livewire::test('pages::visits')
        ->call('changeStatus', $visit->id);

    $visit->refresh();

    expect($visit->status)->toBe('checked_out')
        ->and($visit->checked_out_at)->not->toBeNull();
});

test('change status toggles back to checked in', function () {
    $visitor = Visitor::factory()->create();
    $visit = Visit::factory()->create([
        'visitor_id' => $visitor->id,
        'status' => 'checked_out',
        'checked_in_at' => now()->subHours(2),
        'checked_out_at' => now(),
    ]);

    Livewire::test('pages::visits')
        ->call('changeStatus', $visit->id);

    $visit->refresh();

    expect($visit->status)->toBe('checked_in')
        ->and($visit->checked_out_at)->toBeNull();
});

test('status badge updates after change status', function () {
    $visitor = Visitor::factory()->create();
    $visit = Visit::factory()->create([
        'visitor_id' => $visitor->id,
        'status' => 'checked_in',
        'checked_in_at' => now(),
    ]);

    Livewire::test('pages::visits')
        ->assertSee('On-site')
        ->call('changeStatus', $visit->id)
        ->assertSee('Checked Out');

    $visit->refresh();

    expect($visit->status)->toBe('checked_out');
});

test('delete visit soft-deletes the record', function () {
    $visitor = Visitor::factory()->create();
    $visit = Visit::factory()->create([
        'visitor_id' => $visitor->id,
    ]);

    Livewire::test('pages::visits')
        ->call('confirmDelete', $visit->id)
        ->assertSet('showDeleteModal', true)
        ->call('deleteVisit');

    expect(Visit::find($visit->id))->toBeNull();

    $this->assertSoftDeleted($visit);
});

test('view visit opens the modal', function () {
    $visitor = Visitor::factory()->create();
    $visit = Visit::factory()->create([
        'visitor_id' => $visitor->id,
    ]);

    Livewire::test('pages::visits')
        ->call('viewVisit', $visit->id)
        ->assertSet('showViewModal', true)
        ->assertSee($visitor->name);
});
