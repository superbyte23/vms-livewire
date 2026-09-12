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
    #[On('visitor-registered')]
    public function refreshLists(): void {}

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
    {{-- Schedule a visit --}}
    <livewire:pages::components.booking-wizard wire:key="dashboard-booking" />

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
</div>
