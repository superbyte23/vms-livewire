# Pest Testing — Livewire 4 SFC

## Generating a test alongside a component

```bash
php artisan make:livewire post.create --test
```
For SFC this scaffolds a Pest test file. For `--mfc`, the test lives alongside the component's other files (`create.test.php`).

Note: converting a multi-file component with a test back to single-file (`livewire:convert --sfc`) will prompt you to confirm, since single-file format can't hold a test file — it'll be deleted.

## Core pattern — `Livewire::test()`

```php
use App\Models\Post;
use function Pest\Livewire\livewire;

it('creates a post', function () {
    livewire('post.create')
        ->set('title', 'My First Post')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect('/posts');

    expect(Post::where('title', 'My First Post')->exists())->toBeTrue();
});
```

Reference components by their **dotted name** (`post.create`, `pages::post.create`), exactly as you would in `<livewire:... />` — the underlying file format (SFC/MFC/class) is irrelevant to the test.

## Common assertions

```php
livewire('employee.index')
    ->assertSee('John Canete')
    ->assertDontSee('Deleted User')
    ->set('search', 'John')
    ->assertSee('John Canete')
    ->assertDontSee('Maria Santos')
    ->call('deactivate', $employee->id)
    ->assertDispatched('employee-deactivated')
    ->assertSet('search', 'John')
    ->assertStatus(200);
```

## Validation testing

```php
it('requires a title', function () {
    livewire('post.create')
        ->set('title', '')
        ->call('save')
        ->assertHasErrors(['title' => 'required']);
});
```

## Testing route-model-bound page components

```php
it('shows the correct employee', function () {
    $employee = Employee::factory()->create();

    livewire('pages::employee.show', ['employee' => $employee])
        ->assertSee($employee->name);
});
```

## Testing computed properties indirectly

Don't call `#[Computed]` methods directly in tests — assert on their rendered effect instead (`assertSee`, `assertViewHas`, or query the DB after an action that depends on them). Computed properties are a rendering/caching concern, not a public API surface.

## Feature vs. unit scope

- **Livewire component tests** (`livewire(...)`) — the default for anything involving `wire:model`, actions, validation, or events. Treat these as feature tests: hit the real database (use `RefreshDatabase`), assert on user-visible behavior.
- **Plain Pest unit tests** — for extracted Form objects, service classes, or model logic that doesn't need a rendered component.

## House style for this stack

- Use `it(...)` / `test(...)` with descriptive behavior-based names ("it deactivates an employee"), not method-name mirrors ("test_deactivate").
- One behavior per test; chain Livewire assertions fluently rather than splitting into multiple `livewire()` calls per test.
- Always assert both the **Livewire-level** outcome (`assertHasNoErrors`, `assertSee`) and the **persistence-level** outcome (`assertDatabaseHas` / model `exists()` checks) for anything that writes data — a green Livewire assertion alone doesn't guarantee the DB write happened correctly.
