<?php

use App\Models\VisitorLog;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] #[Layout('layouts::app')] class extends Component {
    public string $chartPeriod = '14days';

    public function updatedChartPeriod(): void {}

    #[Computed]
    public function todayCount(): int
    {
        if (! Schema::hasTable('visitor_logs')) {
            return 0;
        }

        return VisitorLog::whereDate('created_at', today())->count();
    }

    #[Computed]
    public function onSiteCount(): int
    {
        if (! Schema::hasTable('visitor_logs')) {
            return 0;
        }

        return VisitorLog::where('status', 'checked_in')->count();
    }

    #[Computed]
    public function avgDurationMinutes(): ?int
    {
        if (! Schema::hasTable('visitor_logs')) {
            return null;
        }

        $visitorLogs = VisitorLog::whereDate('created_at', today())
            ->where('status', 'checked_out')
            ->whereNotNull('checked_out_at')
            ->get(['checked_in_at', 'checked_out_at']);

        if ($visitorLogs->isEmpty()) {
            return null;
        }

        $totalMinutes = $visitorLogs->sum(fn ($v) => $v->checked_in_at->diffInMinutes($v->checked_out_at));

        return (int) round($totalMinutes / $visitorLogs->count());
    }

    #[Computed]
    public function peakHour(): ?string
    {
        if (! Schema::hasTable('visitor_logs')) {
            return null;
        }

        $hours = VisitorLog::whereDate('created_at', today())
            ->selectRaw("strftime('%H', checked_in_at) as hour, count(*) as total")
            ->whereNotNull('checked_in_at')
            ->groupBy('hour')
            ->orderByDesc('total')
            ->limit(1)
            ->get();

        if ($hours->isEmpty()) {
            return null;
        }

        $h = (int) $hours->first()->hour;

        return now()->setHour($h)->setMinute(0)->format('g A');
    }

    #[Computed]
    public function recentVisitors(): Collection
    {
        if (! Schema::hasTable('visitor_logs')) {
            return new Collection;
        }

        return VisitorLog::with('visitor')
            ->whereDate('created_at', today())
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();
    }

    #[Computed]
    public function visitorsPerDay(): array
    {
        if (! Schema::hasTable('visitor_logs')) {
            return ['labels' => [], 'data' => []];
        }

        $days = match ($this->chartPeriod) {
            '7days' => 7,
            '30days' => 30,
            '90days' => 90,
            default => 14,
        };

        $results = VisitorLog::where('created_at', '>=', now()->subDays($days - 1)->startOfDay())
            ->selectRaw("date(created_at) as date, count(*) as total")
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('total', 'date');

        $period = CarbonPeriod::create(now()->subDays($days - 1), now());
        $labels = [];
        $data = [];

        foreach ($period as $date) {
            $labels[] = $date->format('M j');
            $data[] = $results->get($date->format('Y-m-d'), 0);
        }

        return compact('labels', 'data');
    }

    #[Computed]
    public function peakHours(): array
    {
        if (! Schema::hasTable('visitor_logs')) {
            return ['labels' => [], 'data' => []];
        }

        $hours = VisitorLog::selectRaw("strftime('%H', checked_in_at) as hour, count(*) as total")
            ->whereNotNull('checked_in_at')
            ->groupBy('hour')
            ->orderBy('hour')
            ->pluck('total', 'hour');

        $labels = [];
        $data = [];

        for ($h = 6; $h <= 20; $h++) {
            $labels[] = now()->setHour($h)->setMinute(0)->format('g A');
            $data[] = $hours->get(str_pad($h, 2, '0', STR_PAD_LEFT), 0);
        }

        return compact('labels', 'data');
    }

    #[Computed]
    public function statusDistribution(): array
    {
        if (! Schema::hasTable('visitor_logs')) {
            return ['labels' => [], 'data' => []];
        }

        $onSite = VisitorLog::where('status', 'checked_in')->count();
        $checkedOut = VisitorLog::where('status', 'checked_out')->count();

        return [
            'labels' => ['On-Site', 'Checked Out'],
            'data' => [$onSite, $checkedOut],
        ];
    }

    #[Computed]
    public function durationTrend(): array
    {
        if (! Schema::hasTable('visitor_logs')) {
            return ['labels' => [], 'data' => []];
        }

        $days = match ($this->chartPeriod) {
            '7days' => 7,
            '30days' => 30,
            '90days' => 90,
            default => 14,
        };

        $results = VisitorLog::where('created_at', '>=', now()->subDays($days - 1)->startOfDay())
            ->where('status', 'checked_out')
            ->whereNotNull('checked_out_at')
            ->selectRaw("date(created_at) as date, checked_in_at, checked_out_at")
            ->get()
            ->groupBy('date')
            ->map(fn ($group) => (int) round($group->avg(fn ($v) => $v->checked_in_at->diffInMinutes($v->checked_out_at))));

        $period = CarbonPeriod::create(now()->subDays($days - 1), now());
        $labels = [];
        $data = [];

        foreach ($period as $date) {
            $labels[] = $date->format('M j');
            $data[] = $results->get($date->format('Y-m-d'));
        }

        return compact('labels', 'data');
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
    {{-- KPI Cards --}}
    <div class="grid auto-rows-min gap-4 md:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-neutral-900">
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                </div>
                <div>
                    <p class="text-sm text-neutral-500 dark:text-neutral-400">Visitors Today</p>
                    <p class="text-2xl font-bold text-neutral-900 dark:text-white">{{ $this->todayCount }}</p>
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-neutral-900">
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-400">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M23 21v-2a4 4 0 00-3-3.87m-4-12a4 4 0 010 7.75"/></svg>
                </div>
                <div>
                    <p class="text-sm text-neutral-500 dark:text-neutral-400">On-Site Now</p>
                    <p class="text-2xl font-bold text-emerald-600 dark:text-emerald-400">{{ $this->onSiteCount }}</p>
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-neutral-900">
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-amber-50 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <div>
                    <p class="text-sm text-neutral-500 dark:text-neutral-400">Avg Visit Duration</p>
                    <p class="text-2xl font-bold text-neutral-900 dark:text-white">
                        {{ $this->avgDurationMinutes !== null ? $this->avgDurationMinutes . ' min' : '—' }}
                    </p>
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-neutral-900">
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-purple-50 text-purple-600 dark:bg-purple-900/30 dark:text-purple-400">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                </div>
                <div>
                    <p class="text-sm text-neutral-500 dark:text-neutral-400">Peak Hour</p>
                    <p class="text-2xl font-bold text-neutral-900 dark:text-white">{{ $this->peakHour ?? '—' }}</p>
                </div>
            </div>
        </div>
    </div>

    {{-- Charts Section --}}
    <div class="flex flex-col gap-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <flux:heading>Visitor Analytics</flux:heading>
            <div class="w-40">
                <flux:select wire:model.change="chartPeriod">
                    <option value="7days">Last 7 days</option>
                    <option value="14days">Last 14 days</option>
                    <option value="30days">Last 30 days</option>
                    <option value="90days">Last 90 days</option>
                </flux:select>
            </div>
        </div>

        <div wire:key="chart-grid-{{ $chartPeriod }}" x-data="{
            charts: [],

            init() {
                this.$nextTick(() => {
                    this.initVisitorsPerDay();
                    this.initPeakHours();
                    this.initStatusDistribution();
                    this.initDurationTrend();
                });
            },

            destroyCharts() {
                this.charts.forEach(c => c.destroy());
                this.charts = [];
            },

            initVisitorsPerDay() {
                const ctx = this.$refs.visitorsPerDay;
                if (! ctx) return;
                const chart = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: {{ Js::from($this->visitorsPerDay['labels']) }},
                        datasets: [{
                            label: 'Visitors',
                            data: {{ Js::from($this->visitorsPerDay['data']) }},
                            backgroundColor: 'rgba(59, 130, 246, 0.6)',
                            borderColor: 'rgba(59, 130, 246, 1)',
                            borderWidth: 1,
                            borderRadius: 4,
                        }],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: {
                            y: { beginAtZero: true, ticks: { stepSize: 1 } },
                            x: { grid: { display: false } },
                        },
                    },
                });
                this.charts.push(chart);
            },

            initPeakHours() {
                const ctx = this.$refs.peakHours;
                if (! ctx) return;
                const chart = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: {{ Js::from($this->peakHours['labels']) }},
                        datasets: [{
                            label: 'Check-ins',
                            data: {{ Js::from($this->peakHours['data']) }},
                            backgroundColor: 'rgba(168, 85, 247, 0.6)',
                            borderColor: 'rgba(168, 85, 247, 1)',
                            borderWidth: 1,
                            borderRadius: 4,
                        }],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: {
                            y: { beginAtZero: true, ticks: { stepSize: 1 } },
                            x: { grid: { display: false } },
                        },
                    },
                });
                this.charts.push(chart);
            },

            initStatusDistribution() {
                const ctx = this.$refs.statusDistribution;
                if (! ctx) return;
                const chart = new Chart(ctx, {
                    type: 'doughnut',
                    data: {
                        labels: {{ Js::from($this->statusDistribution['labels']) }},
                        datasets: [{
                            data: {{ Js::from($this->statusDistribution['data']) }},
                            backgroundColor: ['rgba(16, 185, 129, 0.7)', 'rgba(148, 163, 184, 0.6)'],
                            borderColor: ['rgba(16, 185, 129, 1)', 'rgba(148, 163, 184, 1)'],
                            borderWidth: 2,
                        }],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { position: 'bottom' },
                        },
                    },
                });
                this.charts.push(chart);
            },

            initDurationTrend() {
                const ctx = this.$refs.durationTrend;
                if (! ctx) return;
                const chart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: {{ Js::from($this->durationTrend['labels']) }},
                        datasets: [{
                            label: 'Avg Duration (min)',
                            data: {{ Js::from($this->durationTrend['data']) }},
                            borderColor: 'rgba(245, 158, 11, 1)',
                            backgroundColor: 'rgba(245, 158, 11, 0.1)',
                            fill: true,
                            tension: 0.3,
                            pointRadius: 3,
                            pointBackgroundColor: 'rgba(245, 158, 11, 1)',
                        }],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: {
                            y: { beginAtZero: true, ticks: { callback: v => v + ' min' } },
                            x: { grid: { display: false } },
                        },
                        interaction: { intersect: false, mode: 'index' },
                    },
                });
                this.charts.push(chart);
            },
        }" class="grid gap-4 md:grid-cols-2">
            {{-- Visitors Per Day --}}
            <div class="rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-neutral-900">
                <h3 class="mb-3 text-sm font-medium text-neutral-500 dark:text-neutral-400">Visitors Per Day</h3>
                <div class="h-56">
                    <canvas x-ref="visitorsPerDay"></canvas>
                </div>
            </div>

            {{-- Peak Hours --}}
            <div class="rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-neutral-900">
                <h3 class="mb-3 text-sm font-medium text-neutral-500 dark:text-neutral-400">Peak Check-in Hours</h3>
                <div class="h-56">
                    <canvas x-ref="peakHours"></canvas>
                </div>
            </div>

            {{-- Status Distribution --}}
            <div class="rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-neutral-900">
                <h3 class="mb-3 text-sm font-medium text-neutral-500 dark:text-neutral-400">Status Overview</h3>
                <div class="h-56 flex items-center justify-center">
                    <canvas x-ref="statusDistribution" class="max-h-56"></canvas>
                </div>
            </div>

            {{-- Duration Trend --}}
            <div class="rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-neutral-900">
                <h3 class="mb-3 text-sm font-medium text-neutral-500 dark:text-neutral-400">Avg Visit Duration Trend</h3>
                <div class="h-56">
                    <canvas x-ref="durationTrend"></canvas>
                </div>
            </div>
        </div>
    </div>

    {{-- Recent Visitors Table --}}
    <div x-data="{ previewPhoto: '' }" class="relative h-full flex-1 overflow-hidden rounded-xl border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
        {{-- Photo lightbox --}}
        <div x-show="previewPhoto" x-on:click="previewPhoto = ''" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4">
            <img :src="previewPhoto" alt="Visitor photo" class="max-h-[90vh] max-w-[90vw] rounded-xl object-contain shadow-2xl" x-on:click.stop>
            <button x-on:click="previewPhoto = ''" class="absolute right-4 top-4 flex h-10 w-10 items-center justify-center rounded-full bg-white/20 text-white hover:bg-white/30">
                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <div class="p-5 pb-3">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold text-neutral-900 dark:text-white">Recent Visitors</h2>
            </div>
        </div>
        @if ($this->recentVisitors->isEmpty())
            <div class="flex flex-col items-center justify-center py-16 text-center">
                <p class="text-neutral-400 dark:text-neutral-500">No visitors yet today.</p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-y border-neutral-100 text-neutral-500 dark:border-neutral-800 dark:text-neutral-400">
                            <th class="px-5 py-3 font-medium">Name</th>
                            <th class="px-5 py-3 font-medium hidden sm:table-cell">Badge</th>
                            <th class="px-5 py-3 font-medium hidden md:table-cell">Host</th>
                            <th class="px-5 py-3 font-medium hidden lg:table-cell">Status</th>
                            <th class="px-5 py-3 font-medium text-right">Checked In</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        @foreach ($this->recentVisitors as $log)
                            <tr class="group" wire:key="{{ $log->id }}">
                                <td class="px-5 py-3.5">
                                    <div class="flex items-center gap-3">
                                        @if ($log->visitor->photo)
                                            <img src="{{ $log->visitor->photo }}" alt="" class="h-8 w-8 shrink-0 cursor-pointer rounded-full object-cover border border-neutral-200 transition-opacity hover:opacity-80 dark:border-neutral-700" x-on:click="previewPhoto = $event.target.src">
                                        @else
                                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-neutral-100 text-xs font-medium text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400">
                                                {{ substr($log->visitor->name, 0, 2) }}
                                            </div>
                                        @endif
                                        <div>
                                            <span class="font-medium text-neutral-900 dark:text-white">{{ $log->visitor->name }}</span>
                                            @if ($log->visitor->company)
                                                <span class="ml-1.5 text-xs text-neutral-400 dark:text-neutral-500">{{ $log->visitor->company }}</span>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="px-5 py-3.5 text-neutral-600 dark:text-neutral-300 hidden sm:table-cell">{{ $log->badge_number ?: '—' }}</td>
                                <td class="px-5 py-3.5 text-neutral-600 dark:text-neutral-300 hidden md:table-cell">{{ $log->host ?: '—' }}</td>
                                <td class="px-5 py-3.5 hidden lg:table-cell">
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
                                </td>
                                <td class="px-5 py-3.5 text-right text-neutral-500 dark:text-neutral-400 whitespace-nowrap">
                                    {{ $log->checked_in_at ? $log->checked_in_at->format('g:i A') : '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
