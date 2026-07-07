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

    public bool $showCreateModal = false;
    public bool $showEditModal = false;
    public bool $showViewModal = false;
    public bool $showDeleteModal = false;

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
        <flux:button variant="primary" wire:click="openCreateModal" icon="plus">Add Visitor</flux:button>
    </div>

    {{-- Search --}}
    <div class="flex flex-wrap items-end gap-3">
        <div class="min-w-48 flex-1">
            <flux:input wire:model.live.debounce.300ms="search" placeholder="Search by name, email, phone, company, or ID..." icon="magnifying-glass" />
        </div>
    </div>

    {{-- Table --}}
    <div class="overflow-hidden rounded-xl border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
        @if ($this->visitors->isEmpty())
            <div class="flex flex-col items-center justify-center py-16 text-center">
                <p class="text-neutral-400 dark:text-neutral-500">
                    {{ $this->search ? 'No visitors match your search.' : 'No visitors found.' }}
                </p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-neutral-100 text-neutral-500 dark:border-neutral-800 dark:text-neutral-400">
                            <th class="px-5 py-3 font-medium">Name</th>
                            <th class="px-5 py-3 font-medium hidden sm:table-cell">Email</th>
                            <th class="px-5 py-3 font-medium hidden md:table-cell">Phone</th>
                            <th class="px-5 py-3 font-medium hidden lg:table-cell">ID Number</th>
                            <th class="px-5 py-3 font-medium hidden xl:table-cell">Visits</th>
                            <th class="px-5 py-3 font-medium">Flagged</th>
                            <th class="px-5 py-3 font-medium hidden lg:table-cell">Created</th>
                            <th class="px-5 py-3 font-medium text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        @foreach ($this->visitors as $visitor)
                            <tr class="group" wire:key="{{ $visitor->id }}">
                                <td class="px-5 py-3">
                                    <div class="flex items-center gap-3">
                                        @if ($visitor->photo)
                                            <img src="{{ $visitor->photo }}" alt="" class="h-9 w-9 shrink-0 rounded-full object-cover border border-neutral-200 dark:border-neutral-700">
                                        @else
                                            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-neutral-100 text-xs font-medium text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400">
                                                {{ substr($visitor->name, 0, 2) }}
                                            </div>
                                        @endif
                                        <div>
                                            <span class="font-medium text-neutral-900 dark:text-white">{{ $visitor->name }}</span>
                                            @if ($visitor->company)
                                                <div class="text-xs text-neutral-400 dark:text-neutral-500">{{ $visitor->company }}</div>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="px-5 py-3 text-neutral-600 dark:text-neutral-300 hidden sm:table-cell">{{ $visitor->email ?: '—' }}</td>
                                <td class="px-5 py-3 text-neutral-500 dark:text-neutral-400 hidden md:table-cell">{{ $visitor->phone ?: '—' }}</td>
                                <td class="px-5 py-3 text-neutral-500 dark:text-neutral-400 hidden lg:table-cell">{{ $visitor->valid_id_number ?: '—' }}</td>
                                <td class="px-5 py-3 hidden xl:table-cell">
                                    <span class="text-neutral-500 dark:text-neutral-400">{{ $visitor->logs()->count() }}</span>
                                </td>
                                <td class="px-5 py-3">
                                    @if ($visitor->is_flagged)
                                        <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">
                                            <svg class="h-3 w-3" fill="currentColor" viewBox="0 0 20 20"><path d="M3.6 16.3c-.25 0-.5-.1-.7-.3-.4-.4-.4-1 0-1.4l4.7-4.7-4.7-4.7c-.4-.4-.4-1 0-1.4.4-.4 1-.4 1.4 0l4.7 4.7 4.7-4.7c.4-.4 1-.4 1.4 0 .4.4.4 1 0 1.4L10.4 10l4.7 4.7c.4.4.4 1 0 1.4-.2.2-.4.3-.7.3-.25 0-.5-.1-.7-.3L8.97 11.4l-4.67 4.6c-.2.2-.5.3-.7.3z"/></svg>
                                            Flagged
                                        </span>
                                    @else
                                        <span class="text-neutral-300 dark:text-neutral-600">—</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-neutral-500 dark:text-neutral-400 hidden lg:table-cell">{{ $visitor->created_at->format('M j, Y') }}</td>
                                <td class="px-5 py-3 text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        <flux:button size="sm" variant="ghost" wire:click="viewVisitor('{{ $visitor->id }}')" icon="eye" title="View" />
                                        <flux:button size="sm" variant="ghost" wire:click="openEditModal('{{ $visitor->id }}')" icon="pencil" title="Edit" />
                                        <flux:button
                                            size="sm"
                                            variant="ghost"
                                            wire:click="toggleFlag('{{ $visitor->id }}')"
                                            title="{{ $visitor->is_flagged ? 'Remove from watchlist' : 'Add to watchlist' }}"
                                            class="{{ $visitor->is_flagged ? 'text-amber-500 hover:text-amber-700 dark:hover:text-amber-400' : 'text-neutral-400 hover:text-amber-500' }}"
                                        >
                                            <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20"><path d="M7 2a1 1 0 00-.707 1.707L7 4.414v3.758a1 1 0 01-.293.707l-4 4C.817 14.769 2.156 18 4.828 18h10.344c2.672 0 4.01-3.231 2.12-5.121l-4-4A1 1 0 0113 8.172V4.414l.707-.707A1 1 0 0013 2H7z"/></svg>
                                        </flux:button>
                                        <flux:button size="sm" variant="ghost" wire:click="confirmDelete('{{ $visitor->id }}')" icon="trash" class="text-red-500 hover:text-red-700 dark:hover:text-red-400" title="Delete" />
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="border-t border-neutral-100 px-5 py-3 dark:border-neutral-800">
                {{ $this->visitors->links() }}
            </div>
        @endif
    </div>

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
</div>
