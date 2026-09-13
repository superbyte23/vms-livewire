# Blade Tabler Icons

All **6,184 [Tabler Icons](https://tabler.io/icons)** (5,130 outline + 1,054 filled) as Blade
components for Laravel — and Livewire — applications. Built on top of
[`blade-ui-kit/blade-icons`](https://github.com/blade-ui-kit/blade-icons), so icons are cached,
tree-shaken (only the ones you actually use ever get compiled), and follow the same
`<x-icon-name />` convention as `blade-heroicons` and friends.

Icons are plain SVG Blade components, not Livewire components — there's no reactive state to
manage, so they drop straight into any Blade view, Livewire 4 SFC, or Flux UI markup with zero
extra wiring, and they never trigger a network round-trip.

## Installation

Requirements: PHP `^8.1` and Laravel 10, 11, 12, or 13.
[`blade-ui-kit/blade-icons`](https://github.com/blade-ui-kit/blade-icons) is installed
automatically as a dependency.

```bash
composer require superbyte/blade-tabler-icons
```

That's it — the service provider is auto-discovered. Optionally publish the config file if you
want to set a default class or default attributes for every icon:

```bash
php artisan vendor:publish --tag=blade-tabler-icons-config
```

Installing from a local copy (e.g. to patch or preview changes) works via a Composer path
repository:

```json
"repositories": [
    { "type": "path", "url": "packages/blade-tabler-icons", "options": { "symlink": true } }
]
```

```bash
composer require superbyte/blade-tabler-icons:@dev
```

## Usage

Icon names match the file names on [tabler.io/icons](https://tabler.io/icons) (kebab-case).

**Outline (default style)** — prefix `tabler-`:

```blade
<x-tabler-heart />
<x-tabler-shopping-cart class="w-6 h-6 text-zinc-500" />
<x-tabler-arrow-right />
```

**Filled** — prefix `tabler-filled-`:

```blade
<x-tabler-filled-heart class="w-6 h-6 text-red-500" />
<x-tabler-filled-star />
```

Any attribute you pass — `class`, `width`, `height`, `stroke-width`, `aria-hidden`, etc. — is
merged onto the root `<svg>`, overriding the component's defaults.

You can also use the `@svg` Blade directive that `blade-icons` provides, which is handy when the
icon name is dynamic:

```blade
@svg('tabler-' . $icon, 'w-5 h-5')
@svg('tabler-filled-' . $icon, 'w-5 h-5')
```

> **How filled components resolve.** `blade-icons` matches icon sets by splitting the component
> name on the *first* dash, so a `tabler-filled` prefix could never resolve on its own. This
> package therefore ships a `resources/svg/prefixed/` mirror holding copies of the filled icons
> renamed to `filled-{icon}.svg`, registered on the `tabler` set — `tabler-filled-heart`
> resolves as set `tabler` + icon `filled-heart`. The mirror is rebuilt automatically by
> `bin/update-icons.sh`; never edit it by hand.

### Inside a Livewire 4 SFC

Icons are static markup, so just use them in the template like any other Blade component —
no `wire:` directives needed:

```php
<?php
// resources/views/pages/product/⚡index.blade.php

use App\Models\Product;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public bool $inCart = false;

    #[Computed]
    public function products()
    {
        return Product::query()->get();
    }

    public function addToCart(Product $product): void
    {
        $this->inCart = true;
    }
};
?>

<div>
    @foreach ($this->products as $product)
        <div class="flex items-center justify-between" wire:key="product-{{ $product->id }}">
            <span>{{ $product->name }}</span>

            <button wire:click="addToCart({{ $product->id }})" class="flex items-center gap-1">
                <x-tabler-shopping-cart-plus class="w-5 h-5" />
                Add to cart
            </button>
        </div>
    @endforeach

    @if ($inCart)
        <p class="flex items-center gap-1 text-green-600">
            <x-tabler-filled-circle-check class="w-4 h-4" />
            Added!
        </p>
    @endif
</div>
```

## Using with Flux UI's `icon="..."` props

Flux's `icon` prop (on `flux:button`, `flux:menu.item`, `flux:icon`, etc.) doesn't look at
`blade-icons` components at all — it resolves against Flux's own Blade views under
`resources/views/flux/icon/` in *your app*. Heroicons work by default only because Flux ships
those views internally (so `<flux:button icon="tabler-filled-star" />` won't work until you
generate the view below).

This package includes an artisan command that generates those Flux-compatible views from the
bundled Tabler SVGs, so you only pay the cost (extra files in your app) for icons you actually
use:

```bash
php artisan tabler-icons:flux star heart shopping-cart-plus   # just these icons
php artisan tabler-icons:flux --all                            # every icon (6,184 files)
php artisan tabler-icons:flux star --style=outline              # outline only, skip filled
php artisan tabler-icons:flux star --style=filled               # filled only, skip outline
```

This writes to `resources/views/flux/icon/tabler/{name}.blade.php` (outline) and
`resources/views/flux/icon/tabler-filled/{name}.blade.php` (filled). Once generated, use them
anywhere Flux accepts an icon name, with the folder as a dot-namespace prefix:

```blade
<flux:button icon="tabler.star">Favorite</flux:button>
<flux:menu.item icon="tabler-filled.trash" variant="danger">Delete</flux:menu.item>
<flux:icon.tabler.shopping-cart-plus class="w-5 h-5" />
```

Generated views default to 16px (`size-4`), matching Flux's own button-icon sizing — pass any
`class=` to override, e.g. `<flux:icon.tabler.star class="w-6 h-6" />`.

Re-run the command any time you use a new icon name in Flux components — it's cheap and
idempotent, and the generated files are plain Blade views you can commit to your app repo like
any other view.

## Browsing icon names

Search and preview every icon at [tabler.io/icons](https://tabler.io/icons) — the name shown
there (e.g. "brand-github") maps directly to the component name (`<x-tabler-brand-github />`).

## Updating the bundled icon set

The SVGs are synced from the `@tabler/icons` package on npm rather than a full git clone of the
`tabler/tabler-icons` monorepo, which keeps this package small and the sync fast:

```bash
bin/update-icons.sh          # latest release
bin/update-icons.sh 3.47.0   # a specific version
```

Review the diff, then bump this package's version. The script also rebuilds the
`resources/svg/prefixed/` mirror described above and warns if an upstream outline icon ever
collides with a `filled-*` mirror name (upstream wins in that case).

## Troubleshooting

- **New icons don't appear / stale list after updating.** `blade-icons` caches the icon
  manifest — clear it and the compiled views:
  ```bash
  php artisan icons:clear
  php artisan view:clear
  ```
- **`Svg by name "x" from set "tabler" not found`.** The icon name doesn't exist in the bundled
  set — check the spelling against [tabler.io/icons](https://tabler.io/icons) (kebab-case, e.g.
  `shopping-cart-plus`, not `shopping_cart_plus`).

## Testing

```bash
composer install
vendor/bin/pest
```

## Credits

- Icons by [Tabler Icons](https://tabler.io/icons) (Paweł Kuna and contributors), MIT licensed.
- Blade component infrastructure by [Blade Icons](https://github.com/blade-ui-kit/blade-icons).

## License

MIT — see [LICENSE.md](LICENSE.md) (covers both this package and the bundled icon SVGs).
