<?php

use App\Models\Visit;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Title('Reports')] #[Layout('layouts::app')] class extends Component
{
    public string $preset = '30';

    public string $dateFrom = '';

    public string $dateTo = '';

    public function mount(): void
    {
        $this->applyPreset();
    }

    public function selectPreset(string $preset): void
    {
        $this->preset = $preset;
        $this->applyPreset();
    }

    protected function applyPreset(): void
    {
        $days = max(1, (int) $this->preset);
        $this->dateTo = today()->toDateString();
        $this->dateFrom = today()->subDays($days - 1)->toDateString();
    }

    public function updatedDateFrom(): void
    {
        $this->preset = 'custom';
    }

    public function updatedDateTo(): void
    {
        $this->preset = 'custom';
    }

    /**
     * Inclusive [start, end] bounds, order-safe. Grouping happens in PHP so
     * the queries stay portable between MySQL and SQLite.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function range(): array
    {
        $from = $this->dateFrom !== ''
            ? Carbon::parse($this->dateFrom)->startOfDay()
            : today()->subDays(29)->startOfDay();
        $to = $this->dateTo !== ''
            ? Carbon::parse($this->dateTo)->endOfDay()
            : today()->endOfDay();

        return $from->gt($to)
            ? [$to->copy()->startOfDay(), $from->copy()->endOfDay()]
            : [$from, $to];
    }

    #[Computed]
    public function rangeLabel(): string
    {
        [$from, $to] = $this->range();

        return $from->isSameDay($to)
            ? $from->format('M j, Y')
            : $from->format('M j, Y').' – '.$to->format('M j, Y');
    }

    #[Computed]
    public function chartVersion(): string
    {
        return md5($this->dateFrom.'|'.$this->dateTo);
    }

    #[Computed]
    public function checkIns(): Collection
    {
        [$from, $to] = $this->range();

        return Visit::whereNotNull('checked_in_at')
            ->whereBetween('checked_in_at', [$from, $to])
            ->get();
    }

    #[Computed]
    public function completed(): Collection
    {
        [$from, $to] = $this->range();

        return Visit::where('status', 'checked_out')
            ->whereNotNull('checked_out_at')
            ->whereBetween('checked_out_at', [$from, $to])
            ->get();
    }

    #[Computed]
    public function createdInRange(): Collection
    {
        [$from, $to] = $this->range();

        return Visit::whereBetween('created_at', [$from, $to])->get();
    }

    #[Computed]
    public function historyInRange(): Collection
    {
        return $this->checkIns->merge($this->completed)->unique('id')->values();
    }

    #[Computed]
    public function onSiteNow(): int
    {
        return Visit::checkedIn()->count();
    }

    #[Computed]
    public function overdueCount(): int
    {
        return Visit::scheduled()->whereDate('expected_date', '<', today())->count();
    }

    #[Computed]
    public function avgDurationMinutes(): ?float
    {
        $durations = $this->completed
            ->filter(fn ($visit) => $visit->checked_in_at && $visit->checked_out_at)
            ->map(fn ($visit) => $visit->checked_in_at->diffInMinutes($visit->checked_out_at));

        return $durations->isNotEmpty() ? $durations->avg() : null;
    }

    public function formatDuration(?float $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }

        $total = (int) round($minutes);

        return $total >= 60
            ? intdiv($total, 60).'h '.($total % 60).'m'
            : $total.'m';
    }

    /**
     * Bucket bounds for the range. Weekly buckets kick in past 120 days so
     * charts stay readable for long custom ranges.
     *
     * @return array<int, array{0: Carbon, 1: Carbon, 2: string}>
     */
    protected function bucketBounds(): array
    {
        [$from, $to] = $this->range();
        $start = $from->copy()->startOfDay();
        $end = $to->copy()->endOfDay();
        $span = $start->diffInDays($end) + 1;

        $bounds = [];

        if ($span > 120) {
            $cursor = $start->copy();
            while ($cursor->lte($end)) {
                $bucketEnd = $cursor->copy()->addDays(6)->endOfDay();
                if ($bucketEnd->gt($end)) {
                    $bucketEnd = $end->copy();
                }
                $bounds[] = [$cursor->copy()->startOfDay(), $bucketEnd, 'w/c '.$cursor->format('M j')];
                $cursor = $bucketEnd->copy()->addDay()->startOfDay();
            }

            return $bounds;
        }

        foreach (CarbonPeriod::create($start, $end->copy()->startOfDay()) as $day) {
            $bounds[] = [$day->copy()->startOfDay(), $day->copy()->endOfDay(), $day->format('M j')];
        }

        return $bounds;
    }

    #[Computed]
    public function bucketRows(): array
    {
        $rows = [];

        foreach ($this->bucketBounds() as [$start, $end, $label]) {
            $ins = $this->checkIns->filter(fn ($visit) => $visit->checked_in_at->between($start, $end));
            $outs = $this->completed->filter(fn ($visit) => $visit->checked_out_at->between($start, $end));
            $durations = $outs
                ->filter(fn ($visit) => $visit->checked_in_at && $visit->checked_out_at)
                ->map(fn ($visit) => $visit->checked_in_at->diffInMinutes($visit->checked_out_at));

            $rows[] = [
                'label' => $label,
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'checkins' => $ins->count(),
                'completed' => $outs->count(),
                'avg_duration_min' => $durations->isNotEmpty() ? round($durations->avg(), 1) : null,
            ];
        }

        return $rows;
    }

    #[Computed]
    public function chartPayload(): array
    {
        $statuses = [
            'scheduled' => 0,
            'checked_in' => 0,
            'checked_out' => 0,
            'cancelled' => 0,
        ];

        foreach ($this->createdInRange as $visit) {
            if (array_key_exists($visit->status, $statuses)) {
                $statuses[$visit->status]++;
            }
        }

        $hours = array_fill(0, 24, 0);

        foreach ($this->checkIns as $visit) {
            $hours[$visit->checked_in_at->hour]++;
        }

        $hourLabels = [];

        foreach (range(0, 23) as $hour) {
            $hourLabels[] = Carbon::createFromTime($hour)->format('ga');
        }

        return [
            'labels' => array_column($this->bucketRows, 'label'),
            'checkins' => array_column($this->bucketRows, 'checkins'),
            'completed' => array_column($this->bucketRows, 'completed'),
            'status' => array_values($statuses),
            'hours' => $hours,
            'hourLabels' => $hourLabels,
        ];
    }

    #[Computed]
    public function topHosts(): array
    {
        return $this->historyInRange
            ->groupBy(fn ($visit) => $visit->host ?: '—')
            ->map(fn ($group, $host) => ['host' => $host, 'count' => $group->count()])
            ->sortByDesc('count')
            ->take(5)
            ->values()
            ->all();
    }

    #[Computed]
    public function topVisitTypes(): array
    {
        $total = max(1, $this->historyInRange->count());

        return $this->historyInRange
            ->groupBy(fn ($visit) => $visit->visit_type ?: 'Unspecified')
            ->map(fn ($group, $type) => [
                'type' => $type,
                'count' => $group->count(),
                'share' => round($group->count() / $total * 100),
            ])
            ->sortByDesc('count')
            ->take(6)
            ->values()
            ->all();
    }

    public function exportCsv(): StreamedResponse
    {
        $rows = $this->bucketRows;
        [$from, $to] = $this->range();
        $filename = 'reports-'.$from->format('Y-m-d').'-'.$to->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['Bucket', 'Start', 'End', 'Check-ins', 'Completed', 'Avg Duration (min)']);
            foreach ($rows as $row) {
                fputcsv($stream, [$row['label'], $row['start'], $row['end'], $row['checkins'], $row['completed'], $row['avg_duration_min']]);
            }
            fclose($stream);
        }, $filename);
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
    {{-- Header --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading size="lg">{{ __('Reports') }}</flux:heading>
            <flux:subheading>{{ __('Visit activity for :range.', ['range' => $this->rangeLabel]) }}</flux:subheading>
        </div>
        <flux:button variant="primary" wire:click="exportCsv" icon="arrow-up-tray">{{ __('Export CSV') }}</flux:button>
    </div>

    {{-- Filters --}}
    <div class="flex flex-wrap items-end gap-3">
        <div class="flex rounded-xl bg-neutral-100 p-1 dark:bg-neutral-800">
            @foreach (['7' => __('7D'), '14' => __('14D'), '30' => __('30D'), '90' => __('90D')] as $days => $label)
                <button
                    type="button"
                    wire:click="selectPreset('{{ $days }}')"
                    wire:key="preset-{{ $days }}"
                    class="rounded-lg px-3 py-1.5 text-sm font-medium transition-colors {{ $this->preset === (string) $days ? 'bg-white text-neutral-900 shadow-sm dark:bg-neutral-900 dark:text-white' : 'text-neutral-500 hover:text-neutral-900 dark:text-neutral-400 dark:hover:text-white' }}"
                >{{ $label }}</button>
            @endforeach
        </div>
        <div class="w-40">
            <x-date-picker wire:model="dateFrom" placeholder="{{ __('From date') }}" :allow-past="true" />
        </div>
        <div class="w-40">
            <x-date-picker wire:model="dateTo" placeholder="{{ __('To date') }}" :allow-past="true" />
        </div>
        @if ($this->preset === 'custom')
            <span class="pb-2 text-xs font-medium text-neutral-400 dark:text-neutral-500">{{ __('Custom range') }}</span>
        @endif
    </div>

    {{-- KPI cards --}}
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-5">
        <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-neutral-200/80 dark:bg-neutral-900 dark:ring-neutral-700/60">
            <p class="text-xs font-medium uppercase tracking-wider text-neutral-400 dark:text-neutral-500">{{ __('Check-ins') }}</p>
            <p class="mt-1 text-2xl font-bold text-neutral-900 dark:text-white">{{ $this->checkIns->count() }}</p>
        </div>
        <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-neutral-200/80 dark:bg-neutral-900 dark:ring-neutral-700/60">
            <p class="text-xs font-medium uppercase tracking-wider text-neutral-400 dark:text-neutral-500">{{ __('Completed') }}</p>
            <p class="mt-1 text-2xl font-bold text-neutral-900 dark:text-white">{{ $this->completed->count() }}</p>
        </div>
        <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-neutral-200/80 dark:bg-neutral-900 dark:ring-neutral-700/60">
            <p class="text-xs font-medium uppercase tracking-wider text-neutral-400 dark:text-neutral-500">{{ __('Avg duration') }}</p>
            <p class="mt-1 text-2xl font-bold text-neutral-900 dark:text-white">{{ $this->formatDuration($this->avgDurationMinutes) }}</p>
        </div>
        <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-neutral-200/80 dark:bg-neutral-900 dark:ring-neutral-700/60">
            <p class="text-xs font-medium uppercase tracking-wider text-neutral-400 dark:text-neutral-500">{{ __('On-site now') }}</p>
            <p class="mt-1 text-2xl font-bold text-emerald-600 dark:text-emerald-400">{{ $this->onSiteNow }}</p>
        </div>
        <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-neutral-200/80 dark:bg-neutral-900 dark:ring-neutral-700/60">
            <p class="text-xs font-medium uppercase tracking-wider text-neutral-400 dark:text-neutral-500">{{ __('Overdue') }}</p>
            <p class="mt-1 text-2xl font-bold text-amber-600 dark:text-amber-400">{{ $this->overdueCount }}</p>
        </div>
    </div>

    {{-- Charts (rebuilt on filter change via keyed wrapper + x-init; data rides in a JSON block, never in attributes) --}}
    <div wire:key="report-charts-{{ $this->chartVersion }}">
        <script type="application/json" id="reports-chart-data">@json($this->chartPayload)</script>
        <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-neutral-200/80 dark:bg-neutral-900 dark:ring-neutral-700/60">
            <div class="border-b border-neutral-100 px-4 py-3 dark:border-neutral-800">
                <h2 class="text-sm font-semibold tracking-tight text-neutral-900 dark:text-white">{{ __('Check-ins per day') }}</h2>
            </div>
            <div class="p-4">
                <canvas
                    id="reports-daily"
                    class="h-72 w-full"
                    x-data="{ init() {
                        const existing = Chart.getChart('reports-daily');
                        if (existing) existing.destroy();
                        const payload = JSON.parse(document.getElementById('reports-chart-data').textContent);
                        Chart.defaults.color = '#a1a1aa';
                        new Chart(document.getElementById('reports-daily'), {
                            type: 'bar',
                            data: { labels: payload.labels, datasets: [
                                { label: 'Check-ins', data: payload.checkins, backgroundColor: '#10b981', borderRadius: 4 },
                                { label: 'Completed', data: payload.completed, backgroundColor: '#3b82f6', borderRadius: 4 },
                            ] },
                            options: { responsive: true, maintainAspectRatio: false,
                                plugins: { legend: { labels: { boxWidth: 12 } } },
                                scales: {
                                    x: { ticks: { maxTicksLimit: 10 }, grid: { display: false } },
                                    y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: 'rgba(161,161,170,.12)' } },
                                } },
                        });
                    } }"
                    x-init="init()"
                ></canvas>
            </div>
        </div>

        <div class="mt-4 grid gap-4 lg:grid-cols-2">
            <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-neutral-200/80 dark:bg-neutral-900 dark:ring-neutral-700/60">
                <div class="border-b border-neutral-100 px-4 py-3 dark:border-neutral-800">
                    <h2 class="text-sm font-semibold tracking-tight text-neutral-900 dark:text-white">{{ __('Status mix') }}</h2>
                </div>
                <div class="p-4">
                    <canvas
                        id="reports-status"
                        class="mx-auto h-64 w-full max-w-sm"
                        x-data="{ init() {
                            const existing = Chart.getChart('reports-status');
                            if (existing) existing.destroy();
                            const payload = JSON.parse(document.getElementById('reports-chart-data').textContent);
                            Chart.defaults.color = '#a1a1aa';
                            new Chart(document.getElementById('reports-status'), {
                                type: 'doughnut',
                                data: { labels: ['Scheduled', 'On-site', 'Checked out', 'Cancelled'], datasets: [
                                    { data: payload.status, backgroundColor: ['#3b82f6', '#10b981', '#71717a', '#ef4444'], borderWidth: 0 },
                                ] },
                                options: { responsive: true, maintainAspectRatio: false, cutout: '65%',
                                    plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, padding: 16 } } } },
                            });
                        } }"
                        x-init="init()"
                    ></canvas>
                </div>
            </div>

            <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-neutral-200/80 dark:bg-neutral-900 dark:ring-neutral-700/60">
                <div class="border-b border-neutral-100 px-4 py-3 dark:border-neutral-800">
                    <h2 class="text-sm font-semibold tracking-tight text-neutral-900 dark:text-white">{{ __('Peak check-in hours') }}</h2>
                </div>
                <div class="p-4">
                    <canvas
                        id="reports-hours"
                        class="h-64 w-full"
                        x-data="{ init() {
                            const existing = Chart.getChart('reports-hours');
                            if (existing) existing.destroy();
                            const payload = JSON.parse(document.getElementById('reports-chart-data').textContent);
                            Chart.defaults.color = '#a1a1aa';
                            new Chart(document.getElementById('reports-hours'), {
                                type: 'bar',
                                data: { labels: payload.hourLabels, datasets: [
                                    { label: 'Check-ins', data: payload.hours, backgroundColor: '#8b5cf6', borderRadius: 4 },
                                ] },
                                options: { responsive: true, maintainAspectRatio: false,
                                    plugins: { legend: { display: false } },
                                    scales: {
                                        x: { ticks: { maxTicksLimit: 12 }, grid: { display: false } },
                                        y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: 'rgba(161,161,170,.12)' } },
                                    } },
                            });
                        } }"
                        x-init="init()"
                    ></canvas>
                </div>
            </div>
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        {{-- Top hosts --}}
        <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-neutral-200/80 dark:bg-neutral-900 dark:ring-neutral-700/60">
            <div class="border-b border-neutral-100 px-4 py-3 dark:border-neutral-800">
                <h2 class="text-sm font-semibold tracking-tight text-neutral-900 dark:text-white">{{ __('Top hosts') }}</h2>
            </div>
            @if (empty($this->topHosts))
                <p class="px-4 py-6 text-center text-sm text-neutral-400 dark:text-neutral-500">{{ __('No visits in this range.') }}</p>
            @else
                <ul class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    @foreach ($this->topHosts as $index => $row)
                        <li wire:key="report-host-{{ $index }}" class="flex items-center gap-3 px-4 py-2.5 text-sm">
                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-neutral-100 text-xs font-bold text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400">{{ $index + 1 }}</span>
                            <span class="min-w-0 flex-1 truncate font-medium text-neutral-900 dark:text-white">{{ $row['host'] }}</span>
                            <span class="shrink-0 rounded-full bg-emerald-500/10 px-2 py-0.5 text-xs font-bold text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300">{{ $row['count'] }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        {{-- Visit types --}}
        <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-neutral-200/80 dark:bg-neutral-900 dark:ring-neutral-700/60">
            <div class="border-b border-neutral-100 px-4 py-3 dark:border-neutral-800">
                <h2 class="text-sm font-semibold tracking-tight text-neutral-900 dark:text-white">{{ __('Visit types') }}</h2>
            </div>
            @if (empty($this->topVisitTypes))
                <p class="px-4 py-6 text-center text-sm text-neutral-400 dark:text-neutral-500">{{ __('No visits in this range.') }}</p>
            @else
                <ul class="space-y-3 px-4 py-4">
                    @foreach ($this->topVisitTypes as $index => $row)
                        <li wire:key="report-type-{{ $index }}">
                            <div class="flex items-center justify-between gap-3 text-sm">
                                <span class="min-w-0 truncate font-medium text-neutral-900 dark:text-white">{{ $row['type'] }}</span>
                                <span class="shrink-0 text-xs text-neutral-400 dark:text-neutral-500">{{ $row['count'] }} · {{ $row['share'] }}%</span>
                            </div>
                            <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-neutral-100 dark:bg-neutral-800">
                                <div class="h-full rounded-full bg-blue-500" style="width: {{ $row['share'] }}%"></div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>
