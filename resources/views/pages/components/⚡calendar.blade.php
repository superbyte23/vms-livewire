<?php

use App\Models\Visit;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public string $calendarMonth = '';

    public ?string $selectedDate = null;

    public bool $showDayModal = false;

    public function mount(): void
    {
        $this->calendarMonth = now()->format('Y-m');
        $this->selectedDate = now()->toDateString();
    }

    public function prevMonth(): void
    {
        $this->calendarMonth = Carbon::createFromFormat('Y-m', $this->calendarMonth)->subMonth()->format('Y-m');
    }

    public function nextMonth(): void
    {
        $this->calendarMonth = Carbon::createFromFormat('Y-m', $this->calendarMonth)->addMonth()->format('Y-m');
    }

    public function goToCurrentMonth(): void
    {
        $this->calendarMonth = now()->format('Y-m');
        $this->selectedDate = now()->toDateString();
    }

    public function selectDate(string $date): void
    {
        $this->selectedDate = $date;
        $this->calendarMonth = Carbon::parse($date)->format('Y-m');
        $this->showDayModal = true;
    }

    public function rescheduleBooking(string $id): void
    {
        $booking = Visit::scheduled()->findOrFail($id);

        $booking->update(['expected_date' => today()->toDateString()]);

        Flux::toast(variant: 'success', text: __('Booking rescheduled to today.'));
    }

    public function cancelBooking(string $id): void
    {
        Visit::scheduled()->findOrFail($id)->update(['status' => 'cancelled']);

        Flux::toast(variant: 'success', text: __('Booking cancelled.'));
    }

    /**
     * Refresh the grid when the embedded booking wizard (or any other source
     * on this page) creates a schedule. The re-render recomputes the counts.
     */
    #[On('visitor-registered')]
    public function refreshCalendar(): void {}

    #[Computed]
    public function calendarTitle(): string
    {
        return Carbon::createFromFormat('Y-m', $this->calendarMonth ?: now()->format('Y-m'))->format('F Y');
    }

    /**
     * Month grid (Sunday-start weeks) with per-day scheduled-visit and
     * visit counts. Each cell: date, label, inMonth, isToday,
     * isSelected, scheduled, visits.
     */
    #[Computed]
    public function calendarWeeks(): array
    {
        $month = Carbon::createFromFormat('Y-m', $this->calendarMonth ?: now()->format('Y-m'))->startOfMonth();
        $start = $month->copy()->startOfWeek(Carbon::SUNDAY);
        $end = $month->copy()->endOfMonth()->endOfWeek(Carbon::SATURDAY);
        $today = now()->toDateString();

        $scheduledCounts = [];
        $scheduledEvents = [];
        Visit::scheduled()
            ->with('visitor')
            ->whereBetween('expected_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('expected_date')
            ->get(['id', 'visitor_id', 'expected_date', 'visit_type', 'purpose', 'host'])
            ->each(function ($booking) use (&$scheduledCounts, &$scheduledEvents) {
                $key = $booking->expected_date?->toDateString();
                if ($key) {
                    $scheduledCounts[$key] = ($scheduledCounts[$key] ?? 0) + 1;
                    $detail = trim(($booking->visit_type ?: $booking->purpose ?: '').($booking->host ? ' · '.$booking->host : ''));
                    $scheduledEvents[$key][] = [
                        'id' => $booking->id,
                        'name' => $booking->visitor?->name ?? 'Deleted visitor',
                        'detail' => $detail !== '' ? $detail : 'Scheduled visit',
                    ];
                }
            });

        $visitCounts = [];
        Visit::history()
            ->whereBetween('created_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->selectRaw('date(created_at) as date, count(*) as total')
            ->groupBy('date')
            ->pluck('total', 'date')
            ->each(function ($total, $date) use (&$visitCounts) {
                $visitCounts[Carbon::parse($date)->toDateString()] = (int) $total;
            });

        // On-site is current state, not date-bound — surface it on today's cell.
        $onSiteCount = Visit::checkedIn()->count();

        $weeks = [];
        foreach (CarbonPeriod::create($start, $end) as $date) {
            $key = $date->toDateString();
            $cell = [
                'date' => $key,
                'label' => $date->day,
                'inMonth' => $date->format('Y-m') === $month->format('Y-m'),
                'isToday' => $key === $today,
                'isSelected' => $key === $this->selectedDate,
                'scheduled' => $scheduledCounts[$key] ?? 0,
                'events' => $scheduledEvents[$key] ?? [],
                'visits' => $visitCounts[$key] ?? 0,
                'onSite' => $key === $today ? $onSiteCount : 0,
                'isOverdue' => $key < $today && ($scheduledCounts[$key] ?? 0) > 0,
            ];

            if (count($weeks) === 0 || count(end($weeks)) === 7) {
                $weeks[] = [];
            }

            $weeks[count($weeks) - 1][] = $cell;
        }

        return $weeks;
    }

    #[Computed]
    public function selectedDateLabel(): string
    {
        return $this->selectedDate ? Carbon::parse($this->selectedDate)->format('l, M j, Y') : '—';
    }

    #[Computed]
    public function selectedDayScheduled(): Collection
    {
        if (! $this->selectedDate) {
            return new Collection;
        }

        return Visit::with('visitor')
            ->whereDate('expected_date', $this->selectedDate)
            ->whereIn('status', ['scheduled', 'cancelled'])
            ->orderByRaw("CASE WHEN status = 'scheduled' THEN 0 ELSE 1 END")
            ->orderBy('created_at')
            ->get();
    }

    #[Computed]
    public function selectedDayOnSite(): Collection
    {
        return Visit::with('visitor')
            ->where('status', 'checked_in')
            ->orderByDesc('checked_in_at')
            ->get();
    }
}; ?>

<div>
    {{-- Visit Calendar --}}
    <div wire:poll.60s class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-neutral-200/80 dark:bg-neutral-900 dark:ring-neutral-700/60">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-100 px-4 py-4 sm:px-5 dark:border-neutral-800">
            <div>
                <h2 class="text-base font-semibold tracking-tight text-neutral-900 dark:text-white">Visit Calendar</h2>
                <p class="mt-1 flex items-center gap-3 text-xs text-neutral-500 dark:text-neutral-400">
                    <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-emerald-500"></span>Scheduled</span>
                    <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-blue-500"></span>Visits</span>
                    <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-violet-500"></span>On-site</span>
                </p>
            </div>
            <div class="flex items-center gap-1 rounded-full bg-neutral-100 p-1 dark:bg-neutral-800">
                <flux:button size="sm" variant="ghost" icon="chevron-left" wire:click="prevMonth" aria-label="Previous month" class="rounded-full" />
                <button type="button" wire:click="goToCurrentMonth" class="min-w-32 rounded-full px-3 py-1.5 text-sm font-semibold text-neutral-900 transition-colors hover:bg-white hover:shadow-sm dark:text-white dark:hover:bg-neutral-700">
                    {{ $this->calendarTitle }}
                </button>
                <flux:button size="sm" variant="ghost" icon="chevron-right" wire:click="nextMonth" aria-label="Next month" class="rounded-full" />
            </div>
        </div>

        <div class="px-3 pb-3 pt-4 sm:px-4">
            <div class="grid grid-cols-7 gap-1 border-b border-neutral-100 pb-2 text-center text-[11px] font-semibold uppercase tracking-wider text-neutral-400 dark:border-neutral-800 dark:text-neutral-500">
                @foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $weekday)
                    <div class="py-1">{{ $weekday }}</div>
                @endforeach
            </div>

        @foreach ($this->calendarWeeks as $week)
            <div class="grid grid-cols-7 gap-1">
                @foreach ($week as $day)
                    <button
                        type="button"
                        wire:click="selectDate('{{ $day['date'] }}')"
                        wire:key="cal-day-{{ $day['date'] }}"
                        class="group flex aspect-square flex-col rounded-xl border p-1.5 text-sm transition-all {{ $day['isSelected'] ? 'border-emerald-500 bg-emerald-500/[0.08] dark:border-emerald-400 dark:bg-emerald-400/10' : 'border-neutral-200 hover:border-neutral-300 hover:bg-neutral-50 dark:border-neutral-700/70 dark:hover:border-neutral-600 dark:hover:bg-neutral-800/60' }} {{ $day['inMonth'] ? '' : 'opacity-35' }}"
                    >
                        <span class="flex h-6 w-6 items-center justify-center self-end text-xs {{ $day['isToday'] ? 'rounded-full bg-neutral-900 font-semibold text-white dark:bg-white dark:text-neutral-900' : 'font-medium text-neutral-700 dark:text-neutral-200' }}">{{ $day['label'] }}</span>
                        <span class="mt-0.5 hidden w-full flex-col gap-0.5 sm:flex">
                            @foreach (array_slice($day['events'], 0, 2) as $event)
                                <span wire:key="cal-evt-{{ $event['id'] }}" class="flex w-full min-w-0 items-center gap-1 truncate rounded-md border px-1.5 py-px text-left text-[10px] font-semibold leading-tight {{ ($day['isOverdue'] ?? false) ? 'border-amber-600/25 bg-amber-50 text-amber-800 dark:border-amber-400/30 dark:bg-amber-400/10 dark:text-amber-200' : 'border-emerald-600/25 bg-emerald-50 text-emerald-800 dark:border-emerald-400/30 dark:bg-emerald-400/10 dark:text-emerald-200' }}" title="{{ $event['name'] }} — {{ $event['detail'] }}{{ ($day['isOverdue'] ?? false) ? ' — Overdue' : '' }}">
                                    <span class="h-1 w-1 shrink-0 rounded-full {{ ($day['isOverdue'] ?? false) ? 'bg-amber-500 dark:bg-amber-400' : 'bg-emerald-500 dark:bg-emerald-400' }}"></span>
                                    <span class="truncate">{{ $event['name'] }}</span>
                                </span>
                            @endforeach
                            @if (count($day['events']) > 2)
                                <span class="px-1 text-left text-[10px] font-semibold text-neutral-400 dark:text-neutral-500">+{{ count($day['events']) - 2 }} more</span>
                            @endif
                        </span>
                        <span class="mt-auto flex min-h-4 items-center justify-center gap-1">
                            @if ($day['scheduled'] > 0)
                                <span class="inline-flex items-center gap-1 rounded-full px-1.5 py-px text-[10px] font-semibold sm:hidden {{ ($day['isOverdue'] ?? false) ? 'bg-amber-500/10 text-amber-700 dark:bg-amber-400/10 dark:text-amber-300' : 'bg-emerald-500/10 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300' }}" title="{{ $day['scheduled'] }} scheduled visit(s){{ ($day['isOverdue'] ?? false) ? ' — Overdue' : '' }}">
                                    <span class="h-1 w-1 rounded-full {{ ($day['isOverdue'] ?? false) ? 'bg-amber-500' : 'bg-emerald-500' }}"></span>{{ $day['scheduled'] }}
                                </span>
                            @endif
                            @if ($day['visits'] > 0)
                                <span class="inline-flex items-center gap-1 rounded-full bg-blue-500/10 px-1.5 py-px text-[10px] font-semibold text-blue-700 dark:bg-blue-400/10 dark:text-blue-300" title="{{ $day['visits'] }} visit(s)">
                                    <span class="h-1 w-1 rounded-full bg-blue-500"></span>{{ $day['visits'] }}
                                </span>
                            @endif
                            @if (($day['onSite'] ?? 0) > 0)
                                <span class="inline-flex items-center gap-1 rounded-full bg-violet-500/10 px-1.5 py-px text-[10px] font-semibold text-violet-700 dark:bg-violet-400/10 dark:text-violet-300" title="{{ $day['onSite'] }} on-site now">
                                    <span class="h-1 w-1 rounded-full bg-violet-500"></span>{{ $day['onSite'] }}
                                </span>
                            @endif
                        </span>
                    </button>
                @endforeach
            </div>
        @endforeach
        </div>

    </div>

    <flux:modal wire:model="showDayModal" name="day-schedule" class="max-w-2xl">
        <flux:heading size="lg">{{ $this->selectedDateLabel }}</flux:heading>
        <flux:subheading>Scheduled visits for this day.</flux:subheading>

        <div class="mt-4">
            @if ($this->selectedDayScheduled->isEmpty())
                <div class="flex flex-col items-center gap-2 py-8 text-center">
                    <span class="flex h-10 w-10 items-center justify-center rounded-full bg-neutral-100 text-neutral-400 dark:bg-neutral-800 dark:text-neutral-500">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    </span>
                    <p class="text-sm text-neutral-400 dark:text-neutral-500">No scheduled visits on this day.</p>
                </div>
            @else
                <ul class="space-y-2">
                    @foreach ($this->selectedDayScheduled as $visit)
                        @php $isOverdue = $visit->status === 'scheduled' && $visit->expected_date && $visit->expected_date->lt(today()); @endphp
                        <li wire:key="day-sched-{{ $visit->id }}" x-data="{ open: false }" class="overflow-hidden rounded-xl ring-1 {{ $isOverdue ? 'bg-amber-50/60 ring-amber-200/60 dark:bg-amber-900/10 dark:ring-amber-800/60' : 'bg-neutral-50 ring-neutral-200/60 dark:bg-neutral-800/60 dark:ring-neutral-700/60' }}">
                            <button type="button" x-on:click="open = ! open" class="flex w-full items-center gap-3 px-4 py-3 text-left text-sm">
                                @if ($visit->visitor?->photo)
                                    <img src="{{ $visit->visitor->photo }}" alt="" class="h-10 w-10 shrink-0 rounded-full border {{ $isOverdue ? 'border-amber-200 dark:border-amber-800' : 'border-neutral-200 dark:border-neutral-700' }} object-cover">
                                @else
                                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-xs font-bold {{ $isOverdue ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300' : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' }}">{{ substr($visit->visitor?->name ?? '??', 0, 2) }}</span>
                                @endif
                                <div class="min-w-0 flex-1">
                                    <p class="font-semibold break-words {{ $isOverdue ? 'text-amber-700 dark:text-amber-300' : 'text-neutral-900 dark:text-white' }}">{{ $visit->visitor?->name ?? 'Deleted visitor' }}</p>
                                    <p class="truncate text-xs text-neutral-500 dark:text-neutral-400">{{ $visit->visitor?->company ?: '—' }}</p>
                                </div>
                                <span class="hidden shrink-0 rounded-full bg-emerald-500/10 px-2.5 py-0.5 text-[11px] font-semibold text-emerald-700 sm:inline-flex dark:bg-emerald-400/10 dark:text-emerald-300">Scheduled</span>
                                @if ($isOverdue)
                                    <span class="hidden shrink-0 items-center gap-1 rounded-full bg-amber-50 px-2.5 py-0.5 text-[11px] font-semibold text-amber-700 sm:inline-flex dark:bg-amber-900/30 dark:text-amber-300"><span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>{{ __('Overdue') }}</span>
                                @endif
                                <svg class="h-4 w-4 shrink-0 text-neutral-400 transition-transform duration-200" :class="open ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                            </button>
                            <div x-show="open" x-transition class="px-4 pb-3">
                                <dl class="grid grid-cols-1 gap-x-3 gap-y-2 overflow-hidden border-t border-neutral-200/70 pt-3 text-xs sm:grid-cols-2 dark:border-neutral-700/70 [&>div]:min-w-0 [&_dd]:break-words">
                                    <div>
                                        <dt class="font-semibold uppercase tracking-wider text-neutral-400 dark:text-neutral-500">Date</dt>
                                        <dd class="mt-0.5 font-medium text-neutral-900 dark:text-white">{{ $visit->expected_date?->format('l, M j, Y') ?? '—' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="font-semibold uppercase tracking-wider text-neutral-400 dark:text-neutral-500">Visit type</dt>
                                        <dd class="mt-0.5 font-medium text-neutral-900 dark:text-white">{{ $visit->visit_type ?: '—' }}</dd>
                                    </div>
                                    <div class="col-span-2 sm:col-span-1">
                                        <dt class="font-semibold uppercase tracking-wider text-neutral-400 dark:text-neutral-500">Purpose</dt>
                                        <dd class="mt-0.5 font-medium text-neutral-900 dark:text-white">{{ $visit->purpose ?: '—' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="font-semibold uppercase tracking-wider text-neutral-400 dark:text-neutral-500">Host</dt>
                                        <dd class="mt-0.5 font-medium text-neutral-900 dark:text-white">{{ $visit->host ?: '—' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="font-semibold uppercase tracking-wider text-neutral-400 dark:text-neutral-500">Contact</dt>
                                        <dd class="mt-0.5 font-medium text-neutral-900 dark:text-white">{{ $visit->visitor?->email ?: $visit->visitor?->phone ?: '—' }}</dd>
                                    </div>
                                    @if ($visit->visitor?->phone && $visit->visitor?->email)
                                        <div>
                                            <dt class="font-semibold uppercase tracking-wider text-neutral-400 dark:text-neutral-500">Phone</dt>
                                            <dd class="mt-0.5 font-medium text-neutral-900 dark:text-white">{{ $visit->visitor->phone }}</dd>
                                        </div>
                                    @endif
                                    @if ($visit->notes)
                                        <div class="col-span-2 sm:col-span-1">
                                            <dt class="font-semibold uppercase tracking-wider text-neutral-400 dark:text-neutral-500">Notes</dt>
                                            <dd class="mt-0.5 font-medium text-neutral-900 dark:text-white">{{ $visit->notes }}</dd>
                                        </div>
                                    @endif
                                </dl>
                                @if ($isOverdue)
                                    <div class="mt-3 flex flex-wrap gap-2 border-t border-neutral-200/70 pt-3 dark:border-neutral-700/70">
                                        <flux:button size="sm" variant="outline" wire:click="rescheduleBooking('{{ $visit->id }}')">
                                            {{ __('Reschedule to today') }}
                                        </flux:button>
                                        <flux:button size="sm" variant="danger" wire:click="cancelBooking('{{ $visit->id }}')" wire:confirm="{{ __('Cancel this booking?') }}">
                                            {{ __('Cancel booking') }}
                                        </flux:button>
                                    </div>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        @if ($this->selectedDayOnSite->isNotEmpty())
            <div class="mt-5">
                <p class="mb-2 flex items-center gap-1.5 px-1 text-[11px] font-semibold uppercase tracking-wider text-violet-600 dark:text-violet-400"><span class="h-1.5 w-1.5 rounded-full bg-violet-500"></span>On-site now ({{ $this->selectedDayOnSite->count() }})</p>
                <ul class="space-y-1.5">
                    @foreach ($this->selectedDayOnSite as $visit)
                        <li wire:key="day-onsite-{{ $visit->id }}" class="flex items-center gap-3 rounded-lg bg-neutral-50 px-3 py-2.5 text-sm ring-1 ring-neutral-200/60 dark:bg-neutral-800/60 dark:ring-neutral-700/60">
                            @if ($visit->visitor?->photo)
                                <img src="{{ $visit->visitor->photo }}" alt="" class="h-8 w-8 shrink-0 rounded-full border border-neutral-200 object-cover dark:border-neutral-700">
                            @else
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-blue-100 text-[11px] font-bold text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">{{ substr($visit->visitor?->name ?? '??', 0, 2) }}</span>
                            @endif
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-medium text-neutral-900 dark:text-white">{{ $visit->visitor?->name ?? 'Deleted visitor' }}</p>
                                <p class="truncate text-xs text-neutral-500 dark:text-neutral-400">{{ $visit->visitor?->company ?: '—' }}</p>
                            </div>
                            @if ($visit->badge_number)
                                <span class="shrink-0 rounded-md bg-blue-100 px-2 py-0.5 text-xs font-bold text-blue-700 dark:bg-blue-900/40 dark:text-blue-400">{{ $visit->badge_number }}</span>
                            @endif
                            <span class="shrink-0 text-xs text-neutral-400 dark:text-neutral-500">{{ $visit->checked_in_at ? $visit->checked_in_at->format('g:i A') : '—' }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="mt-6 flex justify-end">
            <flux:button variant="ghost" wire:click="$set('showDayModal', false)">Close</flux:button>
        </div>
    </flux:modal>
</div>
