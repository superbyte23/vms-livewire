<?php

use App\Models\Visitor;
use Flux\Flux;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Title('Visitors')] #[Layout('layouts::app')] class extends Component {
    use WithFileUploads, WithPagination;

    public string $search = '';
    public array $selected = [];
    public bool $selectAll = false;

    public bool $showCreateModal = false;
    public bool $showEditModal = false;
    public bool $showViewModal = false;
    public bool $showDeleteModal = false;
    public bool $showBulkDeleteModal = false;
    public bool $showClearAllModal = false;
    public string $clearAllConfirm = '';

    public ?string $editingVisitorId = null;
    public ?string $viewingVisitorId = null;
    public ?string $deletingVisitorId = null;

    public string $createName = '';
    public string $createEmail = '';
    public string $createPhone = '';
    public string $createCompany = '';
    public string $createValidIdNumber = '';

    public string $editName = '';
    public string $editEmail = '';
    public string $editPhone = '';
    public string $editCompany = '';
    public string $editValidIdNumber = '';
    public string $editNotes = '';
    public string $editPhotoDataUri = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function visitors(): LengthAwarePaginator
    {
        return Visitor::when($this->search, fn ($q) => $q->search($this->search))
            ->orderByDesc('created_at')
            ->paginate(15);
    }

    public function openCreateModal(): void
    {
        $this->reset('createName', 'createEmail', 'createPhone', 'createCompany', 'createValidIdNumber');
        $this->showCreateModal = true;
    }

    public function createVisitor(): void
    {
        $this->validate([
            'createName' => 'required|string|max:255',
            'createEmail' => 'nullable|email|max:255',
            'createPhone' => 'nullable|string|max:50',
            'createCompany' => 'nullable|string|max:255',
            'createValidIdNumber' => 'nullable|string|max:255',
        ]);

        Visitor::create([
            'name' => $this->createName,
            'email' => $this->createEmail,
            'phone' => $this->createPhone,
            'company' => $this->createCompany,
            'valid_id_number' => $this->createValidIdNumber,
        ]);

        $this->reset('createName', 'createEmail', 'createPhone', 'createCompany', 'createValidIdNumber', 'showCreateModal');

        Flux::toast(variant: 'success', text: 'Visitor created successfully.');
    }

    public function openEditModal(string $id): void
    {
        $visitor = Visitor::findOrFail($id);
        $this->editingVisitorId = $id;
        $this->editName = $visitor->name;
        $this->editEmail = $visitor->email ?? '';
        $this->editPhone = $visitor->phone ?? '';
        $this->editCompany = $visitor->company ?? '';
        $this->editValidIdNumber = $visitor->valid_id_number ?? '';
        $this->editNotes = $visitor->notes ?? '';
        $this->editPhotoDataUri = '';
        $this->showEditModal = true;
    }

    public function updateVisitor(): void
    {
        $this->validate([
            'editName' => 'required|string|max:255',
            'editEmail' => 'nullable|email|max:255',
            'editPhone' => 'nullable|string|max:50',
            'editCompany' => 'nullable|string|max:255',
            'editValidIdNumber' => 'nullable|string|max:255',
        ]);

        $data = [
            'name' => $this->editName,
            'email' => $this->editEmail ?: null,
            'phone' => $this->editPhone ?: null,
            'company' => $this->editCompany ?: null,
            'valid_id_number' => $this->editValidIdNumber ?: null,
            'notes' => $this->editNotes ?: null,
        ];

        if ($this->editPhotoDataUri) {
            $data['photo'] = $this->editPhotoDataUri;
        }

        Visitor::findOrFail($this->editingVisitorId)->update($data);

        $this->reset('editingVisitorId', 'editName', 'editEmail', 'editPhone', 'editCompany', 'editValidIdNumber', 'editNotes', 'editPhotoDataUri', 'showEditModal');

        Flux::toast(variant: 'success', text: 'Visitor updated successfully.');
    }

    public function viewVisitor(string $id): void
    {
        $this->viewingVisitorId = $id;
        $this->showViewModal = true;
    }

    public function confirmDelete(string $id): void
    {
        $this->deletingVisitorId = $id;
        $this->showDeleteModal = true;
    }

    public function deleteVisitor(): void
    {
        $visitor = Visitor::findOrFail($this->deletingVisitorId);
        $visitor->delete();

        $this->reset('deletingVisitorId', 'showDeleteModal');

        Flux::toast(variant: 'success', text: 'Visitor deleted successfully.');
    }

    public function cancelDelete(): void
    {
        $this->reset('deletingVisitorId', 'showDeleteModal');
    }

    public function updatedSelectAll(bool $value): void
    {
        $this->selected = $value ? $this->visitors->pluck('id')->toArray() : [];
    }

    public function confirmBulkDelete(): void
    {
        $this->showBulkDeleteModal = true;
    }

    public function bulkDelete(): void
    {
        Visitor::whereIn('id', $this->selected)->delete();
        $this->selected = [];
        $this->selectAll = false;
        $this->showBulkDeleteModal = false;
        Flux::toast(variant: 'success', text: 'Selected visitors deleted successfully.');
    }

    public function cancelBulkDelete(): void
    {
        $this->showBulkDeleteModal = false;
    }

    public function confirmClearAll(): void
    {
        $this->showClearAllModal = true;
    }

    public function clearAllVisitors(): void
    {
        if ($this->clearAllConfirm !== 'CLEAR') {
            Flux::toast(variant: 'danger', text: 'Please type CLEAR to confirm.');

            return;
        }

        Visitor::query()->delete();
        $this->selected = [];
        $this->selectAll = false;
        $this->clearAllConfirm = '';
        $this->showClearAllModal = false;
        Flux::toast(variant: 'success', text: 'All visitor data has been cleared.');
    }

    public function cancelClearAll(): void
    {
        $this->clearAllConfirm = '';
        $this->showClearAllModal = false;
    }

    public function toggleFlag(string $id): void
    {
        $visitor = Visitor::findOrFail($id);
        $visitor->update(['is_flagged' => !$visitor->is_flagged]);

        Flux::toast(
            variant: 'success',
            text: $visitor->is_flagged
                ? 'Visitor added to watchlist.'
                : 'Visitor removed from watchlist.',
        );
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
    public function deletingVisitor(): ?Visitor
    {
        if ($this->deletingVisitorId === null) {
            return null;
        }

        return Visitor::find($this->deletingVisitorId);
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
    {{-- Header --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading size="lg">Visitors</flux:heading>
            <flux:subheading>Manage registered visitors and their identity records.</flux:subheading>
        </div>
        <div class="flex gap-2">
            <flux:button variant="danger" wire:click="confirmClearAll" icon="trash">Clear All Data</flux:button>
            <flux:button variant="primary" wire:click="openCreateModal" icon="plus">Add Visitor</flux:button>
        </div>
    </div>

    {{-- Search --}}
    <div class="flex flex-wrap items-end gap-3">
        <div class="min-w-48 flex-1">
            <flux:input wire:model.live.debounce.300ms="search" placeholder="Search by name, email, phone, company, or ID..." icon="magnifying-glass" />
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
                <flux:button size="sm" variant="danger" icon="trash" wire:click="confirmBulkDelete">Delete Selected</flux:button>
            </div>
        </div>
    @endif

    {{-- Table --}}
    @if ($this->visitors->isEmpty())
            <div class="flex flex-col items-center justify-center py-16 text-center">
                <p class="text-neutral-400 dark:text-neutral-500">
                    {{ $this->search ? 'No visitors match your search.' : 'No visitors found.' }}
                </p>
            </div>
        @else
            <flux:table :paginate="$this->visitors">
                <flux:table.columns>
                    <flux:table.column class="w-10">
                        <input type="checkbox" wire:model.live="selectAll" class="rounded border-neutral-300 text-blue-600 focus:ring-blue-500 dark:border-neutral-600 dark:bg-neutral-800">
                    </flux:table.column>
                    <flux:table.column>Name</flux:table.column>
                    <flux:table.column class="hidden sm:table-cell">Email</flux:table.column>
                    <flux:table.column class="hidden md:table-cell">Phone</flux:table.column>
                    <flux:table.column class="hidden lg:table-cell">ID Number</flux:table.column>
                    <flux:table.column class="hidden md:table-cell">QR</flux:table.column>
                    <flux:table.column class="hidden xl:table-cell">Visits</flux:table.column>
                    <flux:table.column>Flagged</flux:table.column>
                    <flux:table.column class="hidden lg:table-cell">Created</flux:table.column>
                    <flux:table.column align="end">Actions</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->visitors as $visitor)
                        <flux:table.row :key="$visitor->id">
                            <flux:table.cell class="w-10">
                                <input type="checkbox" wire:model.live="selected" value="{{ $visitor->id }}" class="rounded border-neutral-300 text-blue-600 focus:ring-blue-500 dark:border-neutral-600 dark:bg-neutral-800">
                            </flux:table.cell>
                            <flux:table.cell variant="strong">
                                <div class="flex items-center gap-3">
                                    @if ($visitor->photo)
                                        <img src="{{ $visitor->photo }}" alt="" class="h-9 w-9 shrink-0 rounded-full object-cover border border-neutral-200 dark:border-neutral-700">
                                    @else
                                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-neutral-100 text-xs font-medium text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400">
                                            {{ substr($visitor->name, 0, 2) }}
                                        </div>
                                    @endif
                                    <div>
                                        <span>{{ $visitor->name }}</span>
                                        @if ($visitor->company)
                                            <div class="text-xs text-zinc-500">{{ $visitor->company }}</div>
                                        @endif
                                    </div>
                                </div>
                            </flux:table.cell>
                            <flux:table.cell class="hidden sm:table-cell">{{ $visitor->email ?: '—' }}</flux:table.cell>
                            <flux:table.cell class="hidden md:table-cell">{{ $visitor->phone ?: '—' }}</flux:table.cell>
                            <flux:table.cell class="hidden lg:table-cell">{{ $visitor->valid_id_number ?: '—' }}</flux:table.cell>
                            <flux:table.cell class="hidden md:table-cell">
                                @if ($visitor->qr_code_token)
                                    <img src="{{ route('qr.code', $visitor->qr_code_token) }}" alt="QR Code" class="h-10 w-10 rounded border border-neutral-200 object-cover dark:border-neutral-700">
                                @else
                                    <span class="text-neutral-300 dark:text-neutral-600">—</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="hidden xl:table-cell">
                                <span class="text-neutral-500 dark:text-neutral-400">{{ $visitor->logs()->count() }}</span>
                            </flux:table.cell>
                            <flux:table.cell>
                                @if ($visitor->is_flagged)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">
                                        <svg class="h-3 w-3" fill="currentColor" viewBox="0 0 20 20"><path d="M3.6 16.3c-.25 0-.5-.1-.7-.3-.4-.4-.4-1 0-1.4l4.7-4.7-4.7-4.7c-.4-.4-.4-1 0-1.4.4-.4 1-.4 1.4 0l4.7 4.7 4.7-4.7c.4-.4 1-.4 1.4 0 .4.4.4 1 0 1.4L10.4 10l4.7 4.7c.4.4.4 1 0 1.4-.2.2-.4.3-.7.3-.25 0-.5-.1-.7-.3L8.97 11.4l-4.67 4.6c-.2.2-.5.3-.7.3z"/></svg>
                                        Flagged
                                    </span>
                                @else
                                    <span class="text-neutral-300 dark:text-neutral-600">—</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="hidden lg:table-cell">{{ $visitor->created_at->format('M j, Y') }}</flux:table.cell>
                            <flux:table.cell align="end">
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" class="cursor-pointer" />
                                    <flux:menu>
                                        <flux:menu.item icon="eye" wire:click="viewVisitor('{{ $visitor->id }}')">
                                            View
                                        </flux:menu.item>
                                        <flux:menu.item icon="pencil" wire:click="openEditModal('{{ $visitor->id }}')">
                                            Edit
                                        </flux:menu.item>
                                        <flux:menu.separator />
                                        <flux:menu.item
                                            icon="flag"
                                            wire:click="toggleFlag('{{ $visitor->id }}')"
                                            class="{{ $visitor->is_flagged ? 'text-amber-500' : '' }}"
                                        >
                                            {{ $visitor->is_flagged ? 'Remove from Watchlist' : 'Add to Watchlist' }}
                                        </flux:menu.item>
                                        <flux:menu.separator />
                                        <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete('{{ $visitor->id }}')">
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

    {{-- Create Visitor Modal --}}
    <flux:modal wire:model="showCreateModal" name="create-visitor" class="min-w-sm">
        <flux:heading size="lg">Add Visitor</flux:heading>
        <flux:text class="mt-2">Register a new visitor in the system.</flux:text>

        <div class="mt-6 space-y-4">
            <flux:input wire:model="createName" label="Full Name" type="text" required placeholder="e.g. John Doe" />
            <flux:input wire:model="createEmail" label="Email" type="email" placeholder="e.g. john@example.com" />
            <flux:input wire:model="createPhone" label="Phone" type="text" placeholder="e.g. +1 555-0123" />
            <flux:input wire:model="createCompany" label="Company" type="text" placeholder="e.g. Acme Corp" />
            <flux:input wire:model="createValidIdNumber" label="Valid ID Number" type="text" placeholder="e.g. DL-12345678" />

            <div class="flex gap-2 pt-2">
                <flux:button variant="primary" class="flex-1 !py-3" wire:click="createVisitor">
                    Create Visitor
                </flux:button>
                <flux:button variant="ghost" x-on:click="$flux.modal('create-visitor').close()">
                    Cancel
                </flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Edit Visitor Modal --}}
    <flux:modal wire:model="showEditModal" name="edit-visitor" class="min-w-sm">
        <flux:heading size="lg">Edit Visitor</flux:heading>
        <flux:text class="mt-2">Update visitor identity details.</flux:text>

        <div class="mt-6 space-y-4">
            {{-- Photo --}}
            @php $currVisitor = $editingVisitorId ? \App\Models\Visitor::find($editingVisitorId) : null; @endphp
            <div x-data="{
                mode: 'idle',
                photo: @entangle('editPhotoDataUri'),
                stream: null,
                videoReady: false,
                async startCamera() {
                    try {
                        this.mode = 'camera';
                        this.stream = await navigator.mediaDevices.getUserMedia({ video: { width: 640, height: 480, facingMode: 'user' } });
                        await this.$nextTick();
                        const video = this.$refs.editVideo;
                        video.srcObject = this.stream;
                        video.onloadedmetadata = () => { video.play(); this.videoReady = true; };
                    } catch (e) { alert('Camera error: ' + e.message); this.mode = 'idle'; }
                },
                capture() {
                    const video = this.$refs.editVideo;
                    const canvas = this.$refs.editCanvas;
                    canvas.width = video.videoWidth || 640;
                    canvas.height = video.videoHeight || 480;
                    canvas.getContext('2d').drawImage(video, 0, 0);
                    this.photo = canvas.toDataURL('image/jpeg', 0.8);
                    this.stopCamera();
                    this.mode = 'idle';
                },
                stopCamera() {
                    if (this.stream) { this.stream.getTracks().forEach(t => t.stop()); this.stream = null; }
                    this.videoReady = false;
                },
                handleUpload(e) {
                    const file = e.target.files[0];
                    if (!file) return;
                    const reader = new FileReader();
                    reader.onload = (ev) => { this.photo = ev.target.result; };
                    reader.readAsDataURL(file);
                    this.mode = 'idle';
                },
                clearPhoto() {
                    this.photo = '';
                },
                destroy() { this.stopCamera(); }
            }">
                <flux:text variant="label" class="mb-2 block">Photo</flux:text>

                {{-- Camera --}}
                <template x-if="mode === 'camera'">
                    <div class="space-y-2">
                        <video x-ref="editVideo" autoplay playsinline class="mx-auto max-h-48 rounded-lg"></video>
                        <div class="flex gap-2 justify-center">
                            <flux:button variant="primary" x-on:click="capture()" x-bind:disabled="!videoReady">Capture</flux:button>
                            <flux:button variant="ghost" x-on:click="stopCamera(); mode = 'idle'">Cancel</flux:button>
                        </div>
                        <canvas x-ref="editCanvas" class="hidden"></canvas>
                    </div>
                </template>

                {{-- Upload --}}
                <template x-if="mode === 'upload'">
                    <div class="space-y-2">
                        <input type="file" accept="image/*" x-on:change="handleUpload($event)" class="block w-full text-sm text-neutral-600 file:mr-3 file:rounded-lg file:border-0 file:bg-neutral-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-neutral-700 hover:file:bg-neutral-200 dark:text-neutral-400 dark:file:bg-neutral-800 dark:file:text-neutral-300 dark:hover:file:bg-neutral-700">
                        <flux:button variant="ghost" size="sm" x-on:click="mode = 'idle'">Cancel</flux:button>
                    </div>
                </template>

                {{-- Buttons --}}
                <div x-show="!photo" class="flex gap-2">
                    <flux:button variant="outline" class="flex-1" x-on:click="startCamera()" icon="camera">Camera</flux:button>
                    <flux:button variant="outline" class="flex-1" x-on:click="mode = 'upload'" icon="arrow-up-tray">Upload</flux:button>
                </div>

                {{-- Current photo (no new photo taken) --}}
                <div x-show="!photo" class="flex justify-center mt-2">
                    @if ($currVisitor?->photo)
                        <div class="relative">
                            <img src="{{ $currVisitor->photo }}" alt="" class="h-20 w-20 rounded-lg object-cover border border-neutral-200 dark:border-neutral-700">
                            <span class="absolute -bottom-2 left-1/2 -translate-x-1/2 whitespace-nowrap rounded bg-neutral-800/70 px-2 py-0.5 text-[10px] text-white">Current</span>
                        </div>
                    @endif
                </div>

                {{-- New photo preview --}}
                <div x-show="photo" class="flex justify-center mt-2">
                    <div class="relative">
                        <img :src="photo" alt="" class="h-20 w-20 rounded-lg object-cover border-2 border-emerald-400 shadow-sm">
                        <button type="button" x-on:click="clearPhoto()" class="absolute -top-2 -right-2 flex h-5 w-5 items-center justify-center rounded-full bg-red-500 text-white text-xs hover:bg-red-600 transition-shadow hover:shadow-md">✕</button>
                    </div>
                </div>
            </div>

            <flux:input wire:model="editName" label="Full Name" type="text" required placeholder="e.g. John Doe" />
            <flux:input wire:model="editEmail" label="Email" type="email" placeholder="e.g. john@example.com" />
            <flux:input wire:model="editPhone" label="Phone" type="text" placeholder="e.g. +1 555-0123" />
            <flux:input wire:model="editCompany" label="Company" type="text" placeholder="e.g. Acme Corp" />
            <flux:input wire:model="editValidIdNumber" label="Valid ID Number" type="text" placeholder="e.g. DL-12345678" />
            <flux:textarea wire:model="editNotes" label="Notes" placeholder="Internal notes..." rows="3" />

            <div class="flex gap-2 pt-2">
                <flux:button variant="primary" class="flex-1 !py-3" wire:click="updateVisitor">
                    Save Changes
                </flux:button>
                <flux:button variant="ghost" x-on:click="$flux.modal('edit-visitor').close()">
                    Cancel
                </flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- View Visitor Modal --}}
    <flux:modal wire:model="showViewModal" name="view-visitor" class="min-w-sm">
        @if ($this->viewingVisitor)
            <flux:heading size="lg">Visitor Details</flux:heading>

            <div class="mt-6 space-y-4">
                @if ($this->viewingVisitor->photo)
                    <div class="flex justify-center">
                        <img src="{{ $this->viewingVisitor->photo }}" alt="Visitor photo" class="h-24 w-24 rounded-full object-cover border border-neutral-200 dark:border-neutral-700">
                    </div>
                @endif

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <flux:text variant="label">Name</flux:text>
                        <flux:text>{{ $this->viewingVisitor->name }}</flux:text>
                    </div>
                    <div>
                        <flux:text variant="label">Flagged</flux:text>
                        <flux:text>
                            @if ($this->viewingVisitor->is_flagged)
                                <span class="inline-flex items-center gap-1 text-amber-600 dark:text-amber-400">Yes</span>
                            @else
                                <span class="text-neutral-500 dark:text-neutral-400">No</span>
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
                        <flux:text variant="label">Valid ID Number</flux:text>
                        <flux:text>{{ $this->viewingVisitor->valid_id_number ?: '—' }}</flux:text>
                    </div>
                    <div>
                        <flux:text variant="label">Total Visits</flux:text>
                        <flux:text>{{ $this->viewingVisitor->logs()->count() }}</flux:text>
                    </div>
                    <div>
                        <flux:text variant="label">Registered</flux:text>
                        <flux:text>{{ $this->viewingVisitor->created_at->format('M j, Y') }}</flux:text>
                    </div>
                </div>

                @if ($this->viewingVisitor->notes)
                    <div>
                        <flux:text variant="label">Notes</flux:text>
                        <flux:text class="mt-1 whitespace-pre-wrap">{{ $this->viewingVisitor->notes }}</flux:text>
                    </div>
                @endif
            </div>

            <div class="mt-6 flex justify-end">
                <flux:button variant="ghost" x-on:click="$flux.modal('view-visitor').close()">
                    Close
                </flux:button>
            </div>
        @endif
    </flux:modal>

    {{-- Delete Visitor Confirmation Modal --}}
    <flux:modal wire:model="showDeleteModal" name="delete-visitor" class="min-w-sm">
        @if ($this->deletingVisitor)
            <flux:heading size="lg">Delete Visitor</flux:heading>
            <flux:text class="mt-2">
                Are you sure you want to delete <strong>{{ $this->deletingVisitor->name }}</strong>?
                This will also delete all their visit history. This action cannot be undone.
            </flux:text>

            @if ($this->deletingVisitor->logs()->count() > 0)
                <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-900/20">
                    <div class="flex items-start gap-2">
                        <svg class="mt-0.5 h-4 w-4 shrink-0 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                        <p class="text-xs text-amber-700 dark:text-amber-400">
                            This visitor has {{ $this->deletingVisitor->logs()->count() }} visit record(s) that will also be deleted.
                        </p>
                    </div>
                </div>
            @endif

            <div class="mt-6 flex gap-2 justify-end">
                <flux:button variant="ghost" wire:click="cancelDelete">
                    Cancel
                </flux:button>
                <flux:button variant="danger" wire:click="deleteVisitor">
                    Delete Visitor
                </flux:button>
            </div>
        @endif
    </flux:modal>

    {{-- Bulk Delete Confirmation Modal --}}
    <flux:modal wire:model="showBulkDeleteModal" name="bulk-delete-visitors" class="min-w-sm">
        <flux:heading size="lg">Delete {{ count($this->selected) }} Visitor{{ count($this->selected) > 1 ? 's' : '' }}</flux:heading>
        <flux:text class="mt-2">
            Are you sure you want to delete {{ count($this->selected) }} selected visitor{{ count($this->selected) > 1 ? 's' : '' }}?
            This will also delete all their visit history. This action cannot be undone.
        </flux:text>

        <div class="mt-6 flex gap-2 justify-end">
            <flux:button variant="ghost" wire:click="cancelBulkDelete">
                Cancel
            </flux:button>
            <flux:button variant="danger" wire:click="bulkDelete">
                Delete {{ count($this->selected) }} Visitor{{ count($this->selected) > 1 ? 's' : '' }}
            </flux:button>
        </div>
    </flux:modal>

    {{-- Clear All Data Confirmation Modal --}}
    <flux:modal wire:model="showClearAllModal" name="clear-all-visitors" class="min-w-sm">
        <flux:heading size="lg">Clear All Visitor Data</flux:heading>
        <flux:text class="mt-2">
            This will permanently delete <strong>every visitor</strong> and all their visit history.
            This action cannot be undone.
        </flux:text>

        <div class="mt-4 rounded-lg border border-red-200 bg-red-50 p-3 dark:border-red-800 dark:bg-red-900/20">
            <div class="flex items-start gap-2">
                <svg class="mt-0.5 h-4 w-4 shrink-0 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                <p class="text-xs text-red-700 dark:text-red-400">
                    Type <strong>CLEAR</strong> to confirm this destructive action.
                </p>
            </div>
        </div>

        <div class="mt-4">
            <flux:input wire:model="clearAllConfirm" label="Confirmation" placeholder="Type CLEAR" />
        </div>

        <div class="mt-6 flex gap-2 justify-end">
            <flux:button variant="ghost" wire:click="cancelClearAll">
                Cancel
            </flux:button>
            <flux:button variant="danger" wire:click="clearAllVisitors">
                Clear All Data
            </flux:button>
        </div>
    </flux:modal>
</div>
