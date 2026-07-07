# Flux UI v2 — Free/Core Component Reference

Scope note: this reference covers the **free/core tier only** (`composer require livewire/flux`, no license needed). Pro components require `livewire/flux-pro` + a license — don't write code assuming they're installed unless the user confirms they have Flux Pro.

## Installation baseline (v2)

```bash
composer require livewire/flux
```
Requires: Laravel 10+, Livewire 3.7+ (Livewire 4 obviously satisfies this), Tailwind CSS v4.2+.

Layout:
```blade
<head>
    ...
    @fluxAppearance
</head>
<body>
    ...
    @livewireScripts
    @fluxScripts
</body>
```

`resources/css/app.css`:
```css
@import 'tailwindcss';
@import '../../vendor/livewire/flux/dist/flux.css';
@custom-variant dark (&:where(.dark, .dark *));
```

Dark mode is automatic via `@fluxAppearance`; remove that directive to manage the `.dark` class yourself. To customize a component's markup: `php artisan flux:publish` (select components, or `--all`).

## Free tier — full component list

These ship in the base `livewire/flux` package, no license required:

**Layout & structure**: Card, Separator, Navbar, Header/Sidebar layouts, Breadcrumbs
**Typography**: Heading, Text
**Forms & inputs**: Button, Input, Textarea, Checkbox, Radio, Switch, Select (native/basic), Field, OTP Input, Composer, Color picker
**Feedback & overlays**: Modal, Popover, Tooltip, Toast, Callout, Progress, Skeleton
**Data display**: Table, Avatar, Badge, Pagination, Timeline, Pillbox, Profile, Carousel
**Navigation & actions**: Dropdown, Icon, Brand

## Pro tier only — do NOT assume these are available

Accordion, Autocomplete, Calendar, Chart, Command (palette), Context (menu), Date picker, Editor (rich text), Listbox / Searchable select / Combobox (the advanced `flux:select` variants), Tabs, File upload, Time picker, Kanban, Slider.

If a user's task calls for one of these and they haven't mentioned owning Flux Pro, flag it explicitly rather than silently writing markup that will 404/error: *"This needs a Pro component (`flux:date-picker`) — do you have a Flux Pro license, or should I build a free-tier alternative (e.g. a native `<input type="date">` styled with `flux:input`)?"*

## Design principles (matters for code review / idiomatic usage)

- **Familiar names over technical ones**: "accordion" not "disclosure", "form inputs" not "form controls".
- **Composability over mega-components**: build complex UI by combining primitives, e.g.:
  ```blade
  <flux:dropdown>
      <flux:button icon:trailing="chevron-down">Options</flux:button>
      <flux:menu>
          <flux:menu.item icon="pencil">Edit</flux:menu.item>
          <flux:menu.item icon="trash" variant="danger">Delete</flux:menu.item>
      </flux:menu>
  </flux:dropdown>
  ```
- **Flux styles the component, you own spacing/layout** — don't expect built-in margin utilities; wrap in your own layout containers.
- **CSS over JS where possible** — Flux leans on modern CSS (`:has()`, etc.) so many interactions work without Alpine/JS on your part.

## Idiomatic patterns

### Form field
```blade
<flux:field>
    <flux:label>Email</flux:label>
    <flux:input wire:model="email" type="email" />
    <flux:error name="email" />
</flux:field>
```
Simpler shorthand for most cases (Field/Label/error wiring built in):
```blade
<flux:input wire:model="email" label="Email" type="email" />
```

### Button variants
```blade
<flux:button variant="primary">Save</flux:button>
<flux:button variant="danger" wire:click="delete" wire:confirm="Are you sure?">Delete</flux:button>
<flux:button variant="ghost" size="sm" icon="pencil">Edit</flux:button>
```

### Modal
```blade
<flux:modal wire:model="showModal" name="edit-employee">
    <flux:heading size="lg">Edit Employee</flux:heading>
    <flux:input wire:model="form.name" label="Name" />
    <div class="flex gap-2 mt-4">
        <flux:button variant="primary" wire:click="save">Save</flux:button>
        <flux:button variant="ghost" x-on:click="$flux.modal('edit-employee').close()">Cancel</flux:button>
    </div>
</flux:modal>

<flux:button x-on:click="$flux.modal('edit-employee').show()">Edit</flux:button>
```

### Table
```blade
<flux:table>
    <flux:table.columns>
        <flux:table.column>Name</flux:table.column>
        <flux:table.column>Role</flux:table.column>
    </flux:table.columns>
    <flux:table.rows>
        @foreach ($users as $user)
            <flux:table.row :key="$user->id">
                <flux:table.cell>{{ $user->name }}</flux:table.cell>
                <flux:table.cell>{{ $user->role }}</flux:table.cell>
            </flux:table.row>
        @endforeach
    </flux:table.rows>
</flux:table>
```

### Toast (flash feedback)
```php
public function save()
{
    // ...
    Flux::toast('Employee saved successfully.', variant: 'success');
}
```

### Badge / status
```blade
<flux:badge color="green">Active</flux:badge>
<flux:badge color="zinc">Inactive</flux:badge>
```

## Theming

Flux ships hand-picked color schemes and supports custom themes via CSS variables — see `flux:publish` + `resources/css/app.css` `@theme` block if the user wants brand colors beyond the defaults. Don't hardcode raw Tailwind colors inside Flux components' expected color props (`color="green"` etc.) — use Flux's named palette so dark mode keeps working correctly.
