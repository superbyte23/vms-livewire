<?php

use App\Models\User;
use App\Models\Visit;
use App\Models\Visitor;
use Livewire\Livewire;

uses()->group('feature')->group('deleted-visitor');

test('dashboard and visits pages survive visitor deletion', function () {
    $user = User::factory()->create();
    $visitor = Visitor::factory()->create(['firstname' => 'Gone', 'lastname' => 'Gary']);
    $visit = Visit::factory()->create([
        'visitor_id' => $visitor->id,
        'status' => 'checked_in',
        'checked_in_at' => now(),
    ]);

    // Soft-delete via the visitors page, as an admin would.
    $this->actingAs($user);

    Livewire::test('pages::visitors')
        ->call('confirmDelete', $visitor->id)
        ->call('deleteVisitor');

    expect(Visitor::find($visitor->id))->toBeNull()
        ->and($visit->fresh())->not->toBeNull();

    // Log still resolves its (trashed) visitor for history.
    expect($visit->fresh()->visitor?->name)->toBe('Gone Gary');

    // Pages render without "Attempt to read property on null".
    $this->actingAs($user)->get(route('dashboard'))->assertOk();
    $this->actingAs($user)->get(route('visits'))->assertOk();

    Livewire::test('pages::visits')->assertSee('Gone Gary');

    Livewire::test('pages::visits')
        ->call('viewVisit', $visit->id)
        ->assertSee('Gone Gary');

    Livewire::test('pages::visits')
        ->call('confirmDelete', $visit->id)
        ->assertSee('Gone Gary');

    // The log itself is untouched by the visitor soft-delete.
    expect($visit->fresh())->not->toBeNull();
});

test('visits csv export survives visitor deletion', function () {
    $user = User::factory()->create();
    $visitor = Visitor::factory()->create(['firstname' => 'Gone', 'lastname' => 'Gina']);
    Visit::factory()->create(['visitor_id' => $visitor->id]);

    $visitor->delete();

    $this->actingAs($user);

    Livewire::test('pages::visits')
        ->call('exportCsv')
        ->assertOk();
});
