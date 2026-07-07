<?php

use App\Models\User;
use Flux\Flux;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('User Management')] #[Layout('layouts::app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $sortField = 'name';
    public string $sortDirection = 'asc';

    public bool $showCreateModal = false;
    public bool $showEditModal = false;
    public bool $showDeleteModal = false;

    public ?string $editingUserId = null;
    public ?string $deletingUserId = null;

    public string $createName = '';
    public string $createEmail = '';
    public string $createPassword = '';
    public string $createPasswordConfirmation = '';

    public string $editName = '';
    public string $editEmail = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function sortBy(string $field): void
    {
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }

        $this->resetPage();
    }

    #[Computed]
    public function users(): LengthAwarePaginator
    {
        return User::when($this->search, fn ($q) => $q->whereAny(['name', 'email'], 'like', '%' . $this->search . '%'))
            ->orderBy($this->sortField, $this->sortDirection)
            ->paginate(25);
    }

    public function openCreateModal(): void
    {
        $this->reset('createName', 'createEmail', 'createPassword', 'createPasswordConfirmation');
        $this->showCreateModal = true;
    }

    public function createUser(): void
    {
        $this->validate([
            'createName' => 'required|string|max:255',
            'createEmail' => 'required|email|max:255|unique:users,email',
            'createPassword' => 'required|string|min:8|same:createPasswordConfirmation',
        ]);

        User::create([
            'name' => $this->createName,
            'email' => $this->createEmail,
            'password' => bcrypt($this->createPassword),
        ]);

        $this->reset('createName', 'createEmail', 'createPassword', 'createPasswordConfirmation', 'showCreateModal');

        Flux::toast(variant: 'success', text: 'User created successfully.');
    }

    public function openEditModal(string $id): void
    {
        $user = User::findOrFail($id);
        $this->editingUserId = $id;
        $this->editName = $user->name;
        $this->editEmail = $user->email;
        $this->showEditModal = true;
    }

    public function updateUser(): void
    {
        $this->validate([
            'editName' => 'required|string|max:255',
            'editEmail' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->editingUserId)],
        ]);

        User::findOrFail($this->editingUserId)->update([
            'name' => $this->editName,
            'email' => $this->editEmail,
        ]);

        $this->reset('editingUserId', 'editName', 'editEmail', 'showEditModal');

        Flux::toast(variant: 'success', text: 'User updated successfully.');
    }

    public function confirmDelete(string $id): void
    {
        $this->deletingUserId = $id;
        $this->showDeleteModal = true;
    }

    public function deleteUser(): void
    {
        $user = User::findOrFail($this->deletingUserId);

        if ($user->id === auth()->id()) {
            Flux::toast(variant: 'danger', text: 'You cannot delete your own account.');
            $this->reset('deletingUserId', 'showDeleteModal');

            return;
        }

        $user->delete();

        $this->reset('deletingUserId', 'showDeleteModal');

        Flux::toast(variant: 'success', text: 'User deleted successfully.');
    }

    public function cancelDelete(): void
    {
        $this->reset('deletingUserId', 'showDeleteModal');
    }

    #[Computed]
    public function deletingUser(): ?User
    {
        if ($this->deletingUserId === null) {
            return null;
        }

        return User::find($this->deletingUserId);
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
    {{-- Header --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading size="lg">User Management</flux:heading>
            <flux:subheading>Manage system users and their access.</flux:subheading>
        </div>
        <flux:button variant="primary" wire:click="openCreateModal" icon="plus">Add User</flux:button>
    </div>

    {{-- Filters --}}
    <div class="flex flex-wrap items-end gap-3">
        <div class="min-w-48 flex-1">
            <flux:input wire:model.live.debounce.300ms="search" placeholder="Search by name or email..." icon="magnifying-glass" />
        </div>
    </div>

    {{-- Table --}}
    <div class="overflow-hidden rounded-xl border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
        @if ($this->users->isEmpty())
            <div class="flex flex-col items-center justify-center py-16 text-center">
                <p class="text-neutral-400 dark:text-neutral-500">
                    {{ $this->search ? 'No users match your search.' : 'No users found.' }}
                </p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-neutral-100 text-neutral-500 dark:border-neutral-800 dark:text-neutral-400">
                            <th class="px-5 py-3 font-medium cursor-pointer hover:text-neutral-700 dark:hover:text-neutral-300" wire:click="sortBy('name')">
                                <div class="flex items-center gap-1">
                                    Name
                                    @if ($this->sortField === 'name')
                                        <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/></svg>
                                    @endif
                                </div>
                            </th>
                            <th class="px-5 py-3 font-medium cursor-pointer hover:text-neutral-700 dark:hover:text-neutral-300" wire:click="sortBy('email')">
                                <div class="flex items-center gap-1">
                                    Email
                                    @if ($this->sortField === 'email')
                                        <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/></svg>
                                    @endif
                                </div>
                            </th>
                            <th class="px-5 py-3 font-medium hidden sm:table-cell">Verified</th>
                            <th class="px-5 py-3 font-medium hidden md:table-cell cursor-pointer hover:text-neutral-700 dark:hover:text-neutral-300" wire:click="sortBy('created_at')">
                                <div class="flex items-center gap-1">
                                    Created
                                    @if ($this->sortField === 'created_at')
                                        <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/></svg>
                                    @endif
                                </div>
                            </th>
                            <th class="px-5 py-3 font-medium text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        @foreach ($this->users as $user)
                            <tr class="group" wire:key="{{ $user->id }}">
                                <td class="px-5 py-3">
                                    <div class="flex items-center gap-3">
                                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-neutral-100 text-xs font-medium text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400">
                                            {{ $user->initials() }}
                                        </div>
                                        <div>
                                            <span class="font-medium text-neutral-900 dark:text-white">{{ $user->name }}</span>
                                            @if ($user->id === auth()->id())
                                                <span class="ml-1.5 inline-flex items-center rounded-full bg-blue-50 px-1.5 py-0.5 text-[10px] font-medium text-blue-600 dark:bg-blue-900/30 dark:text-blue-400">You</span>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="px-5 py-3 text-neutral-600 dark:text-neutral-300">{{ $user->email }}</td>
                                <td class="px-5 py-3 hidden sm:table-cell">
                                    @if ($user->email_verified_at)
                                        <span class="inline-flex items-center gap-1 text-emerald-600 dark:text-emerald-400">
                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                            Verified
                                        </span>
                                    @else
                                        <span class="text-neutral-400 dark:text-neutral-500">Unverified</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-neutral-500 dark:text-neutral-400 hidden md:table-cell">{{ $user->created_at->format('M j, Y') }}</td>
                                <td class="px-5 py-3 text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        <flux:button size="sm" variant="ghost" wire:click="openEditModal('{{ $user->id }}')" icon="pencil" class="opacity-0 group-hover:opacity-100 transition-opacity" />
                                        <flux:button size="sm" variant="ghost" wire:click="confirmDelete('{{ $user->id }}')" icon="trash" class="opacity-0 group-hover:opacity-100 transition-opacity text-red-500 hover:text-red-700 dark:hover:text-red-400" />
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="border-t border-neutral-100 px-5 py-3 dark:border-neutral-800">
                {{ $this->users->links() }}
            </div>
        @endif
    </div>

    {{-- Create User Modal --}}
    <flux:modal wire:model="showCreateModal" name="create-user" class="min-w-sm">
        <flux:heading size="lg">Add User</flux:heading>
        <flux:text class="mt-2">Create a new system user. They will receive login access immediately.</flux:text>

        <div class="mt-6 space-y-4">
            <flux:input wire:model="createName" label="Full Name" type="text" required placeholder="e.g. Jane Smith" />
            <flux:input wire:model="createEmail" label="Email" type="email" required placeholder="e.g. jane@example.com" />
            <flux:input wire:model="createPassword" label="Password" type="password" required placeholder="Min. 8 characters" />
            <flux:input wire:model="createPasswordConfirmation" label="Confirm Password" type="password" required placeholder="Repeat password" />

            <div class="flex gap-2 pt-2">
                <flux:button variant="primary" class="flex-1 !py-3" wire:click="createUser">
                    Create User
                </flux:button>
                <flux:button variant="ghost" x-on:click="$flux.modal('create-user').close()">
                    Cancel
                </flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Edit User Modal --}}
    <flux:modal wire:model="showEditModal" name="edit-user" class="min-w-sm">
        <flux:heading size="lg">Edit User</flux:heading>
        <flux:text class="mt-2">Update user name and email address.</flux:text>

        <div class="mt-6 space-y-4">
            <flux:input wire:model="editName" label="Full Name" type="text" required placeholder="e.g. Jane Smith" />
            <flux:input wire:model="editEmail" label="Email" type="email" required placeholder="e.g. jane@example.com" />

            <div class="flex gap-2 pt-2">
                <flux:button variant="primary" class="flex-1 !py-3" wire:click="updateUser">
                    Save Changes
                </flux:button>
                <flux:button variant="ghost" x-on:click="$flux.modal('edit-user').close()">
                    Cancel
                </flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Delete User Confirmation Modal --}}
    <flux:modal wire:model="showDeleteModal" name="delete-user" class="min-w-sm">
        @if ($this->deletingUser)
            <flux:heading size="lg">Delete User</flux:heading>
            <flux:text class="mt-2">
                Are you sure you want to delete <strong>{{ $this->deletingUser->name }}</strong>?
                This action cannot be undone.
            </flux:text>

            @if ($this->deletingUser->email_verified_at)
                <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-900/20">
                    <div class="flex items-start gap-2">
                        <svg class="mt-0.5 h-4 w-4 shrink-0 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                        <p class="text-xs text-amber-700 dark:text-amber-400">
                            This user has an active account. Deleting will remove all their data and access.
                        </p>
                    </div>
                </div>
            @endif

            <div class="mt-6 flex gap-2 justify-end">
                <flux:button variant="ghost" wire:click="cancelDelete">
                    Cancel
                </flux:button>
                <flux:button variant="danger" wire:click="deleteUser">
                    Delete User
                </flux:button>
            </div>
        @endif
    </flux:modal>
</div>
