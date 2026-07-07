---
name: tall-stack-expert
description: >
  Expert senior-developer guidance for building applications on Laravel 13, Livewire 4 Single File Components (SFC), and Flux UI v2 (free/core tier). Use this skill whenever the user is writing, reviewing, refactoring, or debugging Livewire components, Blade views with Flux components, routing via Route::livewire(), Livewire actions, properties, validation, or forms, or anything in a "TALL stack" (Tailwind, Alpine, Laravel, Livewire) project, even if they don't say "TALL stack" explicitly. Trigger on mentions of Livewire, Volt, SFC, .blade.php components with the lightning-bolt prefix, wire:model, wire:click, wire:submit and similar directives, Flux components such as flux:button, flux:input, or flux:modal, or Laravel 13 app architecture questions. Always consult this skill before writing new Livewire components or Flux UI markup so output matches current v4/v2 conventions instead of stale Livewire 3, standalone Volt-package, or Flux v1 patterns.
---

# TALL Stack Expert (Laravel 13 · Livewire 4 SFC · Flux UI v2)

Act as a senior full-stack Laravel developer who lives and breathes this exact stack. Default to the newest conventions below — do not fall back to older Livewire 3 class-component habits, the standalone `livewire/volt` package, or Flux v1 patterns unless the user's codebase clearly still uses them (check existing files first).

## Core stack facts (don't second-guess these)

- **Livewire 4** ships **Single File Components (SFC) as the default** output of `php artisan make:livewire`. Volt's old "functional/class API in one file" idea is now native to Livewire itself — you no longer need the separate `livewire/volt` package for this.
- SFC files live at `resources/views/components/**/⚡name.blade.php` (the ⚡ emoji prefix is optional, cosmetic, and configurable in `config/livewire.php`).
- A component is still referenced everywhere (Blade tags, `Route::livewire()`, tests) by its dotted name — `post.create` — regardless of whether it's SFC, multi-file (`--mfc`), or class-based.
- Full-page components use the `pages::` namespace (`resources/views/pages/...`) and are routed with `Route::livewire('/uri', 'pages::name')`, not a controller.
- Flux UI v2's **free/core tier** is large — the paid Pro tier is a specific, short list of advanced widgets. Don't assume something is Pro just because it feels complex; check the list in `references/flux-ui-v2-components.md`.

Read the reference files below before generating non-trivial code — they contain the concrete syntax, gotchas, and decision rules. Load only what's relevant to the task.

- `references/livewire-4-sfc.md` — SFC anatomy, `make:livewire` options, props/mount, computed properties, actions, validation, forms, events, Islands, scoped CSS/JS, lifecycle hooks, nesting, `wire:model` modifiers.
- `references/flux-ui-v2-components.md` — Full free-vs-Pro component breakdown, installation/theming basics, and idiomatic markup patterns for forms, tables, modals, navigation.
- `references/pest-testing.md` — Pest conventions for testing Livewire 4 components (SFC-aware), including the `--test` flag and `Livewire::test()` patterns.

## Working defaults

1. **New component → SFC by default.** Only use `--class` or `--mfc` if the user's project config or existing conventions call for it, or the component is genuinely large/JS-heavy (then suggest `--mfc`).
2. **Prefer computed properties (`#[Computed]`) over calling queries in `render()`** for anything used in the view — cheaper, cached per-request, and idiomatic v4.
3. **Validation**: use `#[Validate]` attributes on properties for simple cases, `$this->validate([...])` inline for one-off action validation, and a dedicated Form object (`php artisan make:livewire-form`) once a form has non-trivial state or is reused.
4. **Flux first for UI primitives.** Reach for `flux:button`, `flux:input`, `flux:field`, `flux:modal`, `flux:table`, etc. before hand-rolling Tailwind markup — check `references/flux-ui-v2-components.md` for whether the exact component you want is free or Pro before writing code that assumes it's installed.
5. **One root element per SFC template** — Livewire will error otherwise. If a component needs multiple visual blocks, wrap in a single `<div>` and use Flux layout primitives inside.
6. **`wire:key`** on every item inside a loop that can reorder, be added to, or be removed — non-negotiable for Livewire's DOM diffing to behave.
7. **Don't leak sensitive data** — use `protected` properties (or dedicated read-only computed methods) for anything that shouldn't be serialized to the client-side snapshot.
8. **Testing**: default to Pest for anything non-trivial (`Livewire::test('component.name')->set()->call()->assertSet()`), unless the user's project is already on PHPUnit-style Livewire tests.
9. **Match existing code style first.** If the user's repo already has class-based components or a Volt-package legacy pattern, mirror it rather than silently "upgrading" their file to SFC mid-task — offer the modernization as a suggestion instead of doing it unprompted.

## Quick component skeleton (SFC)

```php
<?php
// resources/views/pages/employee/⚡index.blade.php

use App\Models\Employee;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $search = '';

    #[Computed]
    public function employees()
    {
        return Employee::query()
            ->when($this->search, fn ($q) => $q->where('name', 'like', "%{$this->search}%"))
            ->paginate(15);
    }

    public function deactivate(Employee $employee): void
    {
        $employee->update(['is_active' => false]);
    }
};
?>

<div>
    <flux:input wire:model.live.debounce.300ms="search" placeholder="Search employees..." icon="magnifying-glass" />

    <flux:table>
        <flux:table.columns>
            <flux:table.column>Name</flux:table.column>
            <flux:table.column>Status</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @foreach ($this->employees as $employee)
                <flux:table.row :key="$employee->id">
                    <flux:table.cell>{{ $employee->name }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge :color="$employee->is_active ? 'green' : 'zinc'">
                            {{ $employee->is_active ? 'Active' : 'Inactive' }}
                        </flux:badge>
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:button size="sm" variant="danger" wire:click="deactivate({{ $employee->id }})" wire:confirm="Deactivate this employee?">
                            Deactivate
                        </flux:button>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    {{ $this->employees->links() }}
</div>
```

This skeleton demonstrates: SFC anatomy, `#[Computed]`, pagination, debounced live search, Flux table/badge/button primitives, `wire:confirm`, and safe route-model-bound `wire:click` calls. Use it as a mental template, not something to paste verbatim — adapt to the actual task.
