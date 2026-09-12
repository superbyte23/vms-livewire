<?php

use App\Models\Visit;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] #[Layout('layouts::app')] class extends Component
{
    public bool $showScheduleModal = false;

    public bool $showKioskModal = false;

    public int $scheduleKey = 0;

    public int $kioskKey = 0;

    public function openScheduleModal(): void
    {
        $this->scheduleKey++;
        $this->showScheduleModal = true;
    }

    public function openKiosk(): void
    {
        $this->kioskKey++;
        $this->showKioskModal = true;
    }

    #[On('visitor-registered')]
    public function refreshLists(): void {}

    #[On('kiosk-visitor-registered')]
    public function refreshFromKiosk(): void {}

    #[Computed]
    public function todayExpected(): Collection
    {
        return Visit::scheduled()
            ->with('visitor')
            ->whereDate('expected_date', today())
            ->orderBy('created_at')
            ->limit(10)
            ->get();
    }

    #[Computed]
    public function onSiteNow(): Collection
    {
        return Visit::checkedIn()
            ->with('visitor')
            ->orderByDesc('checked_in_at')
            ->limit(10)
            ->get();
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
    <div class="grid items-start gap-4 lg:grid-cols-[480px_minmax(0,1fr)]">
    <div class="flex min-w-0 flex-col gap-4">
    {{-- Schedule a visit (opens as modal) --}}
    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-neutral-200/80 dark:bg-neutral-900 dark:ring-neutral-700/60">
        <div class="flex items-center gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            </span>
            <div class="min-w-0">
                <h2 class="text-sm font-semibold tracking-tight text-neutral-900 dark:text-white">{{ __('Schedule a visit') }}</h2>
                <p class="truncate text-xs text-neutral-500 dark:text-neutral-400">{{ __('Book ahead so check-in is faster.') }}</p>
            </div>
        </div>
        <flux:button variant="primary" class="mt-4 w-full !py-3" wire:click="openScheduleModal" icon="plus">
            {{ __('Schedule a visit') }}
        </flux:button>
    </div>

    {{-- Kiosk check-in (opens the full kiosk in a modal) --}}
    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-neutral-200/80 dark:bg-neutral-900 dark:ring-neutral-700/60">
        <div class="flex items-center gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.75 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 0 1 3.75 9.375v-4.5ZM3.75 14.625c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5a1.125 1.125 0 0 1-1.125-1.125v-4.5ZM13.5 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 0 1 13.5 9.375v-4.5Z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6.75 6.75h.75v.75h-.75v-.75ZM6.75 16.5h.75v.75h-.75v-.75ZM16.5 6.75h.75v.75h-.75v-.75ZM13.5 13.5h.75v.75h-.75v-.75ZM13.5 19.5h.75v.75h-.75v-.75ZM19.5 13.5h.75v.75h-.75v-.75ZM19.5 19.5h.75v.75h-.75v-.75ZM16.5 16.5h.75v.75h-.75v-.75Z"/></svg>
            </span>
            <div class="min-w-0">
                <h2 class="text-sm font-semibold tracking-tight text-neutral-900 dark:text-white">{{ __('Kiosk check-in') }}</h2>
                <p class="truncate text-xs text-neutral-500 dark:text-neutral-400">{{ __('Walk-in visitors use the full kiosk here.') }}</p>
            </div>
        </div>
        <flux:button variant="primary" class="mt-4 w-full !py-3" wire:click="openKiosk" icon="qr-code">
            {{ __('Open kiosk') }}
        </flux:button>
    </div>

    {{-- Today's lists --}}
    <div wire:poll.60s class="flex flex-col gap-4">
        {{-- Expected visitors today --}}
        <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-neutral-200/80 dark:bg-neutral-900 dark:ring-neutral-700/60">
            <div class="flex items-center justify-between gap-3 border-b border-neutral-100 px-4 py-3 dark:border-neutral-800">
                <h2 class="text-sm font-semibold tracking-tight text-neutral-900 dark:text-white">
                    {{ __('Expected today') }}
                    <span class="ml-1.5 inline-flex items-center rounded-full bg-emerald-500/10 px-2 py-0.5 text-[11px] font-bold text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300">{{ $this->todayExpected->count() }}</span>
                </h2>
                <a href="{{ route('visits') }}" class="text-xs font-medium text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-300">{{ __('View all') }}</a>
            </div>
            @if ($this->todayExpected->isEmpty())
                <p class="px-4 py-6 text-center text-sm text-neutral-400 dark:text-neutral-500">{{ __('No expected visitors today.') }}</p>
            @else
                <ul class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    @foreach ($this->todayExpected as $booking)
                        <li wire:key="dash-expected-{{ $booking->id }}" class="flex items-center gap-3 px-4 py-2.5 text-sm">
                            @if ($booking->visitor?->photo)
                                <img src="{{ $booking->visitor->photo }}" alt="" class="h-9 w-9 shrink-0 rounded-full border border-neutral-200 object-cover dark:border-neutral-700">
                            @else
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-xs font-bold text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">{{ substr($booking->visitor?->name ?? '??', 0, 2) }}</span>
                            @endif
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-medium text-neutral-900 dark:text-white">{{ $booking->visitor?->name ?? __('Deleted visitor') }}</p>
                                <p class="truncate text-xs text-neutral-500 dark:text-neutral-400">{{ $booking->visit_type ?: $booking->purpose ?: '—' }}{{ $booking->host ? ' · ' . $booking->host : '' }}</p>
                            </div>
                            <span class="hidden shrink-0 rounded-full bg-emerald-500/10 px-2 py-0.5 text-[11px] font-semibold text-emerald-700 sm:inline-flex dark:bg-emerald-400/10 dark:text-emerald-300">{{ __('Expected') }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        {{-- On-site now --}}
        <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-neutral-200/80 dark:bg-neutral-900 dark:ring-neutral-700/60">
            <div class="flex items-center justify-between gap-3 border-b border-neutral-100 px-4 py-3 dark:border-neutral-800">
                <h2 class="text-sm font-semibold tracking-tight text-neutral-900 dark:text-white">
                    {{ __('On-site now') }}
                    <span class="ml-1.5 inline-flex items-center rounded-full bg-violet-500/10 px-2 py-0.5 text-[11px] font-bold text-violet-700 dark:bg-violet-400/10 dark:text-violet-300">{{ $this->onSiteNow->count() }}</span>
                </h2>
                <a href="{{ route('visits') }}" class="text-xs font-medium text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-300">{{ __('View all') }}</a>
            </div>
            @if ($this->onSiteNow->isEmpty())
                <p class="px-4 py-6 text-center text-sm text-neutral-400 dark:text-neutral-500">{{ __('No visitors on-site.') }}</p>
            @else
                <ul class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    @foreach ($this->onSiteNow as $visit)
                        @php $selfie = $visit->photo ?: $visit->visitor?->photo; @endphp
                        <li wire:key="dash-onsite-{{ $visit->id }}" class="flex items-center gap-3 px-4 py-2.5 text-sm">
                            @if ($selfie)
                                <img src="{{ $selfie }}" alt="" class="h-9 w-9 shrink-0 rounded-full border border-neutral-200 object-cover dark:border-neutral-700">
                            @else
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-violet-100 text-xs font-bold text-violet-700 dark:bg-violet-900/40 dark:text-violet-300">{{ substr($visit->visitor?->name ?? '??', 0, 2) }}</span>
                            @endif
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-medium text-neutral-900 dark:text-white">{{ $visit->visitor?->name ?? __('Deleted visitor') }}</p>
                                <p class="truncate text-xs text-neutral-500 dark:text-neutral-400">{{ $visit->visitor?->company ?: ($visit->host ? __('Visiting: ') . $visit->host : '—') }}</p>
                            </div>
                            @if ($visit->badge_number)
                                <span class="shrink-0 rounded-md bg-violet-100 px-2 py-0.5 text-xs font-bold text-violet-700 dark:bg-violet-900/40 dark:text-violet-300">{{ $visit->badge_number }}</span>
                            @endif
                            <span class="shrink-0 text-xs text-neutral-400 dark:text-neutral-500">{{ $visit->checked_in_at ? $visit->checked_in_at->format('g:i A') : '—' }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
    </div>
    <livewire:pages::components.calendar wire:key="dashboard-calendar" />
    </div>

    {{-- Schedule form modal (fresh wizard on every open) --}}
    <flux:modal wire:model="showScheduleModal" name="schedule-visit" class="w-[min(40.5rem,calc(100vw_-_2rem))] max-w-4xl!">
        <flux:heading size="lg">{{ __('Schedule a visit') }}</flux:heading>
        <flux:subheading>{{ __('Book a visit ahead of time so check-in is faster.') }}</flux:subheading>

        <div class="mt-4">
            <livewire:pages::components.booking-wizard :key="'dashboard-booking-'.$this->scheduleKey" />
        </div>
    </flux:modal>

    {{-- Kiosk check-in overlay (fresh kiosk state on every open).
         Plain overlay (not a <dialog>), so the kiosk's own camera dialogs
         (checkout-confirm, id-scan) and QR overlays never nest inside another
         <dialog>. Escape here is ignored while any of those are open. --}}
    @if ($this->showKioskModal)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-neutral-950/60 p-4 backdrop-blur-sm dark:bg-neutral-950/70 sm:flex sm:items-center sm:justify-center"
             x-data
             x-on:click.self="$wire.set('showKioskModal', false)"
             x-on:keydown.window.escape="$event.defaultPrevented || window.__kioskHasOpenDialog() || $wire.set('showKioskModal', false)">
            <div class="pointer-events-none fixed right-4 top-4 z-10">
                <flux:button variant="ghost" icon="x-mark" aria-label="{{ __('Close kiosk') }}" wire:click="$set('showKioskModal', false)" class="pointer-events-auto" />
            </div>

            <div class="w-[min(40.5rem,calc(100vw_-_2rem))] max-w-4xl">
                <div class="pointer-events-auto w-full overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-neutral-200/80 dark:bg-neutral-900 dark:ring-neutral-700/60">
                    <div class="max-h-[calc(100svh-2rem)] overflow-y-auto">
                        <livewire:pages::welcome :key="'dashboard-kiosk-'.$this->kioskKey" :embedded="true" />
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
