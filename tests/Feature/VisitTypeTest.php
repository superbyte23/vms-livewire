<?php

use App\Models\User;
use App\Models\Visit;
use App\Models\Visitor;
use App\Models\VisitType;
use Livewire\Livewire;

uses()->group('feature')->group('visit-types');

test('guests are redirected to the login page', function () {
    $response = $this->get(route('settings.visit-types'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the page with seeded defaults', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('settings.visit-types'));
    $response->assertOk();

    Livewire::test('pages::settings.visit-types')
        ->assertSee('Interviewee')
        ->assertSee('Contractor');
});

test('a visit type can be added', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::settings.visit-types')
        ->set('name', 'Site Inspection')
        ->call('addType')
        ->assertHasNoErrors();

    expect(VisitType::where('name', 'Site Inspection')->exists())->toBeTrue();
    expect(VisitType::options())->toContain('Site Inspection');
});

test('duplicate visit type names are rejected', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::settings.visit-types')
        ->set('name', 'Interviewee')
        ->call('addType')
        ->assertHasErrors(['name']);
});

test('a visit type can be renamed', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $type = VisitType::where('name', 'Tour')->firstOrFail();

    Livewire::test('pages::settings.visit-types')
        ->call('editType', (string) $type->id)
        ->set('editName', 'Guided Tour')
        ->call('updateType')
        ->assertHasNoErrors();

    expect($type->fresh()->name)->toBe('Guided Tour');
});

test('deactivated types disappear from dropdown options', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $type = VisitType::where('name', 'Tour')->firstOrFail();

    Livewire::test('pages::settings.visit-types')
        ->call('toggleActive', (string) $type->id);

    expect(VisitType::options())->not->toContain('Tour');
});

test('a type used by visits cannot be deleted', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $visitor = Visitor::factory()->create();
    Visit::factory()->create([
        'visitor_id' => $visitor->id,
        'visit_type' => 'Interviewee',
        'status' => 'checked_out',
    ]);

    $type = VisitType::where('name', 'Interviewee')->firstOrFail();

    Livewire::test('pages::settings.visit-types')
        ->call('deleteType', (string) $type->id);

    expect($type->fresh())->not->toBeNull();
});

test('an unused type can be deleted', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $type = VisitType::create(['name' => 'Temporary Type', 'sort_order' => 99]);

    Livewire::test('pages::settings.visit-types')
        ->call('deleteType', (string) $type->id);

    expect(VisitType::find($type->id))->toBeNull();
});

test('booking wizard offers managed types', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    VisitType::create(['name' => 'Site Inspection', 'sort_order' => 99]);
    $tour = VisitType::where('name', 'Tour')->firstOrFail();
    $tour->update(['is_active' => false]);

    Livewire::test('pages::components.booking-wizard')
        ->set('expectedDate', now()->addDay()->toDateString())
        ->call('next')
        ->assertSee('Site Inspection')
        ->assertDontSee('<option value="Tour">', false);
});
