<?php

use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class)->group('feature')->group('watchlist');

beforeEach(function () {
    $user = User::factory()->create();
    $this->actingAs($user);
});

test('watchlist page shows flagged visitors', function () {
    Visitor::factory()->count(3)->create(['is_flagged' => true]);
    Visitor::factory()->count(5)->create(['is_flagged' => false]);

    Livewire::test('pages::watchlist')
        ->assertCount('flaggedVisitors', 3);
});

test('watchlist page shows empty state when no flagged visitors', function () {
    Livewire::test('pages::watchlist')
        ->assertSee('No visitors on the watchlist.');
});

test('search filters flagged visitors', function () {
    Visitor::factory()->create(['name' => 'John Doe', 'is_flagged' => true]);
    Visitor::factory()->create(['name' => 'Jane Smith', 'is_flagged' => true]);

    Livewire::test('pages::watchlist')
        ->set('search', 'John')
        ->assertCount('flaggedVisitors', 1);
});

test('view visitor modal opens with visitor data', function () {
    $visitor = Visitor::factory()->create([
        'is_flagged' => true,
        'notes' => 'Suspicious activity',
    ]);

    Livewire::test('pages::watchlist')
        ->call('viewVisitor', $visitor->id)
        ->assertSet('showViewModal', true)
        ->assertSet('viewingVisitorId', $visitor->id);
});

test('edit notes modal opens and saves', function () {
    $visitor = Visitor::factory()->create(['is_flagged' => true]);

    Livewire::test('pages::watchlist')
        ->call('openEditNotes', $visitor->id)
        ->assertSet('showEditNotesModal', true)
        ->set('editNotes', 'Updated watchlist notes')
        ->call('saveNotes');

    $visitor->refresh();
    expect($visitor->notes)->toBe('Updated watchlist notes');
});

test('unflag removes visitor from watchlist', function () {
    $visitor = Visitor::factory()->create(['is_flagged' => true]);

    Livewire::test('pages::watchlist')
        ->call('confirmUnflag', $visitor->id)
        ->assertSet('showUnflagModal', true)
        ->call('unflagVisitor');

    $visitor->refresh();
    expect($visitor->is_flagged)->toBeFalse();
});

test('unflagged candidates shows non-flagged visitors', function () {
    Visitor::factory()->count(2)->create(['is_flagged' => true]);
    Visitor::factory()->count(3)->create(['is_flagged' => false]);

    Livewire::test('pages::watchlist')
        ->call('openAddModal')
        ->assertCount('unflaggedCandidates', 3);
});

test('flag visitor adds them to watchlist', function () {
    $visitor = Visitor::factory()->create(['is_flagged' => false]);

    Livewire::test('pages::watchlist')
        ->call('flagVisitor', $visitor->id);

    $visitor->refresh();
    expect($visitor->is_flagged)->toBeTrue();
});
