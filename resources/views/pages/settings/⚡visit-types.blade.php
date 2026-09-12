<?php

use App\Models\VisitType;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Visit types')] #[Layout('layouts::app')] class extends Component
{
    public string $name = '';

    public ?string $editingId = null;

    public string $editName = '';

    public bool $showEditModal = false;

    #[Computed]
    public function types(): Collection
    {
        return VisitType::ordered()->get();
    }

    public function addType(): void
    {
        $this->validate([
            'name' => 'required|string|max:255|unique:visit_types,name',
        ]);

        VisitType::create([
            'name' => trim($this->name),
            'sort_order' => (VisitType::max('sort_order') ?? -1) + 1,
        ]);

        $this->reset('name');

        Flux::toast(variant: 'success', text: __('Visit type added.'));
    }

    public function editType(string $id): void
    {
        $type = VisitType::findOrFail($id);

        $this->editingId = $type->id;
        $this->editName = $type->name;
        $this->showEditModal = true;
    }

    public function updateType(): void
    {
        $type = VisitType::findOrFail($this->editingId);

        $this->validate([
            'editName' => 'required|string|max:255|unique:visit_types,name,'.$type->id,
        ]);

        $type->update(['name' => trim($this->editName)]);

        $this->reset('editingId', 'editName', 'showEditModal');

        Flux::toast(variant: 'success', text: __('Visit type updated.'));
    }

    public function cancelEdit(): void
    {
        $this->reset('editingId', 'editName', 'showEditModal');
    }

    public function toggleActive(string $id): void
    {
        $type = VisitType::findOrFail($id);

        $type->update(['is_active' => ! $type->is_active]);

        Flux::toast(
            variant: 'success',
            text: $type->is_active ? __('Visit type activated.') : __('Visit type deactivated.'),
        );
    }

    public function deleteType(string $id): void
    {
        $type = VisitType::findOrFail($id);

        if ($type->isInUse()) {
            Flux::toast(variant: 'warning', text: __('This type is used by existing visits. Deactivate it instead.'));

            return;
        }

        $type->delete();

        Flux::toast(variant: 'success', text: __('Visit type deleted.'));
    }
}; ?>

<div>
<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Visit types') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Visit types')" :subheading="__('Manage the visit types offered at check-in and booking')">
        <div class="flex gap-2">
            <flux:input wire:model="name" placeholder="{{ __('New visit type…') }}" class="flex-1" />
            <flux:button variant="primary" wire:click="addType" icon="tabler.plus" class="shrink-0">
                {{ __('Add') }}
            </flux:button>
        </div>
        <flux:error name="name" />

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Visit type') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Actions') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->types as $type)
                    <flux:table.row :key="$type->id">
                        <flux:table.cell variant="strong" class="py-1.5">
                            <span class="inline-flex items-center gap-2">
                                <span class="{{ $type->is_active ? '' : 'text-neutral-400 dark:text-neutral-500' }}">{{ $type->name }}</span>
                                <span class="h-1.5 w-1.5 rounded-full {{ $type->is_active ? 'bg-emerald-500' : 'bg-neutral-300 dark:bg-neutral-600' }}" title="{{ $type->is_active ? __('Active') : __('Inactive') }}"></span>
                            </span>
                        </flux:table.cell>
                        <flux:table.cell align="end" class="py-1.5">
                            <div class="flex justify-end gap-0.5">
                                <flux:button size="sm" variant="ghost" icon="tabler.pencil" wire:click="editType('{{ $type->id }}')" title="{{ __('Rename') }}" aria-label="{{ __('Rename :name', ['name' => $type->name]) }}" />
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    :icon="$type->is_active ? 'tabler.eye-off' : 'tabler.eye'"
                                    wire:click="toggleActive('{{ $type->id }}')"
                                    title="{{ $type->is_active ? __('Deactivate') : __('Activate') }}"
                                    aria-label="{{ $type->is_active ? __('Deactivate :name', ['name' => $type->name]) : __('Activate :name', ['name' => $type->name]) }}"
                                />
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="tabler.trash"
                                    wire:click="deleteType('{{ $type->id }}')"
                                    wire:confirm="{{ __('Delete :name?', ['name' => $type->name]) }}"
                                    title="{{ __('Delete') }}"
                                    aria-label="{{ __('Delete :name', ['name' => $type->name]) }}"
                                />
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="2">
                            <p class="py-3 text-center text-sm text-neutral-400 dark:text-neutral-500">{{ __('No visit types yet. Add the first one above.') }}</p>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        <p class="mt-3 text-xs text-neutral-400 dark:text-neutral-500">
            {{ __('Inactive types are hidden from check-in and booking forms. Types used by existing visits cannot be deleted.') }}
        </p>
    </x-pages::settings.layout>
</section>

<flux:modal wire:model="showEditModal" name="rename-visit-type" class="max-w-lg">
    <flux:heading size="lg">{{ __('Rename visit type') }}</flux:heading>

    <div class="mt-4">
        <flux:input wire:model="editName" label="{{ __('Name') }}" />
        <flux:error name="editName" />
    </div>

    <div class="mt-6 flex justify-end gap-2">
        <flux:button variant="ghost" wire:click="cancelEdit">{{ __('Cancel') }}</flux:button>
        <flux:button variant="primary" wire:click="updateType">{{ __('Save') }}</flux:button>
    </div>
</flux:modal>
</div>
