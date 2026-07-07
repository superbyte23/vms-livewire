# Livewire 4 — Single File Components Deep Reference

## Creating components

```bash
php artisan make:livewire post.create              # SFC (default)
php artisan make:livewire post.create --mfc         # multi-file
php artisan make:livewire post.create --class        # class-based (v3-style)
php artisan make:livewire pages::post.create         # full-page component
php artisan make:livewire post.create --test         # include a Pest test file
php artisan livewire:convert post.create             # convert sfc <-> mfc
```

| Format | Path | Component name |
|---|---|---|
| Single-file | `resources/views/components/post/⚡create.blade.php` | `post.create` |
| Multi-file | `resources/views/components/post/⚡create/create.php` (+ `.blade.php`, `.js`, `.css`) | `post.create` |
| Class-based | `app/Livewire/Post/Create.php` | `post.create` |
| Page (SFC) | `resources/views/pages/post/⚡create.blade.php` | `pages::post.create` |

The ⚡ emoji is cosmetic only — disable globally via `config/livewire.php`:
```php
'make_command' => ['emoji' => false],
```
Restore v3-style class-based defaults:
```php
'make_command' => ['type' => 'class', 'emoji' => false],
```

## Anatomy of an SFC

```php
<?php

use Livewire\Component;

new class extends Component {
    public $title = '';

    public function save()
    {
        // ...
    }
};
?>

<div>
    <input wire:model="title" type="text">
    <button wire:click="save">Save Post</button>
</div>
```

Rules:
- Exactly **one root HTML element** in the template half — Livewire errors otherwise.
- The `<?php ... ?>` block must contain an anonymous class extending `Livewire\Component` (or `Livewire\Volt\Component`-equivalent behavior is now native — just use `Livewire\Component`).
- Everything after the closing `?>` is the Blade view.

## Rendering & routing

```blade
<livewire:post.create />
<livewire:post.create :title="$initialTitle" />        {{-- dynamic prop --}}
<livewire:pages::post.create />                          {{-- namespaced --}}
```

```php
Route::livewire('/posts/create', 'pages::post.create');
Route::livewire('/posts/{post}', 'pages::post.show');    // route-model binding just works
```

For route-model binding, type-hint a public property matching the route parameter name — no `mount()` needed:
```php
new class extends Component {
    public Post $post; // Automatically bound from {post}
};
```

## Props & mount()

```php
new class extends Component {
    public $title;

    public function mount($title = null)
    {
        $this->title = $title;
    }
};
```
You can skip `mount()` entirely if property names match the passed prop names — Livewire auto-assigns them. **Props are not reactive by default**; if a parent's bound value changes after initial render, the child won't see it unless you opt into reactive props (see Nesting docs / `#[Reactive]`).

## Accessing data in views

Three ways, in order of idiomatic-ness for v4:

1. **Public properties** — simplest, auto-exposed to the template (`{{ $title }}`).
2. **Computed properties** — for anything derived/expensive (queries, aggregates):
   ```php
   use Livewire\Attributes\Computed;

   #[Computed]
   public function posts()
   {
       return Post::with('author')->latest()->get();
   }
   ```
   Access in the view as `$this->posts` (note the `$this->` — this is what tells Livewire to call + memoize for the current request only, not persisted between requests).
3. **`render()` with explicit data** — like a controller; runs on *every* update, so avoid expensive work here unless you truly need fresh data every time:
   ```php
   public function render()
   {
       return $this->view(['currentTime' => now()]);
   }
   ```

Protected/private properties are never sent to the client (safe for secrets) but also aren't persisted between requests — only good for static values set at declaration time.

## Organizing components

Default namespaces:
- `pages::` → `resources/views/pages/`
- `layouts::` → `resources/views/layouts/`

Add custom namespaces in `config/livewire.php`:
```php
'component_namespaces' => [
    'admin' => resource_path('views/admin'),
],
```
Then: `php artisan make:livewire admin::users-table`, `<livewire:admin::users-table />`, `Route::livewire('/admin/users', 'admin::users-table')`.

## Actions, validation, forms

Inline validation:
```php
public function save()
{
    $validated = $this->validate([
        'title' => 'required|min:3',
        'body' => 'required',
    ]);

    Post::create($validated);
}
```

Attribute-based validation on properties (good for live/real-time feedback):
```php
use Livewire\Attributes\Validate;

#[Validate('required|min:3')]
public string $title = '';
```

For reusable, non-trivial forms, extract a Form object (`php artisan make:livewire-form PostForm`) and use it via `public PostForm $form;` — keeps the component lean and the validation rules testable in isolation.

## Key attributes cheat-sheet

| Attribute | Purpose |
|---|---|
| `#[Computed]` | Memoize an expensive method per-request, expose as `$this->prop` |
| `#[Validate('rule')]` | Declarative per-property validation |
| `#[Url]` | Sync a property to the query string |
| `#[Locked]` | Prevent client-side tampering with a public property |
| `#[On('event-name')]` | Listen for a Livewire/browser event |
| `#[Reactive]` | Opt a prop into reacting to parent updates |
| `#[Lazy]` | Defer initial render (skeleton first, then load) |
| `#[Layout('layouts::app')]` | Set the layout for a page component |
| `#[Title('Page Title')]` | Set the `<title>` for a page component |
| `#[Renderless]` | Skip the render step after an action (perf) |

## Islands (v4 headline feature)

Isolate a region of a component so it updates independently without re-rendering the whole component — great for expensive sub-sections (e.g. a live-updating counter next to a large table).

```blade
@island
    <div>{{ $this->expensiveComputedThing }}</div>
@endisland
```
Use for anything that updates on a different cadence than the rest of the component (polling widgets, notification counters, chat panels).

## Scoped CSS/JS (SFC only)

```blade
<style>
    .title { font-weight: 600; }
</style>

<script>
    $wire.on('saved', () => console.log('saved!'));
</script>
```
Styles are auto-scoped to the component; use `<style global>` to opt out. Both compile to cached `.css`/`.js` assets automatically — no manual asset pipeline wiring needed.

## Common `wire:model` modifiers

| Modifier | Effect |
|---|---|
| `.live` | Update on every keystroke/change instead of waiting for an action |
| `.live.debounce.300ms` | Live update, debounced |
| `.blur` | Update on blur instead of input |
| `.number` | Cast to number |
| `.boolean` | Cast to boolean (checkboxes) |

## Nesting components

- Pass data down via props (`:prop="$value"`); pass events up via `$dispatch()` / `#[On]`.
- Use `wire:key` on every dynamically-keyed nested component or loop item.
- Reactive props require the `#[Reactive]` attribute on the child's property.

## Troubleshooting quick reference

- **"Component not found"** → check dotted name matches file path; clear view cache: `php artisan view:clear`.
- **Blank render / no output** → check for missing single root element, or a PHP syntax error in the `<?php ... ?>` block.
- **Duplicate class name errors** → two SFCs share a name in different directories; rename or namespace one.
