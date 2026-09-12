<?php

use App\Models\User;
use App\Models\Visitor;
use Livewire\Livewire;

uses()->group('feature')->group('visitor-identity');

test('name parts compose the display name on create', function () {
    $visitor = Visitor::factory()->create([
        'firstname' => 'Jose',
        'middlename' => 'Protacio',
        'lastname' => 'Rizal',
    ]);

    expect($visitor->name)->toBe('Jose Protacio Rizal');
});

test('blank middle name composes cleanly without double spaces', function () {
    $visitor = Visitor::factory()->create([
        'firstname' => 'Jane',
        'middlename' => null,
        'lastname' => 'Doe',
    ]);

    expect($visitor->name)->toBe('Jane Doe');
});

test('full names split into parts consistently', function () {
    expect(Visitor::splitName('Jose Protacio Rizal'))->toBe([
        'firstname' => 'Jose',
        'middlename' => 'Protacio',
        'lastname' => 'Rizal',
    ])->and(Visitor::splitName('Single Mononym'))->toBe([
        'firstname' => 'Single',
        'middlename' => null,
        'lastname' => 'Mononym',
    ]);

    $visitor = Visitor::factory()->withFullName('Single Mononym')->create();

    expect($visitor->firstname)->toBe('Single')
        ->and($visitor->middlename)->toBeNull()
        ->and($visitor->lastname)->toBe('Mononym')
        ->and($visitor->name)->toBe('Single Mononym');
});

test('visitors are searchable by lastname and government id', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Visitor::factory()->create(['firstname' => 'Amelia', 'lastname' => 'Earhart', 'government_id' => 'GOV-999']);

    Livewire::test('pages::visitors')
        ->set('search', 'Earhart')
        ->assertSee('Amelia');

    Livewire::test('pages::visitors')
        ->set('search', 'GOV-999')
        ->assertSee('Amelia');
});

test('admin edit writes parts and re-syncs the display name', function () {
    $user = User::factory()->create();
    $visitor = Visitor::factory()->create(['firstname' => 'Before', 'lastname' => 'Name']);
    $this->actingAs($user);

    Livewire::test('pages::visitors')
        ->call('openEditModal', $visitor->id)
        ->set('editFirstname', 'After')
        ->set('editMiddlename', 'Middle')
        ->set('editLastname', 'Name')
        ->set('editGovernmentId', 'GOV-123')
        ->set('editAddress', '123 Main St')
        ->call('updateVisitor');

    $visitor->refresh();

    expect($visitor->name)->toBe('After Middle Name')
        ->and($visitor->government_id)->toBe('GOV-123')
        ->and($visitor->address)->toBe('123 Main St');
});

test('admin create requires first and last names', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::visitors')
        ->call('openCreateModal')
        ->set('createFirstname', 'Only First')
        ->call('createVisitor')
        ->assertHasErrors(['createLastname' => 'required']);

    expect(Visitor::where('firstname', 'Only First')->exists())->toBeFalse();
});

test('kiosk registration stores the structured identity', function () {
    $idPhoto = 'data:image/jpeg;base64,'.base64_encode('gov-id-bytes');

    Livewire::test('pages::welcome')
        ->call('startCreating')
        ->set('firstname', 'Kiosk')
        ->set('middlename', 'Middle')
        ->set('lastname', 'Kid')
        ->set('phone', '555-0177')
        ->set('address', '456 Side St')
        ->set('governmentId', 'GOV-456')
        ->set('validIdPhoto', $idPhoto)
        ->call('nextStep')
        ->set('purpose', 'Meeting')
        ->call('nextStep')
        ->call('checkIn');

    $visitor = Visitor::where('firstname', 'Kiosk')->first();

    expect($visitor)->not->toBeNull()
        ->and($visitor->name)->toBe('Kiosk Middle Kid')
        ->and($visitor->address)->toBe('456 Side St')
        ->and($visitor->government_id)->toBe('GOV-456')
        ->and($visitor->government_id_photo)->toBe($idPhoto)
        ->and($visitor->photo)->toBeNull();
});

test('admin edit saves the government ID capture without touching the profile photo', function () {
    $user = User::factory()->create();
    $visitor = Visitor::factory()->create(['photo' => 'data:image/jpeg;base64,profile']);
    $this->actingAs($user);

    Livewire::test('pages::visitors')
        ->call('openEditModal', $visitor->id)
        ->set('editGovIdPhotoDataUri', 'data:image/jpeg;base64,gov-id')
        ->call('updateVisitor');

    $visitor->refresh();

    expect($visitor->government_id_photo)->toBe('data:image/jpeg;base64,gov-id')
        ->and($visitor->photo)->toBe('data:image/jpeg;base64,profile');
});
