<?php

use App\Models\Visit;
use App\Services\VisitorCheckOutService;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Title('Visits')] #[Layout('layouts::app')] class extends Component
{
    use WithPagination;

    public string $search = '';

    public string $statusFilter = '';

    public string $dateFrom = '';

    public string $dateTo = '';

    public array $selected = [];

    public bool $selectAll = false;

    public bool $showViewModal = false;

    public bool $showDeleteModal = false;

    public bool $showBulkDeleteModal = false;

    public bool $showClearAllModal = false;

    public string $clearAllConfirm = '';

    public ?string $viewingVisitId = null;

    public ?string $deletingVisitId = null;

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
        $this->reset('search', 'statusFilter', 'dateFrom', 'dateTo', 'selected', 'selectAll');
        $this->resetPage();
    }

    public function rescheduleBooking(string $id): void
    {
        Visit::scheduled()->findOrFail($id)->update(['expected_date' => today()->toDateString()]);

        Flux::toast(variant: 'success', text: 'Booking moved to today.');
    }

    public function checkOutVisit(string $id): void
    {
        $visit = Visit::with('visitor')->findOrFail($id);

        if ($visit->status !== 'checked_in') {
            return;
        }

        app(VisitorCheckOutService::class)->checkOut($visit);

        Flux::toast(variant: 'success', text: __(':name has been checked out.', ['name' => $visit->visitor?->name ?? 'Visitor']));
    }

    public function changeStatus(string $id): void
    {
        $visit = Visit::findOrFail($id);

        if (! in_array($visit->status, ['checked_in', 'checked_out'], true)) {
            return;
        }

        if ($visit->status === 'checked_in') {
            $visit->update(['status' => 'checked_out', 'checked_out_at' => now()]);
        } else {
            $visit->update(['status' => 'checked_in', 'checked_out_at' => null]);
        }
    }

    public function cancelBooking(string $id): void
    {
        Visit::scheduled()->findOrFail($id)->update(['status' => 'cancelled']);

        Flux::toast(variant: 'success', text: 'Booking cancelled.');
    }

    public function viewVisit(string $id): void
    {
        $this->viewingVisitId = $id;
        $this->showViewModal = true;
    }

    public function confirmDelete(string $id): void
    {
        $this->deletingVisitId = $id;
        $this->showDeleteModal = true;
    }

    public function deleteVisit(): void
    {
        $visit = Visit::findOrFail($this->deletingVisitId);
        $visit->delete();

        $this->reset('deletingVisitId', 'showDeleteModal');

        Flux::toast(variant: 'success', text: 'Visit record deleted successfully.');
    }

    public function cancelDelete(): void
    {
        $this->reset('deletingVisitId', 'showDeleteModal');
    }

    public function updatedSelectAll(bool $value): void
    {
        $this->selected = $value ? $this->visits->pluck('id')->toArray() : [];
    }

    public function confirmBulkDelete(): void
    {
        $this->showBulkDeleteModal = true;
    }

    public function bulkDelete(): void
    {
        Visit::whereIn('id', $this->selected)->delete();
        $this->selected = [];
        $this->selectAll = false;
        $this->showBulkDeleteModal = false;
        $this->resetPage();
        Flux::toast(variant: 'success', text: 'Selected visit records deleted successfully.');
    }

    public function cancelBulkDelete(): void
    {
        $this->showBulkDeleteModal = false;
    }

    public function confirmClearAll(): void
    {
        $this->showClearAllModal = true;
    }

    public function clearAllVisits(): void
    {
        if ($this->clearAllConfirm !== 'CLEAR') {
            Flux::toast(variant: 'danger', text: 'Please type CLEAR to confirm.');

            return;
        }

        Visit::query()->delete();
        $this->selected = [];
        $this->selectAll = false;
        $this->clearAllConfirm = '';
        $this->showClearAllModal = false;
        Flux::toast(variant: 'success', text: 'All visit records have been cleared.');
    }

    public function cancelClearAll(): void
    {
        $this->clearAllConfirm = '';
        $this->showClearAllModal = false;
    }

    public function closeViewModal(): void
    {
        $this->reset('showViewModal', 'viewingVisitId');
    }

    protected function baseQuery(): Builder
    {
        return Visit::with('visitor')
            ->when($this->search, fn ($q) => $q->where(fn ($qq) => $qq
                ->search($this->search)
                ->orWhere('host', 'like', '%'.$this->search.'%')
                ->orWhere('badge_number', 'like', '%'.$this->search.'%')))
            ->when($this->statusFilter === 'overdue',
                fn ($q) => $q->scheduled()->whereDate('expected_date', '<', today()),
                fn ($q) => $q->when($this->statusFilter, fn ($qq) => $qq->where('status', $this->statusFilter)))
            ->when($this->dateFrom, fn ($q) => $q->where(fn ($qq) => $qq
                ->whereDate('expected_date', '>=', $this->dateFrom)
                ->orWhereDate('created_at', '>=', $this->dateFrom)))
            ->when($this->dateTo, fn ($q) => $q->where(fn ($qq) => $qq
                ->whereDate('expected_date', '<=', $this->dateTo)
                ->orWhereDate('created_at', '<=', $this->dateTo)));
    }

    #[Computed]
    public function visits(): LengthAwarePaginator
    {
        return $this->baseQuery()->orderByDesc('created_at')->paginate(10);
    }

    #[Computed]
    public function viewingVisit(): ?Visit
    {
        if ($this->viewingVisitId === null) {
            return null;
        }

        return Visit::with('visitor')->find($this->viewingVisitId);
    }

    #[Computed]
    public function deletingVisit(): ?Visit
    {
        if ($this->deletingVisitId === null) {
            return null;
        }

        return Visit::with('visitor')->find($this->deletingVisitId);
    }

    public function exportCsv(): StreamedResponse
    {
        $visits = $this->baseQuery()->orderByDesc('created_at')->get();

        $headers = ['Name', 'Email', 'Phone', 'Company', 'Badge', 'Host', 'Visit Type', 'Purpose', 'Expected Date', 'Status', 'Checked In', 'Checked Out'];

        return response()->streamDownload(function () use ($visits, $headers) {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, $headers);
            foreach ($visits as $visit) {
                fputcsv($stream, [
                    $visit->visitor?->name ?? 'Deleted visitor',
                    $visit->visitor?->email,
                    $visit->visitor?->phone,
                    $visit->visitor?->company,
                    $visit->badge_number,
                    $visit->host,
                    $visit->visit_type,
                    $visit->purpose,
                    $visit->expected_date?->format('Y-m-d'),
                    $visit->status,
                    $visit->checked_in_at?->format('Y-m-d g:i A'),
                    $visit->checked_out_at?->format('Y-m-d g:i A'),
                ]);
            }
            fclose($stream);
        }, 'visits-'.now()->format('Y-m-d').'.csv');
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
    {{-- Header --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading size="lg">Visits</flux:heading>
            <flux:subheading>Visit history and scheduled bookings — one list.</flux:subheading>
        </div>
        <div class="flex gap-2">
            <flux:button variant="danger" wire:click="confirmClearAll" icon="trash">Clear All Data</flux:button>
            <flux:button variant="primary" wire:click="exportCsv" icon="arrow-up-tray">Export CSV</flux:button>
        </div>
    </div>

    {{-- Filters --}}
    <div class="flex flex-wrap items-end gap-3">
        <div class="min-w-48 flex-1">
            <flux:input wire:model.live.debounce.300ms="search" placeholder="Search by name, email, host, badge..." icon="magnifying-glass" autocomplete="off" />
        </div>
        <div class="w-40">
            <flux:select wire:model.live="statusFilter" placeholder="All statuses">
                <option value="">All statuses</option>
                <option value="scheduled">Scheduled</option>
                <option value="overdue">Overdue</option>
                <option value="checked_in">On-site</option>
                <option value="checked_out">Checked Out</option>
                <option value="cancelled">Cancelled</option>
            </flux:select>
        </div>
        <div class="w-40">
            <x-date-picker wire:model="dateFrom" placeholder="From date" :allow-past="true" />
        </div>
        <div class="w-40">
            <x-date-picker wire:model="dateTo" placeholder="To date" :allow-past="true" />
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

        {{-- Bulk action bar --}}
        @if (count($this->selected) > 0)
            <div class="mb-4 flex items-center justify-between rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 dark:border-blue-800 dark:bg-blue-900/20">
                <span class="text-sm font-medium text-blue-700 dark:text-blue-300">
                    {{ count($this->selected) }} record{{ count($this->selected) > 1 ? 's' : '' }} selected
                </span>
                <div class="flex gap-2">
                    <flux:button size="sm" variant="ghost" wire:click="$set('selected', [])">Clear</flux:button>
                    <flux:button size="sm" variant="danger" icon="trash" wire:click="confirmBulkDelete">Delete Selected</flux:button>
                </div>
            </div>
        @endif

        @if ($this->visits->isEmpty())
            <div class="flex flex-col items-center justify-center py-16 text-center">
                <p class="text-neutral-400 dark:text-neutral-500">No visits match your filters.</p>
            </div>
        @else
            <flux:table :paginate="$this->visits">
                <flux:table.columns>
                    <flux:table.column class="w-10">
                        <input type="checkbox" wire:model.live="selectAll" class="rounded border-neutral-300 text-blue-600 focus:ring-blue-500 dark:border-neutral-600 dark:bg-neutral-800">
                    </flux:table.column>
                    <flux:table.column>Name</flux:table.column>
                    <flux:table.column class="hidden sm:table-cell">Badge</flux:table.column>
                    <flux:table.column class="hidden sm:table-cell">QR</flux:table.column>
                    <flux:table.column class="hidden md:table-cell">Host</flux:table.column>
                    <flux:table.column class="hidden lg:table-cell">Company</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column class="hidden md:table-cell">Expected</flux:table.column>
                    <flux:table.column align="end">Checked In</flux:table.column>
                    <flux:table.column align="end" class="hidden lg:table-cell">Checked Out</flux:table.column>
                    <flux:table.column align="end">Actions</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->visits as $visit)
                        <flux:table.row :key="$visit->id">
                            <flux:table.cell class="w-10">
                                <input type="checkbox" wire:model.live="selected" value="{{ $visit->id }}" class="rounded border-neutral-300 text-blue-600 focus:ring-blue-500 dark:border-neutral-600 dark:bg-neutral-800">
                            </flux:table.cell>
                            <flux:table.cell variant="strong">
                                <div class="flex items-center gap-3">
                                    @php $avatar = $visit->photo ?: $visit->visitor?->photo; @endphp
                                    @if ($avatar)
                                        <img src="{{ $avatar }}" alt="" class="h-9 w-9 shrink-0 cursor-pointer rounded-full object-cover border border-neutral-200 transition-opacity hover:opacity-80 dark:border-neutral-700" x-on:click="previewPhoto = $event.target.src">
                                    @else
                                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-neutral-100 text-xs font-medium text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400">
                                            {{ substr($visit->visitor?->name ?? '??', 0, 2) }}
                                        </div>
                                    @endif
                                    <div>
                                        <span>{{ $visit->visitor?->name ?? 'Deleted visitor' }}</span>
                                        <div class="text-xs text-zinc-500">
                                            {{ $visit->visitor?->email ?: '—' }}
                                        </div>
                                    </div>
                                </div>
                            </flux:table.cell>
                            <flux:table.cell class="hidden sm:table-cell">{{ $visit->badge_number ?: '—' }}</flux:table.cell>
                            <flux:table.cell class="hidden sm:table-cell">
                                @if ($visit->visitor?->qr_code_token)
                                    <img
                                        src="{{ route('qr.code', $visit->visitor->qr_code_token) }}"
                                        alt="QR"
                                        class="h-8 w-8 cursor-pointer rounded border border-neutral-200 transition-opacity hover:opacity-80 dark:border-neutral-700"
                                        x-on:click="previewQr = $event.target.src"
                                        title="Click to enlarge"
                                    >
                                @else
                                    <span class="text-neutral-300 dark:text-neutral-600">—</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="hidden md:table-cell">{{ $visit->host ?: '—' }}</flux:table.cell>
                            <flux:table.cell class="hidden lg:table-cell">{{ $visit->visitor?->company ?: '—' }}</flux:table.cell>
                            <flux:table.cell class="py-0">
                                @php $isOverdue = $visit->status === 'scheduled' && $visit->expected_date && $visit->expected_date->lt(today()); @endphp
                                @if ($visit->status === 'scheduled')
                                    <span class="inline-flex items-center gap-1 rounded-full bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-700 dark:bg-blue-900/30 dark:text-blue-400">
                                        <span class="h-1.5 w-1.5 rounded-full bg-blue-500"></span>
                                        Scheduled
                                    </span>
                                    @if ($isOverdue)
                                        <span class="ml-1 inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">
                                            <span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>
                                            Overdue
                                        </span>
                                    @endif
                                @elseif ($visit->status === 'cancelled')
                                    <span class="inline-flex items-center gap-1 rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700 dark:bg-red-900/30 dark:text-red-400">
                                        Cancelled
                                    </span>
                                @elseif ($visit->status === 'checked_in')
                                    <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400">
                                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                                        On-site
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 rounded-full bg-neutral-100 px-2 py-0.5 text-xs font-medium text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400">
                                        Checked Out
                                    </span>
                                @endif
                                @if (app()->environment('local') && in_array($visit->status, ['checked_in', 'checked_out'], true))
                                    <button
                                        wire:click="changeStatus('{{ $visit->id }}')"
                                        wire:confirm="Toggle {{ $visit->visitor?->name ?? 'Deleted visitor' }} to {{ $visit->status === 'checked_in' ? 'checked out' : 'on-site' }}?"
                                        class="ml-1.5 inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-medium text-neutral-400 hover:bg-neutral-100 hover:text-neutral-600 dark:hover:bg-neutral-800 dark:hover:text-neutral-300"
                                        title="Toggle status (dev only)"
                                    >
                                        ↻
                                    </button>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell align="end" class="hidden md:table-cell">
                                @if ($visit->expected_date)
                                    <span class="{{ ($visit->status === 'scheduled' && $visit->expected_date->lt(today())) ? 'font-medium text-amber-700 dark:text-amber-400' : '' }}">{{ $visit->expected_date->format('M j, Y') }}</span>
                                @else
                                    <span class="text-neutral-300 dark:text-neutral-600">—</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell align="end">{{ $visit->checked_in_at?->format('M j, g:i A') ?: '—' }}</flux:table.cell>
                            <flux:table.cell align="end" class="hidden lg:table-cell">{{ $visit->checked_out_at?->format('M j, g:i A') ?: '—' }}</flux:table.cell>
                            <flux:table.cell align="end">
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" class="cursor-pointer" />
                                    <flux:menu>
                                        <flux:menu.item icon="eye" wire:click="viewVisit('{{ $visit->id }}')">
                                            View
                                        </flux:menu.item>
                                        @if ($visit->status === 'scheduled')
                                            @if ($visit->expected_date && $visit->expected_date->lt(today()))
                                                <flux:menu.item icon="calendar" wire:click="rescheduleBooking('{{ $visit->id }}')">
                                                    Move to today
                                                </flux:menu.item>
                                            @endif
                                            <flux:menu.item icon="x-circle" wire:click="cancelBooking('{{ $visit->id }}')" wire:confirm="Cancel this booking?">
                                                Cancel booking
                                            </flux:menu.item>
                                        @endif
                                        @if ($visit->status === 'checked_in')
                                            <flux:menu.item icon="arrow-right-end-on-rectangle" wire:click="checkOutVisit('{{ $visit->id }}')" wire:confirm="Check out {{ $visit->visitor?->name ?? 'this visitor' }}?">
                                                Check out
                                            </flux:menu.item>
                                        @endif
                                        <flux:menu.separator />
                                        <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete('{{ $visit->id }}')">
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
    </div>

    {{-- View Visitor Modal --}}
    <flux:modal wire:model="showViewModal" name="view-visit" class="max-w-2xl">
        @if ($this->viewingVisit)
            <flux:heading size="lg">Visit Details</flux:heading>

            <div class="mt-6 space-y-4">
                @php
                    $modalSelfie = $this->viewingVisit->photo;
                    $modalProfile = $this->viewingVisit->visitor?->photo;
                    $modalIsOverdue = $this->viewingVisit->status === 'scheduled' && $this->viewingVisit->expected_date && $this->viewingVisit->expected_date->lt(today());
                @endphp
                @if ($modalSelfie || $modalProfile)
                    <div class="flex items-start justify-center gap-6">
                        @if ($modalSelfie)
                            <div class="text-center">
                                <img src="{{ $modalSelfie }}" alt="Visit selfie" class="h-24 w-24 rounded-full object-cover border border-neutral-200 dark:border-neutral-700">
                                <p class="mt-1.5 text-xs font-medium text-neutral-500 dark:text-neutral-400">{{ __('Check-in selfie') }}</p>
                            </div>
                        @endif
                        @if ($modalProfile)
                            <div class="text-center">
                                <img src="{{ $modalProfile }}" alt="Visitor photo" class="h-24 w-24 rounded-full object-cover border border-neutral-200 dark:border-neutral-700">
                                <p class="mt-1.5 text-xs font-medium text-neutral-500 dark:text-neutral-400">{{ __('Profile photo') }}</p>
                            </div>
                        @endif
                    </div>
                @endif

                @if ($modalIsOverdue)
                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-900/20">
                        <p class="text-xs font-medium text-amber-700 dark:text-amber-400">{{ __('Scheduled date has passed (:date). Reschedule or cancel this booking.', ['date' => $this->viewingVisit->expected_date->format('M j, Y')]) }}</p>
                    </div>
                @endif

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <flux:text variant="label">Name</flux:text>
                        <flux:text>{{ $this->viewingVisit->visitor?->name ?? 'Deleted visitor' }}</flux:text>
                    </div>
                    <div>
                        <flux:text variant="label">Status</flux:text>
                        <flux:text>
                            @if ($this->viewingVisit->status === 'scheduled')
                                <span class="inline-flex items-center gap-1 text-blue-600 dark:text-blue-400">
                                    <span class="h-1.5 w-1.5 rounded-full bg-blue-500"></span>
                                    Scheduled{{ $this->viewingVisit->expected_date ? ' · '.$this->viewingVisit->expected_date->format('M j, Y') : '' }}
                                </span>
                            @elseif ($this->viewingVisit->status === 'cancelled')
                                <span class="inline-flex items-center gap-1 text-red-500 dark:text-red-400">
                                    Cancelled
                                </span>
                            @elseif ($this->viewingVisit->status === 'checked_in')
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
                        <flux:text>{{ $this->viewingVisit->visitor?->email ?: '—' }}</flux:text>
                    </div>
                    <div>
                        <flux:text variant="label">Phone</flux:text>
                        <flux:text>{{ $this->viewingVisit->visitor?->phone ?: '—' }}</flux:text>
                    </div>
                    <div>
                        <flux:text variant="label">Company</flux:text>
                        <flux:text>{{ $this->viewingVisit->visitor?->company ?: '—' }}</flux:text>
                    </div>
                    <div>
                        <flux:text variant="label">Badge Number</flux:text>
                        <flux:text>{{ $this->viewingVisit->badge_number ?: '—' }}</flux:text>
                    </div>
                    <div>
                        <flux:text variant="label">Host</flux:text>
                        <flux:text>{{ $this->viewingVisit->host ?: '—' }}</flux:text>
                    </div>
                    <div>
                        <flux:text variant="label">Visit type</flux:text>
                        <flux:text>{{ $this->viewingVisit->visit_type ?: '—' }}</flux:text>
                    </div>
                    <div>
                        <flux:text variant="label">Purpose</flux:text>
                        <flux:text>{{ $this->viewingVisit->purpose ?: '—' }}</flux:text>
                    </div>
                    <div>
                        <flux:text variant="label">Expected date</flux:text>
                        <flux:text>{{ $this->viewingVisit->expected_date?->format('M j, Y') ?: '—' }}</flux:text>
                    </div>
                    <div>
                        <flux:text variant="label">Checked In</flux:text>
                        <flux:text>{{ $this->viewingVisit->checked_in_at?->format('M j, Y g:i A') ?: '—' }}</flux:text>
                    </div>
                    <div>
                        <flux:text variant="label">Checked Out</flux:text>
                        <flux:text>{{ $this->viewingVisit->checked_out_at?->format('M j, Y g:i A') ?: '—' }}</flux:text>
                    </div>
                    @if ($this->viewingVisit->checked_in_at && $this->viewingVisit->checked_out_at)
                        @php $visitMins = $this->viewingVisit->checked_in_at->diffInMinutes($this->viewingVisit->checked_out_at); @endphp
                        <div>
                            <flux:text variant="label">Duration</flux:text>
                            <flux:text>{{ $visitMins >= 60 ? intdiv($visitMins, 60).'h '.($visitMins % 60).'m' : $visitMins.'m' }}</flux:text>
                        </div>
                    @endif
                    @if ($this->viewingVisit->visitor?->government_id)
                        <div>
                            <flux:text variant="label">Government ID</flux:text>
                            <flux:text>{{ $this->viewingVisit->visitor->government_id }}</flux:text>
                        </div>
                    @endif
                </div>

                @if ($this->viewingVisit->visitor?->qr_code_token)
                    <div class="flex items-center gap-4 rounded-xl border border-neutral-200 p-3 dark:border-neutral-700">
                        <img src="{{ route('qr.code', $this->viewingVisit->visitor->qr_code_token) }}" alt="Visitor QR code" class="h-20 w-20 shrink-0 rounded-lg border border-neutral-200 dark:border-neutral-700">
                        <div class="text-xs text-neutral-500 dark:text-neutral-400">
                            <p class="font-medium text-neutral-700 dark:text-neutral-300">{{ __('Badge QR code') }}</p>
                            <p class="mt-0.5">{{ __('Scan at the kiosk to check in or out.') }}</p>
                        </div>
                    </div>
                @endif

                @if ($this->viewingVisit->notes)
                    <div>
                        <flux:text variant="label">Notes</flux:text>
                        <flux:text class="mt-1 whitespace-pre-wrap">{{ $this->viewingVisit->notes }}</flux:text>
                    </div>
                @endif

                @if ($this->viewingVisit->visitor?->is_flagged)
                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-900/20">
                        <div class="flex items-start gap-2">
                            <svg class="mt-0.5 h-4 w-4 shrink-0 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                            <p class="text-xs text-amber-700 dark:text-amber-400">This visitor is flagged.</p>
                        </div>
                    </div>
                @endif
            </div>

            <div class="mt-6 flex flex-wrap gap-2 justify-end">
                @if ($this->viewingVisit->status === 'scheduled')
                    @if ($modalIsOverdue)
                        <flux:button variant="outline" wire:click="rescheduleBooking('{{ $this->viewingVisit->id }}')">
                            {{ __('Move to today') }}
                        </flux:button>
                    @endif
                    <flux:button variant="danger" wire:click="cancelBooking('{{ $this->viewingVisit->id }}')" wire:confirm="{{ __('Cancel this booking?') }}">
                        {{ __('Cancel booking') }}
                    </flux:button>
                @elseif ($this->viewingVisit->status === 'checked_in')
                    <flux:button variant="primary" wire:click="checkOutVisit('{{ $this->viewingVisit->id }}')" wire:confirm="{{ __('Check out this visitor?') }}">
                        {{ __('Check out') }}
                    </flux:button>
                @endif
                <flux:button variant="ghost" wire:click="closeViewModal">
                    Close
                </flux:button>
            </div>
        @endif
    </flux:modal>

    {{-- Delete Visitor Log Confirmation Modal --}}
    <flux:modal wire:model="showDeleteModal" name="delete-visitor-log" class="max-w-lg">
        @if ($this->deletingVisit)
            <flux:heading size="lg">Delete Visit Record</flux:heading>
            <flux:text class="mt-2">
                Are you sure you want to delete the visit record for <strong>{{ $this->deletingVisit->visitor?->name ?? 'Deleted visitor' }}</strong>?
                This action cannot be undone.
            </flux:text>

            <div class="mt-6 flex gap-2 justify-end">
                <flux:button variant="ghost" wire:click="cancelDelete">
                    Cancel
                </flux:button>
                <flux:button variant="danger" wire:click="deleteVisit">
                    Delete Record
                </flux:button>
            </div>
        @endif
    </flux:modal>

    {{-- Bulk Delete Confirmation Modal --}}
    <flux:modal wire:model="showBulkDeleteModal" name="bulk-delete-visits" class="max-w-lg">
        <flux:heading size="lg">Delete {{ count($this->selected) }} Visit Record{{ count($this->selected) > 1 ? 's' : '' }}</flux:heading>
        <flux:text class="mt-2">
            Are you sure you want to delete {{ count($this->selected) }} selected visit record{{ count($this->selected) > 1 ? 's' : '' }}?
            This action cannot be undone.
        </flux:text>

        <div class="mt-6 flex gap-2 justify-end">
            <flux:button variant="ghost" wire:click="cancelBulkDelete">
                Cancel
            </flux:button>
            <flux:button variant="danger" wire:click="bulkDelete">
                Delete {{ count($this->selected) }} Record{{ count($this->selected) > 1 ? 's' : '' }}
            </flux:button>
        </div>
    </flux:modal>

    {{-- Clear All Data Confirmation Modal --}}
    <flux:modal wire:model="showClearAllModal" name="clear-all-visits" class="max-w-lg">
        <flux:heading size="lg">Clear All Visit Records</flux:heading>
        <flux:text class="mt-2">
            This will permanently delete <strong>every visit record</strong> in the system.
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
            <flux:button variant="danger" wire:click="clearAllVisits">
                Clear All Data
            </flux:button>
        </div>
    </flux:modal>
</div>
