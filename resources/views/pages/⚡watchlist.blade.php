<?php

use App\Models\Visitor;
use Flux\Flux;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Watchlist')] #[Layout('layouts::app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $addSearch = '';
    public array $selected = [];
    public bool $selectAll = false;

    public bool $showViewModal = false;
    public bool $showEditNotesModal = false;
    public bool $showUnflagModal = false;
    public bool $showAddModal = false;
    public bool $showBulkUnflagModal = false;

    public ?string $viewingVisitorId = null;
    public ?string $editingNotesId = null;
    public ?string $unflaggingVisitorId = null;
    public ?string $editNotes = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedAddSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function flaggedVisitors(): LengthAwarePaginator
    {
        return Visitor::flagged()
            ->when($this->search, fn ($q) => $q->search($this->search))
            ->orderByDesc('updated_at')
            ->paginate(15);
    }

    #[Computed]
    public function viewingVisitor(): ?Visitor
    {
        if ($this->viewingVisitorId === null) {
            return null;
        }

        return Visitor::find($this->viewingVisitorId);
    }

    #[Computed]
    public function unflaggingVisitor(): ?Visitor
    {
        if ($this->unflaggingVisitorId === null) {
            return null;
        }

        return Visitor::find($this->unflaggingVisitorId);
    }

    #[Computed]
    public function unflaggedCandidates(): LengthAwarePaginator
    {
        return Visitor::where('is_flagged', false)
            ->when($this->addSearch, fn ($q) => $q->search($this->addSearch))
            ->orderByDesc('created_at')
            ->paginate(10);
    }

    public function viewVisitor(string $id): void
    {
        $this->viewingVisitorId = $id;
        $this->showViewModal = true;
    }

    public function openEditNotes(string $id): void
    {
        $visitor = Visitor::findOrFail($id);
        $this->editingNotesId = $id;
        $this->editNotes = $visitor->notes ?? '';
        $this->showEditNotesModal = true;
    }

    public function saveNotes(): void
    {
        $this->validate([
            'editNotes' => 'nullable|string|max:1000',
        ]);

        Visitor::findOrFail($this->editingNotesId)->update([
            'notes' => $this->editNotes ?: null,
        ]);

        $this->reset('editingNotesId', 'editNotes', 'showEditNotesModal');

        Flux::toast(variant: 'success', text: 'Watchlist notes updated.');
    }

    public function confirmUnflag(string $id): void
    {
        $this->unflaggingVisitorId = $id;
        $this->showUnflagModal = true;
    }

    public function unflagVisitor(): void
    {
        Visitor::findOrFail($this->unflaggingVisitorId)->update([
            'is_flagged' => false,
        ]);

        $this->reset('unflaggingVisitorId', 'showUnflagModal');

        Flux::toast(variant: 'success', text: 'Visitor removed from watchlist.');
    }

    public function cancelUnflag(): void
    {
        $this->reset('unflaggingVisitorId', 'showUnflagModal');
    }

    public function updatedSelectAll(bool $value): void
    {
        $this->selected = $value ? $this->flaggedVisitors->pluck('id')->toArray() : [];
    }

    public function confirmBulkUnflag(): void
    {
        $this->showBulkUnflagModal = true;
    }

    public function bulkUnflag(): void
    {
        Visitor::whereIn('id', $this->selected)->update(['is_flagged' => false]);
        $this->selected = [];
        $this->selectAll = false;
        $this->showBulkUnflagModal = false;
        Flux::toast(variant: 'success', text: 'Selected visitors removed from watchlist.');
    }

    public function cancelBulkUnflag(): void
    {
        $this->showBulkUnflagModal = false;
    }

    public function openAddModal(): void
    {
        $this->reset('addSearch');
        $this->showAddModal = true;
    }

    public function flagVisitor(string $id): void
    {
        Visitor::findOrFail($id)->update([
            'is_flagged' => true,
            'notes' => 'Added to watchlist',
        ]);

        Flux::toast(variant: 'success', text: 'Visitor added to watchlist.');
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
    {{-- Header --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading size="lg">Watchlist</flux:heading>
            <flux:subheading>Manage flagged visitors and security alerts.</flux:subheading>
        </div>
        <flux:button variant="primary" wire:click="openAddModal" icon="plus">Add to Watchlist</flux:button>
    </div>

    {{-- Search --}}
    <div class="flex flex-wrap items-end gap-3">
        <div class="min-w-48 flex-1">
            <flux:input wire:model.live.debounce.300ms="search" placeholder="Search watchlist..." icon="magnifying-glass" />
        </div>
    </div>

    {{-- Bulk action bar --}}
    @if (count($this->selected) > 0)
        <div class="flex items-center justify-between rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 dark:border-blue-800 dark:bg-blue-900/20">
            <span class="text-sm font-medium text-blue-700 dark:text-blue-300">
                {{ count($this->selected) }} visitor{{ count($this->selected) > 1 ? 's' : '' }} selected
            </span>
            <div class="flex gap-2">
                <flux:button size="sm" variant="ghost" wire:click="$set('selected', [])">Clear</flux:button>
                <flux:button size="sm" variant="danger" icon="flag" wire:click="confirmBulkUnflag">Remove from Watchlist</flux:button>
            </div>
        </div>
    @endif

    {{-- Table --}}
    @if ($this->flaggedVisitors->isEmpty())
            <div class="flex flex-col items-center justify-center py-16 text-center">
                <div class="mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-neutral-100 dark:bg-neutral-800">
                    <svg class="h-6 w-6 text-neutral-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 21v-4m0 0V5a2 2 0 012-2h6.5l1 1H21l-3 6 3 6h-8.5l-1-1H5a2 2 0 00-2 2zm9-13.5V9"/></svg>
                </div>
                <p class="text-neutral-400 dark:text-neutral-500">
                    {{ $this->search ? 'No flagged visitors match your search.' : 'No visitors on the watchlist.' }}
                </p>
                @unless ($this->search)
                    <flux:button variant="ghost" wire:click="openAddModal" class="mt-3">Add a visitor to the watchlist</flux:button>
                @endunless
            </div>
        @else
            <flux:table :paginate="$this->flaggedVisitors">
                <flux:table.columns>
                    <flux:table.column class="w-10">
                        <input type="checkbox" wire:model.live="selectAll" class="rounded border-neutral-300 text-blue-600 focus:ring-blue-500 dark:border-neutral-600 dark:bg-neutral-800">
                    </flux:table.column>
                    <flux:table.column>Visitor</flux:table.column>
                    <flux:table.column class="hidden sm:table-cell">Contact</flux:table.column>
                    <flux:table.column class="hidden md:table-cell">Notes</flux:table.column>
                    <flux:table.column class="hidden lg:table-cell">Flagged</flux:table.column>
                    <flux:table.column align="end">Actions</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->flaggedVisitors as $visitor)
                        <flux:table.row :key="$visitor->id">
                            <flux:table.cell class="w-10">
                                <input type="checkbox" wire:model.live="selected" value="{{ $visitor->id }}" class="rounded border-neutral-300 text-blue-600 focus:ring-blue-500 dark:border-neutral-600 dark:bg-neutral-800">
                            </flux:table.cell>
                            <flux:table.cell variant="strong">
                                <div class="flex items-center gap-3">
                                    @if ($visitor->photo)
                                        <img src="{{ $visitor->photo }}" alt="" class="h-8 w-8 shrink-0 cursor-pointer rounded-full object-cover border border-neutral-200 transition-opacity hover:opacity-80 dark:border-neutral-700" x-on:click="window.dispatchEvent(new CustomEvent('preview-photo', { detail: '{{ $visitor->photo }}' }))">
                                    @else
                                        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-neutral-100 text-xs font-medium text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400">
                                            {{ substr($visitor->name, 0, 2) }}
                                        </div>
                                    @endif
                                    <div>
                                        <span>{{ $visitor->name }}</span>
                                        @if ($visitor->company)
                                            <span class="ml-1.5 text-xs text-zinc-500">{{ $visitor->company }}</span>
                                        @endif
                                        @if ($visitor->status === 'checked_in')
                                            <span class="ml-1.5 inline-flex items-center gap-1 rounded-full bg-amber-50 px-1.5 py-0.5 text-[10px] font-medium text-amber-600 dark:bg-amber-900/30 dark:text-amber-400">On-site</span>
                                        @endif
                                    </div>
                                </div>
                            </flux:table.cell>
                            <flux:table.cell class="hidden sm:table-cell">
                                <div>{{ $visitor->email ?: '—' }}</div>
                                <div class="text-xs text-zinc-500">{{ $visitor->phone ?: '—' }}</div>
                            </flux:table.cell>
                            <flux:table.cell class="hidden md:table-cell max-w-48 truncate">
                                {{ $visitor->notes ?: '—' }}
                            </flux:table.cell>
                            <flux:table.cell class="hidden lg:table-cell whitespace-nowrap">
                                {{ $visitor->updated_at->format('M j, Y') }}
                            </flux:table.cell>
                            <flux:table.cell align="end">
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" class="cursor-pointer" />
                                    <flux:menu>
                                        <flux:menu.item icon="eye" wire:click="viewVisitor('{{ $visitor->id }}')">
                                            View
                                        </flux:menu.item>
                                        <flux:menu.item icon="pencil" wire:click="openEditNotes('{{ $visitor->id }}')">
                                            Edit Notes
                                        </flux:menu.item>
                                        <flux:menu.separator />
                                        <flux:menu.item icon="flag" variant="danger" wire:click="confirmUnflag('{{ $visitor->id }}')">
                                            Remove from Watchlist
                                        </flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif

    {{-- View Modal --}}
    <flux:modal wire:model="showViewModal" name="view-flagged" class="min-w-sm">
        @if ($this->viewingVisitor)
            <flux:heading size="lg">Flagged Visitor Details</flux:heading>

            <div class="mt-6 space-y-4">
                @if ($this->viewingVisitor->photo)
                    <div class="flex justify-center">
                        <img src="{{ $this->viewingVisitor->photo }}" alt="" class="h-24 w-24 rounded-full object-cover border border-neutral-200 dark:border-neutral-700">
                    </div>
                @endif

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <flux:text variant="label">Name</flux:text>
                        <flux:text>{{ $this->viewingVisitor->name }}</flux:text>
                    </div>
                    <div>
                        <flux:text variant="label">Status</flux:text>
                        <flux:text>
                            @if ($this->viewingVisitor->status === 'checked_in')
                                <span class="inline-flex items-center gap-1 text-emerald-600 dark:text-emerald-400">On-site</span>
                            @else
                                <span class="text-neutral-500 dark:text-neutral-400">Checked Out</span>
                            @endif
                        </flux:text>
                    </div>
                    <div>
                        <flux:text variant="label">Email</flux:text>
                        <flux:text>{{ $this->viewingVisitor->email ?: '—' }}</flux:text>
                    </div>
                    <div>
                        <flux:text variant="label">Phone</flux:text>
                        <flux:text>{{ $this->viewingVisitor->phone ?: '—' }}</flux:text>
                    </div>
                    <div>
                        <flux:text variant="label">Company</flux:text>
                        <flux:text>{{ $this->viewingVisitor->company ?: '—' }}</flux:text>
                    </div>
                    <div>
                        <flux:text variant="label">Badge Number</flux:text>
                        <flux:text>{{ $this->viewingVisitor->badge_number ?: '—' }}</flux:text>
                    </div>
                    <div>
                        <flux:text variant="label">Host</flux:text>
                        <flux:text>{{ $this->viewingVisitor->host ?: '—' }}</flux:text>
                    </div>
                    <div>
                        <flux:text variant="label">Purpose</flux:text>
                        <flux:text>{{ $this->viewingVisitor->purpose ?: '—' }}</flux:text>
                    </div>
                </div>

                <div>
                    <flux:text variant="label">Notes</flux:text>
                    <flux:text class="mt-1 whitespace-pre-wrap">{{ $this->viewingVisitor->notes ?: 'None' }}</flux:text>
                </div>

                <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-900/20">
                    <div class="flex items-start gap-2">
                        <svg class="mt-0.5 h-4 w-4 shrink-0 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                        <p class="text-xs text-amber-700 dark:text-amber-400">This visitor is flagged on the watchlist.</p>
                    </div>
                </div>
            </div>

            <div class="mt-6 flex justify-end">
                <flux:button variant="ghost" x-on:click="$flux.modal('view-flagged').close()">Close</flux:button>
            </div>
        @endif
    </flux:modal>

    {{-- Edit Notes Modal --}}
    <flux:modal wire:model="showEditNotesModal" name="edit-notes" class="min-w-sm">
        <flux:heading size="lg">Edit Watchlist Notes</flux:heading>
        <flux:text class="mt-2">Update the notes for this flagged visitor.</flux:text>

        <div class="mt-6 space-y-4">
            <flux:textarea wire:model="editNotes" label="Notes" placeholder="Reason for flagging, remarks..." rows="4" />

            <div class="flex gap-2 pt-2">
                <flux:button variant="primary" class="flex-1 !py-3" wire:click="saveNotes">Save Notes</flux:button>
                <flux:button variant="ghost" x-on:click="$flux.modal('edit-notes').close()">Cancel</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Unflag Confirmation Modal --}}
    <flux:modal wire:model="showUnflagModal" name="unflag-visitor" class="min-w-sm">
        @if ($this->unflaggingVisitor)
            <flux:heading size="lg">Remove from Watchlist</flux:heading>
            <flux:text class="mt-2">
                Are you sure you want to remove <strong>{{ $this->unflaggingVisitor->name }}</strong> from the watchlist?
                They will no longer be flagged on check-in.
            </flux:text>

            <div class="mt-6 flex gap-2 justify-end">
                <flux:button variant="ghost" wire:click="cancelUnflag">Cancel</flux:button>
                <flux:button variant="danger" wire:click="unflagVisitor">Remove</flux:button>
            </div>
        @endif
    </flux:modal>

    {{-- Add to Watchlist Modal --}}
    <flux:modal wire:model="showAddModal" name="add-watchlist" class="min-w-sm">
        <flux:heading size="lg">Add to Watchlist</flux:heading>
        <flux:text class="mt-2">Search visitors to flag on the watchlist.</flux:text>

        <div class="mt-6 space-y-4">
            <flux:input wire:model.live.debounce.300ms="addSearch" placeholder="Search visitors..." icon="magnifying-glass" />

            @if ($this->unflaggedCandidates->isEmpty())
                <p class="py-4 text-center text-sm text-neutral-400 dark:text-neutral-500">
                    {{ $this->addSearch ? 'No visitors match your search.' : 'All visitors are already on the watchlist.' }}
                </p>
            @else
                <div class="max-h-64 space-y-1 overflow-y-auto">
                    @foreach ($this->unflaggedCandidates as $candidate)
                        <div class="flex items-center justify-between rounded-lg px-3 py-2 hover:bg-neutral-50 dark:hover:bg-neutral-800" wire:key="candidate-{{ $candidate->id }}">
                            <div class="flex items-center gap-2">
                                @if ($candidate->photo)
                                    <img src="{{ $candidate->photo }}" alt="" class="h-7 w-7 rounded-full object-cover">
                                @else
                                    <div class="flex h-7 w-7 items-center justify-center rounded-full bg-neutral-100 text-[10px] font-medium text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400">
                                        {{ substr($candidate->name, 0, 2) }}
                                    </div>
                                @endif
                                <div>
                                    <p class="text-sm font-medium text-neutral-900 dark:text-white">{{ $candidate->name }}</p>
                                    <p class="text-xs text-neutral-400">{{ $candidate->email ?: $candidate->phone ?: '—' }}</p>
                                </div>
                            </div>
                            <flux:button size="xs" variant="ghost" wire:click="flagVisitor('{{ $candidate->id }}')" icon="flag">
                                Flag
                            </flux:button>
                        </div>
                    @endforeach
                </div>

                @if ($this->unflaggedCandidates->hasPages())
                    <div class="pt-2">
                        <flux:pagination :paginator="$this->unflaggedCandidates" />
                    </div>
                @endif
            @endif
        </div>
    </flux:modal>

    {{-- Bulk Unflag Confirmation Modal --}}
    <flux:modal wire:model="showBulkUnflagModal" name="bulk-unflag-watchlist" class="min-w-sm">
        <flux:heading size="lg">Remove {{ count($this->selected) }} Visitor{{ count($this->selected) > 1 ? 's' : '' }} from Watchlist</flux:heading>
        <flux:text class="mt-2">
            Are you sure you want to remove {{ count($this->selected) }} selected visitor{{ count($this->selected) > 1 ? 's' : '' }} from the watchlist?
            They will no longer be flagged on check-in.
        </flux:text>

        <div class="mt-6 flex gap-2 justify-end">
            <flux:button variant="ghost" wire:click="cancelBulkUnflag">
                Cancel
            </flux:button>
            <flux:button variant="danger" wire:click="bulkUnflag">
                Remove {{ count($this->selected) }} Visitor{{ count($this->selected) > 1 ? 's' : '' }}
            </flux:button>
        </div>
    </flux:modal>
</div>