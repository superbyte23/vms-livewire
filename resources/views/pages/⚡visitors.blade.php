<?php

use App\Models\VisitorLog;
use Flux\Flux;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Title('Visitor Log')] #[Layout('layouts::app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $statusFilter = '';
    public string $dateFrom = '';
    public string $dateTo = '';

    public bool $showViewModal = false;
    public bool $showDeleteModal = false;

    public ?string $viewingLogId = null;
    public ?string $deletingLogId = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatedDateTo(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'statusFilter', 'dateFrom', 'dateTo');
        $this->resetPage();
    }

    public function changeStatus(string $id): void
    {
        $log = VisitorLog::findOrFail($id);

        if ($log->status === 'checked_in') {
            $log->update(['status' => 'checked_out', 'checked_out_at' => now()]);
        } else {
            $log->update(['status' => 'checked_in', 'checked_out_at' => null]);
        }
    }

    public function viewLog(string $id): void
    {
        $this->viewingLogId = $id;
        $this->showViewModal = true;
    }

    public function confirmDelete(string $id): void
    {
        $this->deletingLogId = $id;
        $this->showDeleteModal = true;
    }

    public function deleteLog(): void
    {
        $log = VisitorLog::findOrFail($this->deletingLogId);
        $log->delete();

        $this->reset('deletingLogId', 'showDeleteModal');

        Flux::toast(variant: 'success', text: 'Visitor record deleted successfully.');
    }

    public function cancelDelete(): void
    {
        $this->reset('deletingLogId', 'showDeleteModal');
    }

    #[Computed]
    public function visitors(): LengthAwarePaginator
    {
        return VisitorLog::with('visitor')
            ->when($this->search, fn ($q) => $q->search($this->search))
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->dateFrom, fn ($q) => $q->whereDate('created_at', '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->whereDate('created_at', '<=', $this->dateTo))
            ->orderByDesc('created_at')
            ->paginate(10);
    }

    #[Computed]
    public function viewingVisitor(): ?VisitorLog
    {
        if ($this->viewingLogId === null) {
            return null;
        }

        return VisitorLog::with('visitor')->find($this->viewingLogId);
    }

    #[Computed]
    public function deletingVisitor(): ?VisitorLog
    {
        if ($this->deletingLogId === null) {
            return null;
        }

        return VisitorLog::with('visitor')->find($this->deletingLogId);
    }

    public function exportCsv(): StreamedResponse
    {
        $logs = VisitorLog::with('visitor')
            ->when($this->search, fn ($q) => $q->search($this->search))
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->dateFrom, fn ($q) => $q->whereDate('created_at', '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->whereDate('created_at', '<=', $this->dateTo))
            ->orderByDesc('created_at')
            ->get();

        $headers = ['Name', 'Email', 'Phone', 'Company', 'Badge', 'Host', 'Purpose', 'Status', 'Checked In', 'Checked Out'];

        return response()->streamDownload(function () use ($logs, $headers) {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, $headers);
            foreach ($logs as $log) {
                fputcsv($stream, [
                    $log->visitor->name,
                    $log->visitor->email,
                    $log->visitor->phone,
                    $log->visitor->company,
                    $log->badge_number,
                    $log->host,
                    $log->purpose,
                    $log->status,
                    $log->checked_in_at?->format('Y-m-d g:i A'),
                    $log->checked_out_at?->format('Y-m-d g:i A'),
                ]);
            }
            fclose($stream);
        }, 'visitors-' . now()->format('Y-m-d') . '.csv');
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
    {{-- Header --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading size="lg">Visitor Log</flux:heading>
            <flux:subheading>View and export visitor check-in history.</flux:subheading>
        </div>
        <flux:button variant="primary" wire:click="exportCsv" icon="arrow-up-tray">Export CSV</flux:button> 
    </div>

    {{-- Filters --}}
    <div class="flex flex-wrap items-end gap-3">
        <div class="min-w-48 flex-1">
            <flux:input wire:model.live.debounce.300ms="search" placeholder="Search by name, email, host, badge..." icon="magnifying-glass" />
        </div>
        <div class="w-40">
            <flux:select wire:model.change="statusFilter" placeholder="All statuses">
                <option value="">All statuses</option>
                <option value="checked_in">On-site</option>
                <option value="checked_out">Checked Out</option>
            </flux:select>
        </div>
        <div class="w-40">
            <flux:input wire:model.change="dateFrom" type="date" placeholder="From date" />
        </div>
        <div class="w-40">
            <flux:input wire:model.change="dateTo" type="date" placeholder="To date" />
        </div>
        @if ($this->search || $this->statusFilter || $this->dateFrom || $this->dateTo)
            <flux:button variant="ghost" wire:click="clearFilters" class="shrink-0">
                Clear
            </flux:button>
        @endif
    </div>

    {{-- Table --}}
    <div x-data="{ previewPhoto: '', previewQr: '' }" class="">
        {{-- Photo lightbox --}}
        <div x-show="previewPhoto" x-on:click="previewPhoto = ''" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4">
            <img :src="previewPhoto" alt="Visitor photo" class="max-h-[90vh] max-w-[90vw] rounded-xl object-contain shadow-2xl" x-on:click.stop>
            <button x-on:click="previewPhoto = ''" class="absolute right-4 top-4 flex h-10 w-10 items-center justify-center rounded-full bg-white/20 text-white hover:bg-white/30">
                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        {{-- QR preview lightbox --}}
        <div x-show="previewQr" x-on:click="previewQr = ''" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4">
            <img :src="previewQr" alt="QR Code" class="max-h-[90vh] max-w-[90vw] rounded-xl object-contain shadow-2xl" x-on:click.stop>
            <button x-on:click="previewQr = ''" class="absolute right-4 top-4 flex h-10 w-10 items-center justify-center rounded-full bg-white/20 text-white hover:bg-white/30">
                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        @if ($this->visitors->isEmpty())
            <div class="flex flex-col items-center justify-center py-16 text-center">
                <p class="text-neutral-400 dark:text-neutral-500">No visitors match your filters.</p>
            </div>
        @else
            <flux:table :paginate="$this->visitors">
                <flux:table.columns>
                    <flux:table.column>Name</flux:table.column>
                    <flux:table.column class="hidden sm:table-cell">Badge</flux:table.column>
                    <flux:table.column class="hidden sm:table-cell">QR</flux:table.column>
                    <flux:table.column class="hidden md:table-cell">Host</flux:table.column>
                    <flux:table.column class="hidden lg:table-cell">Company</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column align="end">Checked In</flux:table.column>
                    <flux:table.column align="end" class="hidden lg:table-cell">Checked Out</flux:table.column>
                    <flux:table.column align="end">Actions</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->visitors as $log)
                        <flux:table.row :key="$log->id">
                            <flux:table.cell variant="strong">
                                <div class="flex items-center gap-3">
                                    @if ($log->visitor->photo)
                                        <img src="{{ $log->visitor->photo }}" alt="" class="h-9 w-9 shrink-0 cursor-pointer rounded-full object-cover border border-neutral-200 transition-opacity hover:opacity-80 dark:border-neutral-700" x-on:click="previewPhoto = $event.target.src">
                                    @else
                                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-neutral-100 text-xs font-medium text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400">
                                            {{ substr($log->visitor->name, 0, 2) }}
                                        </div>
                                    @endif
                                    <div>
                                        <span>{{ $log->visitor->name }}</span>
                                        <div class="text-xs text-zinc-500">
                                            {{ $log->visitor->email ?: '—' }}
                                        </div>
                                    </div>
                                </div>
                            </flux:table.cell>
                            <flux:table.cell class="hidden sm:table-cell">{{ $log->badge_number ?: '—' }}</flux:table.cell>
                            <flux:table.cell class="hidden sm:table-cell">
                                @if ($log->qr_code_token)
                                    <img
                                        src="{{ route('qr.code', $log->qr_code_token) }}"
                                        alt="QR"
                                        class="h-8 w-8 cursor-pointer rounded border border-neutral-200 transition-opacity hover:opacity-80 dark:border-neutral-700"
                                        x-on:click="previewQr = $event.target.src"
                                        title="Click to enlarge"
                                    >
                                @else
                                    <span class="text-neutral-300 dark:text-neutral-600">—</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="hidden md:table-cell">{{ $log->host ?: '—' }}</flux:table.cell>
                            <flux:table.cell class="hidden lg:table-cell">{{ $log->visitor->company ?: '—' }}</flux:table.cell>
                            <flux:table.cell class="py-0">
                                @if ($log->status === 'checked_in')
                                    <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400">
                                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                                        On-site
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 rounded-full bg-neutral-100 px-2 py-0.5 text-xs font-medium text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400">
                                        Checked Out
                                    </span>
                                @endif
                                @if (app()->environment('local'))
                                    <button
                                        wire:click="changeStatus('{{ $log->id }}')"
                                        wire:confirm="Toggle {{ $log->visitor->name }} to {{ $log->status === 'checked_in' ? 'checked out' : 'on-site' }}?"
                                        class="ml-1.5 inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-medium text-neutral-400 hover:bg-neutral-100 hover:text-neutral-600 dark:hover:bg-neutral-800 dark:hover:text-neutral-300"
                                        title="Toggle status (dev only)"
                                    >
                                        ↻
                                    </button>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell align="end">{{ $log->checked_in_at?->format('M j, g:i A') ?: '—' }}</flux:table.cell>
                            <flux:table.cell align="end" class="hidden lg:table-cell">{{ $log->checked_out_at?->format('M j, g:i A') ?: '—' }}</flux:table.cell>
                            <flux:table.cell align="end">
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" class="cursor-pointer" />
                                    <flux:menu>
                                        <flux:menu.item icon="eye" wire:click="viewLog('{{ $log->id }}')">
                                            View
                                        </flux:menu.item>
                                        <flux:menu.separator />
                                        <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete('{{ $log->id }}')">
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
    {{-- View Visitor Modal --}}
    <flux:modal wire:model="showViewModal" name="view-visitor" class="min-w-sm">
        @if ($this->viewingVisitor)
            <flux:heading size="lg">Visitor Details</flux:heading>

            <div class="mt-6 space-y-4">
                @if ($this->viewingVisitor->visitor->photo)
                    <div class="flex justify-center">
                        <img src="{{ $this->viewingVisitor->visitor->photo }}" alt="Visitor photo" class="h-24 w-24 rounded-full object-cover border border-neutral-200 dark:border-neutral-700">
                    </div>
                @endif

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <flux:text variant="label">Name</flux:text>
                        <flux:text>{{ $this->viewingVisitor->visitor->name }}</flux:text>
                    </div>
                    <div>
                        <flux:text variant="label">Status</flux:text>
                        <flux:text>
                            @if ($this->viewingVisitor->status === 'checked_in')
                                <span class="inline-flex items-center gap-1 text-emerald-600 dark:text-emerald-400">
                                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                                    On-site
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 text-neutral-500 dark:text-neutral-400">
                                    Checked Out
                                </span>
                            @endif
                        </flux:text>
                    </div>
                    <div>
                        <flux:text variant="label">Email</flux:text>
                        <flux:text>{{ $this->viewingVisitor->visitor->email ?: '—' }}</flux:text>
                    </div>
                    <div>
                        <flux:text variant="label">Phone</flux:text>
                        <flux:text>{{ $this->viewingVisitor->visitor->phone ?: '—' }}</flux:text>
                    </div>
                    <div>
                        <flux:text variant="label">Company</flux:text>
                        <flux:text>{{ $this->viewingVisitor->visitor->company ?: '—' }}</flux:text>
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
                    <div>
                        <flux:text variant="label">Checked In</flux:text>
                        <flux:text>{{ $this->viewingVisitor->checked_in_at?->format('M j, Y g:i A') ?: '—' }}</flux:text>
                    </div>
                    <div>
                        <flux:text variant="label">Checked Out</flux:text>
                        <flux:text>{{ $this->viewingVisitor->checked_out_at?->format('M j, Y g:i A') ?: '—' }}</flux:text>
                    </div>
                </div>

                @if ($this->viewingVisitor->notes)
                    <div>
                        <flux:text variant="label">Notes</flux:text>
                        <flux:text class="mt-1 whitespace-pre-wrap">{{ $this->viewingVisitor->notes }}</flux:text>
                    </div>
                @endif

                @if ($this->viewingVisitor->visitor->is_flagged)
                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-900/20">
                        <div class="flex items-start gap-2">
                            <svg class="mt-0.5 h-4 w-4 shrink-0 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                            <p class="text-xs text-amber-700 dark:text-amber-400">This visitor is flagged.</p>
                        </div>
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
            <flux:heading size="lg">Delete Visitor Record</flux:heading>
            <flux:text class="mt-2">
                Are you sure you want to delete the visitor record for <strong>{{ $this->deletingVisitor->visitor->name }}</strong>?
                This action cannot be undone.
            </flux:text>

            <div class="mt-6 flex gap-2 justify-end">
                <flux:button variant="ghost" wire:click="cancelDelete">
                    Cancel
                </flux:button>
                <flux:button variant="danger" wire:click="deleteLog">
                    Delete Record
                </flux:button>
            </div>
        @endif
    </flux:modal>
</div>
