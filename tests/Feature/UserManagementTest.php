<?php

use App\Models\User;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('users'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the user management page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('users'));
    $response->assertOk();
});

test('users page shows list of users', function () {
    $admin = User::factory()->create();
    $users = User::factory()->count(3)->create();

    $this->actingAs($admin);

    $response = $this->get(route('users'));

    foreach ($users as $user) {
        $response->assertSee($user->name);
    }
});

test('admin can create a new user', function () {
    $admin = User::factory()->create();
    $this->actingAs($admin);

    Livewire::test('pages::users')
        ->set('createName', 'New User')
        ->set('createEmail', 'newuser@example.com')
        ->set('createPassword', 'password123')
        ->set('createPasswordConfirmation', 'password123')
        ->call('createUser')
        ->assertDispatched('toast-show');

    $this->assertDatabaseHas('users', [
        'name' => 'New User',
        'email' => 'newuser@example.com',
    ]);
});

test('admin can edit a user', function () {
    $admin = User::factory()->create();
    $user = User::factory()->create();

    $this->actingAs($admin);

    Livewire::test('pages::users')
        ->call('openEditModal', $user->id)
        ->assertSet('editName', $user->name)
        ->assertSet('editEmail', $user->email)
        ->set('editName', 'Updated Name')
        ->call('updateUser')
        ->assertDispatched('toast-show');

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'name' => 'Updated Name',
    ]);
});

test('admin can delete a user', function () {
    $admin = User::factory()->create();
    $user = User::factory()->create();

    $this->actingAs($admin);

    Livewire::test('pages::users')
        ->call('confirmDelete', $user->id)
        ->assertSet('showDeleteModal', true)
        ->call('deleteUser')
        ->assertDispatched('toast-show');

    $this->assertDatabaseMissing('users', ['id' => $user->id]);
});

test('admin cannot delete their own account', function () {
    $admin = User::factory()->create();
    $this->actingAs($admin);

    Livewire::test('pages::users')
        ->call('confirmDelete', $admin->id)
        ->call('deleteUser')
        ->assertDispatched('toast-show');

    $this->assertDatabaseHas('users', ['id' => $admin->id]);
});

test('search filters users by name and email', function () {
    $admin = User::factory()->create();
    User::factory()->create(['name' => 'Alice Johnson', 'email' => 'alice@example.com']);
    User::factory()->create(['name' => 'Bob Smith', 'email' => 'bob@example.com']);

    $this->actingAs($admin);

    Livewire::test('pages::users')
        ->set('search', 'Alice')
        ->assertSee('Alice Johnson')
        ->assertDontSee('Bob Smith');
});

test('create user validates required fields', function () {
    $admin = User::factory()->create();
    $this->actingAs($admin);

    Livewire::test('pages::users')
        ->call('createUser')
        ->assertHasErrors(['createName' => 'required']);
});
