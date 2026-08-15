<?php

use App\Models\PreRegistration;
use App\Models\User;
use App\Notifications\VisitorPreRegistered;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Pre-Registrations')] #[Layout('layouts::app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $statusFilter = '';

    public bool $showCreateModal = false;
    public bool $showEditModal = false;
    public bool $showViewModal = false;
    public bool $showCancelModal = false;
    public bool $showDeleteModal = false;

    public ?string $editingId = null;
    public ?string $viewingId = null;
    public ?string $cancellingId = null;
    public ?string $deletingId = null;

    public string $createName = '';
    public string $createEmail = '';
    public string $createPhone = '';
    public string $createCompany = '';
    public string $createHost = '';
    public ?string $createHostUserId = null;
    public string $createPurpose = '';
    public string $createExpectedDate = '';

    public string $editName = '';
    public string $editEmail = '';
    public string $editPhone = '';
    public string $editCompany = '';
    public string $editHost = '';
    public ?string $editHostUserId = null;
    public string $editPurpose = '';
    public string $editExpectedDate = '';
    public string $editNotes = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function preRegistrations(): LengthAwarePaginator
    {
        return PreRegistration::query()
            ->when($this->search, fn ($q) => $q->search($this->search))
            ->when($this->statusFilter, fn ($q) => $q->status($this->statusFilter))
            ->orderByDesc('created_at')
            ->paginate(15);
    }

    #[Computed]
    public function hostUsers(): Collection
    {
        return User::orderBy('name')->get();
    }

    public function setStatusFilter(string $status): void
    {
        $this->statusFilter = $status;
        $this->resetPage();
    }

    public function openCreateModal(): void
    {
        $this->reset('createName', 'createEmail', 'createPhone', 'createCompany', 'createHost', 'createHostUserId', 'createPurpose', 'createExpectedDate');
        $this->showCreateModal = true;
    }

    public function createPreRegistration(): void
    {
        $this->validate([
            'createName' => 'required|string|max:255',
            'createEmail' => 'nullable|email|max:255',
            'createPhone' => 'nullable|string|max:20',
            'createCompany' => 'nullable|string|max:255',
            'createHost' => 'nullable|string|max:255',
            'createPurpose' => 'nullable|string|max:255',
            'createExpectedDate' => 'nullable|date',
        ]);

        $preRegistration = PreRegistration::create([
            'name' => $this->createName,
            'email' => $this->createEmail ?: null,
            'phone' => $this->createPhone ?: null,
            'company' => $this->createCompany ?: null,
            'host' => $this->createHost ?: null,
            'host_user_id' => $this->createHostUserId,
            'purpose' => $this->createPurpose ?: null,
            'expected_date' => $this->createExpectedDate ?: null,
            'status' => 'pending',
        ]);

        if ($this->createHostUserId && $hostUser = User::find($this->createHostUserId)) {
            $hostUser->notify(new VisitorPreRegistered($preRegistration));
        }

        $this->reset('createName', 'createEmail', 'createPhone', 'createCompany', 'createHost', 'createHostUserId', 'createPurpose', 'createExpectedDate', 'showCreateModal');

        Flux::toast(variant: 'success', text: 'Pre-registration created successfully.');
    }

    public function openEditModal(string $id): void
    {
        $pre = PreRegistration::findOrFail($id);
        $this->editingId = $id;
        $this->editName = $pre->name;
        $this->editEmail = $pre->email ?? '';
        $this->editPhone = $pre->phone ?? '';
        $this->editCompany = $pre->company ?? '';
        $this->editHost = $pre->host ?? '';
        $this->editHostUserId = $pre->host_user_id;
        $this->editPurpose = $pre->purpose ?? '';
        $this->editExpectedDate = $pre->expected_date?->format('Y-m-d') ?? '';
        $this->editNotes = $pre->notes ?? '';
        $this->showEditModal = true;
    }

    public function updatePreRegistration(): void
    {
        $this->validate([
            'editName' => 'required|string|max:255',
            'editEmail' => 'nullable|email|max:255',
            'editPhone' => 'nullable|string|max:20',
            'editCompany' => 'nullable|string|max:255',
            'editHost' => 'nullable|string|max:255',
            'editPurpose' => 'nullable|string|max:255',
            'editExpectedDate' => 'nullable|date',
        ]);

        PreRegistration::findOrFail($this->editingId)->update([
            'name' => $this->editName,
            'email' => $this->editEmail ?: null,
            'phone' => $this->editPhone ?: null,
            'company' => $this->editCompany ?: null,
            'host' => $this->editHost ?: null,
            'host_user_id' => $this->editHostUserId,
            'purpose' => $this->editPurpose ?: null,
            'expected_date' => $this->editExpectedDate ?: null,
            'notes' => $this->editNotes ?: null,
        ]);

        $this->reset('editingId', 'editName', 'editEmail', 'editPhone', 'editCompany', 'editHost', 'editHostUserId', 'editPurpose', 'editExpectedDate', 'editNotes', 'showEditModal');

        Flux::toast(variant: 'success', text: 'Pre-registration updated successfully.');
    }

    public function viewPreRegistration(string $id): void
    {
        $this->viewingId = $id;
        $this->showViewModal = true;
    }

    public function confirmCancel(string $id): void
    {
        $this->cancellingId = $id;
        $this->showCancelModal = true;
    }

    public function cancelPreRegistration(): void
    {
        PreRegistration::findOrFail($this->cancellingId)->update(['status' => 'cancelled']);

        $this->reset('cancellingId', 'showCancelModal');

        Flux::toast(variant: 'success', text: 'Pre-registration cancelled.');
    }

    public function dismissCancel(): void
    {
        $this->reset('cancellingId', 'showCancelModal');
    }

    public function confirmDelete(string $id): void
    {
        $this->deletingId = $id;
        $this->showDeleteModal = true;
    }

    public function deletePreRegistration(): void
    {
        PreRegistration::findOrFail($this->deletingId)->delete();

        $this->reset('deletingId', 'showDeleteModal');

        Flux::toast(variant: 'success', text: 'Pre-registration deleted successfully.');
    }

    public function dismissDelete(): void
    {
        $this->reset('deletingId', 'showDeleteModal');
    }

    #[Computed]
    public function viewingPreRegistration(): ?PreRegistration
    {
        if ($this->viewingId === null) {
            return null;
        }

        return PreRegistration::with('hostUser')->find($this->viewingId);
    }

    #[Computed]
    public function cancellingPreRegistration(): ?PreRegistration
    {
        if ($this->cancellingId === null) {
            return null;
        }

        return PreRegistration::find($this->cancellingId);
    }

    #[Computed]
    public function deletingPreRegistration(): ?PreRegistration
    {
        if ($this->deletingId === null) {
            return null;
        }

        return PreRegistration::find($this->deletingId);
    }

    public function statusBadgeClasses(string $status): string
    {
        return match ($status) {
            'pending' => 'bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
            'used' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
            'cancelled' => 'bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-400',
            default => 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400',
        };
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            'pending' => 'Pending',
            'used' => 'Used',
            'cancelled' => 'Cancelled',
            default => ucfirst($status),
        };
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
    {{-- Header --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading size="lg">Pre-Registrations</flux:heading>
            <flux:subheading>Manage visits booked ahead of time.</flux:subheading>
        </div>
        <flux:button variant="primary" wire:click="openCreateModal" icon="plus">Add Pre-Registration</flux:button>
    </div>

    {{-- Search + filters --}}
    <div class="flex flex-wrap items-end gap-3">
        <div class="min-w-48 flex-1">
            <flux:input wire:model.live.debounce.300ms="search" placeholder="Search by name, email, company, or host..." icon="magnifying-glass" />
        </div>
    </div>

    <div class="flex gap-1">
        @foreach (['' => 'All', 'pending' => 'Pending', 'used' => 'Used', 'cancelled' => 'Cancelled'] as $value => $label)
            <flux:button
                size="sm"
                variant="{{ $this->statusFilter === $value ? 'primary' : 'ghost' }}"
                wire:click="setStatusFilter('{{ $value }}')"
            >
                {{ $label }}
            </flux:button>
        @endforeach
    </div>

    {{-- Table --}}
    @if ($this->preRegistrations->isEmpty())
            <div class="flex flex-col items-center justify-center py-16 text-center">
                <p class="text-neutral-400 dark:text-neutral-500">
                    {{ $this->search || $this->statusFilter ? 'No pre-registrations match your filters.' : 'No pre-registrations yet.' }}
                </p>
            </div>
        @else
            <flux:table :paginate="$this->preRegistrations">
                <flux:table.columns>
                    <flux:table.column>Name</flux:table.column>
                    <flux:table.column class="hidden sm:table-cell">Host</flux:table.column>
                    <flux:table.column class="hidden md:table-cell">Purpose</flux:table.column>
                    <flux:table.column class="hidden lg:table-cell">Expected</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column class="hidden md:table-cell">QR</flux:table.column>
                    <flux:table.column class="hidden xl:table-cell">Created</flux:table.column>
                    <flux:table.column align="end">Actions</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->preRegistrations as $pre)
                        <flux:table.row :key="$pre->id">
                            <flux:table.cell variant="strong">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-neutral-100 text-xs font-medium text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400">
                                        {{ substr($pre->name, 0, 2) }}
                                    </div>
                                    <div>
                                        <span>{{ $pre->name }}</span>
                                        @if ($pre->company)
                                            <div class="text-xs text-zinc-500">{{ $pre->company }}</div>
                                        @endif
                                    </div>
                                </div>
                            </flux:table.cell>
                            <flux:table.cell class="hidden sm:table-cell">{{ $pre->host ?: '—' }}</flux:table.cell>
                            <flux:table.cell class="hidden md:table-cell max-w-48 truncate">{{ $pre->purpose ?: '—' }}</flux:table.cell>
                            <flux:table.cell class="hidden lg:table-cell">{{ $pre->expected_date?->format('M j, Y') ?: '—' }}</flux:table.cell>
                            <flux:table.cell class="py-0">
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $this->statusBadgeClasses($pre->status) }}">
                                    {{ $this->statusLabel($pre->status) }}
                                </span>
                            </flux:table.cell>
                            <flux:table.cell class="hidden md:table-cell">
                                @if ($pre->qr_code_token)
                                    <img src="{{ route('qr.code', $pre->qr_code_token) }}" alt="QR Code" class="h-10 w-10 rounded border border-neutral-200 object-cover dark:border-neutral-700">
                                @else
                                    <span class="text-neutral-300 dark:text-neutral-600">—</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="hidden xl:table-cell">{{ $pre->created_at->format('M j, Y') }}</flux:table.cell>
                            <flux:table.cell align="end">
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" class="cursor-pointer" />
                                    <flux:menu>
                                        <flux:menu.item icon="eye" wire:click="viewPreRegistration('{{ $pre->id }}')">
                                            View
                                        </flux:menu.item>
                                        <flux:menu.item icon="pencil" wire:click="openEditModal('{{ $pre->id }}')">
                                            Edit
                                        </flux:menu.item>
                                        @if ($pre->status === 'pending')
                                            <flux:menu.separator />
                                            <flux:menu.item icon="x-circle" wire:click="confirmCancel('{{ $pre->id }}')" class="text-amber-500">
                                                Cancel
                                            </flux:menu.item>
                                        @endif
                                        <flux:menu.separator />
                                        <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete('{{ $pre->id }}')">
                                            Delete
                                        </flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif

    {{-- Create Modal --}}
    <flux:modal wire:model="showCreateModal" name="create-pre-registration" class="min-w-sm">
        <flux:heading size="lg">Add Pre-Registration</flux:heading>
        <flux:text class="mt-2">Book a visit ahead of time for a visitor.</flux:text>

        <div class="mt-6 space-y-4">
            <flux:input wire:model="createName" label="Full Name" type="text" required placeholder="e.g. John Doe" />
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <flux:input wire:model="createEmail" label="Email" type="email" placeholder="e.g. john@example.com" />
                <flux:input wire:model="createPhone" label="Phone" type="text" placeholder="e.g. +1 555-0123" />
            </div>
            <flux:input wire:model="createCompany" label="Company" type="text" placeholder="e.g. Acme Corp" />
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <flux:select wire:model="createHostUserId" label="Host">
                        <option value="">— No host —</option>
                        @foreach ($this->hostUsers as $user)
                            <option value="{{ $user->id }}">{{ $user->name }}</option>
                        @endforeach
                    </flux:select>
                </div>
                <flux:input wire:model="createHost" label="Host name (custom)" placeholder="e.g. Sarah Johnson" />
            </div>
            <flux:input wire:model="createPurpose" label="Purpose" placeholder="e.g. Meeting, Interview" />
            <x-date-picker wire:model="createExpectedDate" label="Expected date (optional)" />

            <div class="flex gap-2 pt-2">
                <flux:button variant="primary" class="flex-1 !py-3" wire:click="createPreRegistration">
                    Create Pre-Registration
                </flux:button>
                <flux:button variant="ghost" x-on:click="$flux.modal('create-pre-registration').close()">
                    Cancel
                </flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Edit Modal --}}
    <flux:modal wire:model="showEditModal" name="edit-pre-registration" class="min-w-sm">
        <flux:heading size="lg">Edit Pre-Registration</flux:heading>
        <flux:text class="mt-2">Update the pre-registered visit details.</flux:text>

        <div class="mt-6 space-y-4">
            <flux:input wire:model="editName" label="Full Name" type="text" required placeholder="e.g. John Doe" />
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <flux:input wire:model="editEmail" label="Email" type="email" placeholder="e.g. john@example.com" />
                <flux:input wire:model="editPhone" label="Phone" type="text" placeholder="e.g. +1 555-0123" />
            </div>
            <flux:input wire:model="editCompany" label="Company" type="text" placeholder="e.g. Acme Corp" />
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <flux:select wire:model="editHostUserId" label="Host">
                        <option value="">— No host —</option>
                        @foreach ($this->hostUsers as $user)
                            <option value="{{ $user->id }}">{{ $user->name }}</option>
                        @endforeach
                    </flux:select>
                </div>
                <flux:input wire:model="editHost" label="Host name (custom)" placeholder="e.g. Sarah Johnson" />
            </div>
            <flux:input wire:model="editPurpose" label="Purpose" placeholder="e.g. Meeting, Interview" />
            <x-date-picker wire:model="editExpectedDate" label="Expected date (optional)" />
            <flux:textarea wire:model="editNotes" label="Notes" placeholder="Internal notes..." rows="3" />

            <div class="flex gap-2 pt-2">
                <flux:button variant="primary" class="flex-1 !py-3" wire:click="updatePreRegistration">
                    Save Changes
                </flux:button>
                <flux:button variant="ghost" x-on:click="$flux.modal('edit-pre-registration').close()">
                    Cancel
                </flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- View Modal --}}
    <flux:modal wire:model="showViewModal" name="view-pre-registration" class="min-w-sm">
        @if ($this->viewingPreRegistration)
            <flux:heading size="lg">Pre-Registration Details</flux:heading>

            <div class="mt-6 space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <flux:text variant="label">Name</flux:text>
                        <flux:text>{{ $this->viewingPreRegistration->name }}</flux:text>
                    </div>
                    <div>
                        <flux:text variant="label">Status</flux:text>
                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium mt-1 {{ $this->statusBadgeClasses($this->viewingPreRegistration->status) }}">
                            {{ $this->statusLabel($this->viewingPreRegistration->status) }}
                        </span>
                    </div>
                    <div>
                        <flux:text variant="label">Email</flux:text>
                        <flux:text>{{ $this->viewingPreRegistration->email ?: '—' }}</flux:text>
                    </div>
                    <div>
                        <flux:text variant="label">Phone</flux:text>
                        <flux:text>{{ $this->viewingPreRegistration->phone ?: '—' }}</flux:text>
                    </div>
                    <div>
                        <flux:text variant="label">Company</flux:text>
                        <flux:text>{{ $this->viewingPreRegistration->company ?: '—' }}</flux:text>
                    </div>
                    <div>
                        <flux:text variant="label">Host</flux:text>
                        <flux:text>{{ $this->viewingPreRegistration->host ?: '—' }}</flux:text>
                    </div>
                    <div>
                        <flux:text variant="label">Purpose</flux:text>
                        <flux:text>{{ $this->viewingPreRegistration->purpose ?: '—' }}</flux:text>
                    </div>
                    <div>
                        <flux:text variant="label">Expected date</flux:text>
                        <flux:text>{{ $this->viewingPreRegistration->expected_date?->format('M j, Y') ?: '—' }}</flux:text>
                    </div>
                </div>

                @if ($this->viewingPreRegistration->valid_id_photo)
                    <div>
                        <flux:text variant="label">Valid ID</flux:text>
                        <img src="{{ $this->viewingPreRegistration->valid_id_photo }}" alt="Valid ID" class="mt-1 h-32 w-48 rounded-lg border border-neutral-200 object-cover dark:border-neutral-700">
                    </div>
                @endif

                @if ($this->viewingPreRegistration->notes)
                    <div>
                        <flux:text variant="label">Notes</flux:text>
                        <flux:text class="mt-1 whitespace-pre-wrap">{{ $this->viewingPreRegistration->notes }}</flux:text>
                    </div>
                @endif

                @if ($this->viewingPreRegistration->status === 'pending' && $this->viewingPreRegistration->qr_code_token)
                    <div class="pt-2 text-center">
                        <flux:text variant="label">QR Code</flux:text>
                        <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">Scan this at the kiosk to pre-fill check-in.</p>
                        <div class="mx-auto mt-3 inline-block rounded-xl border border-neutral-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900">
                            <img src="{{ route('qr.code', $this->viewingPreRegistration->qr_code_token) }}" alt="Pre-registration QR Code" class="h-36 w-36">
                        </div>
                    </div>
                @endif
            </div>

            <div class="mt-6 flex justify-end">
                <flux:button variant="ghost" x-on:click="$flux.modal('view-pre-registration').close()">
                    Close
                </flux:button>
            </div>
        @endif
    </flux:modal>

    {{-- Cancel Confirmation Modal --}}
    <flux:modal wire:model="showCancelModal" name="cancel-pre-registration" class="min-w-sm">
        @if ($this->cancellingPreRegistration)
            <flux:heading size="lg">Cancel Pre-Registration</flux:heading>
            <flux:text class="mt-2">
                Are you sure you want to cancel <strong>{{ $this->cancellingPreRegistration->name }}</strong>'s pre-registration? The record will be kept as cancelled.
            </flux:text>

            <div class="mt-6 flex gap-2 justify-end">
                <flux:button variant="ghost" wire:click="dismissCancel">
                    Cancel
                </flux:button>
                <flux:button variant="danger" wire:click="cancelPreRegistration">
                    Cancel Pre-Registration
                </flux:button>
            </div>
        @endif
    </flux:modal>

    {{-- Delete Confirmation Modal --}}
    <flux:modal wire:model="showDeleteModal" name="delete-pre-registration" class="min-w-sm">
        @if ($this->deletingPreRegistration)
            <flux:heading size="lg">Delete Pre-Registration</flux:heading>
            <flux:text class="mt-2">
                Are you sure you want to delete <strong>{{ $this->deletingPreRegistration->name }}</strong>'s pre-registration? This action cannot be undone.
            </flux:text>

            <div class="mt-6 flex gap-2 justify-end">
                <flux:button variant="ghost" wire:click="dismissDelete">
                    Cancel
                </flux:button>
                <flux:button variant="danger" wire:click="deletePreRegistration">
                    Delete
                </flux:button>
            </div>
        @endif
    </flux:modal>
</div>
