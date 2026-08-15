@props([
    'label' => null,
    'placeholder' => __('Select a date'),
    'allowPast' => false,
])

@php
    $model = $attributes->wire('model')->value();
    $id = $attributes->get('id', $model ?: 'date-picker');
@endphp

<div x-data="{
    open: false,
    mode: 'days',
    selected: @if ($model) @entangle($model) @else '' @endif,
    viewYear: null,
    viewMonth: null,
    init() {
        const now = new Date();
        this.viewYear = now.getFullYear();
        this.viewMonth = now.getMonth();
    },
    currentYear() { return new Date().getFullYear(); },
    currentMonth() { return new Date().getMonth(); },
    todayISO() {
        const n = new Date();
        return n.getFullYear() + '-' + String(n.getMonth() + 1).padStart(2, '0') + '-' + String(n.getDate()).padStart(2, '0');
    },
    minISO() {
        @if ($allowPast)
            return null;
        @else
            const n = new Date();
            return n.getFullYear() + '-' + String(n.getMonth() + 1).padStart(2, '0') + '-' + String(n.getDate()).padStart(2, '0');
        @endif
    },
    openPicker() {
        if (this.selected) {
            const [y, m] = this.selected.split('-').map(Number);
            this.viewYear = y;
            this.viewMonth = m - 1;
        }
        this.open = true;
    },
    get canPrev() {
        @if ($allowPast)
            return true;
        @else
            const now = new Date();
            return (this.viewYear > now.getFullYear()) || (this.viewYear === now.getFullYear() && this.viewMonth > now.getMonth());
        @endif
    },
    prevMonth() {
        this.viewMonth--;
        if (this.viewMonth < 0) { this.viewMonth = 11; this.viewYear--; }
    },
    nextMonth() {
        this.viewMonth++;
        if (this.viewMonth > 11) { this.viewMonth = 0; this.viewYear++; }
    },
    canPrevYear() {
        @if ($allowPast)
            return true;
        @else
            return this.viewYear > this.currentYear();
        @endif
    },
    prevYear() {
        @if ($allowPast)
            this.viewYear--;
        @else
            if (this.viewYear > this.currentYear()) this.viewYear--;
        @endif
    },
    nextYear() {
        this.viewYear++;
    },
    days() {
        const start = (new Date(this.viewYear, this.viewMonth, 1).getDay() + 6) % 7;
        const total = new Date(this.viewYear, this.viewMonth + 1, 0).getDate();
        const min = this.minISO();
        const cells = [];
        for (let i = 0; i < start; i++) cells.push({ off: true });
        for (let d = 1; d <= total; d++) {
            const iso = this.viewYear + '-' + String(this.viewMonth + 1).padStart(2, '0') + '-' + String(d).padStart(2, '0');
            cells.push({ off: false, iso, day: d, disabled: min && iso < min, today: iso === this.todayISO() });
        }
        return cells;
    },
    months() {
        const out = [];
        const cy = this.currentYear(), cm = this.currentMonth();
        const min = this.minISO();
        for (let i = 0; i < 12; i++) {
            out.push({ i, name: new Date(2000, i, 1).toLocaleDateString(undefined, { month: 'short' }), disabled: min && this.viewYear === cy && i < cm });
        }
        return out;
    },
    pickMonth(i) {
        this.viewMonth = i;
        this.mode = 'days';
    },
    select(iso) {
        this.selected = iso;
        this.open = false;
    },
    monthLabel() {
        return new Date(this.viewYear, this.viewMonth, 1).toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
    }
}">
    @if ($label)
        <label for="{{ $id }}" class="text-sm font-medium text-neutral-700 dark:text-neutral-300">{{ $label }}</label>
    @endif
    <button type="button" x-on:click="openPicker()" id="{{ $id }}" name="{{ $model ?? '' }}"
        class="{{ $label ? 'mt-1 ' : '' }}flex h-10 w-full items-center justify-between gap-2 rounded-lg border border-zinc-200 border-b-zinc-300/80 bg-white px-3 text-base leading-[1.375rem] text-zinc-700 shadow-xs dark:border-white/10 dark:bg-white/10 dark:text-zinc-300 sm:text-sm">
        <span x-text="selected ? new Date(selected + 'T00:00:00').toLocaleDateString(undefined, { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' }) : @js($placeholder)"
            x-bind:class="selected ? 'text-zinc-700 dark:text-zinc-300' : 'text-zinc-400 dark:text-zinc-500'"
            class="truncate"></span>
        <flux:icon.calendar-days class="h-4 w-4 shrink-0 text-zinc-400 dark:text-white/60" />
    </button>

    <div x-show="open" x-cloak
        x-transition.opacity.duration.150
        class="fixed inset-0 z-[90] flex items-end justify-center bg-black/50 sm:items-center sm:bg-black/60"
        x-on:click="open = false">
        <div x-on:click.stop
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="translate-y-full sm:translate-y-0 sm:scale-95"
            x-transition:enter-end="translate-y-0 sm:scale-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="translate-y-0 sm:scale-100"
            x-transition:leave-end="translate-y-full sm:translate-y-0 sm:scale-95"
            class="w-full max-w-md rounded-t-2xl bg-white p-5 pb-6 shadow-xl dark:bg-neutral-900 sm:rounded-2xl">
            <div class="flex items-center justify-between">
                <button type="button" x-on:click="mode === 'days' ? prevMonth() : prevYear()" x-bind:disabled="mode === 'days' ? !canPrev : !canPrevYear()"
                    class="flex h-9 w-9 items-center justify-center rounded-lg text-neutral-600 hover:bg-neutral-100 disabled:opacity-30 dark:text-neutral-300 dark:hover:bg-neutral-800">
                    <flux:icon.chevron-left class="h-5 w-5" />
                </button>
                <button type="button" x-on:click="mode = mode === 'days' ? 'months' : 'days'"
                    class="flex items-center gap-1 rounded-lg px-2 py-1 text-base font-semibold text-neutral-900 hover:bg-neutral-100 dark:text-white dark:hover:bg-neutral-800">
                    <span x-text="mode === 'days' ? monthLabel() : viewYear"></span>
                    <flux:icon.chevron-down x-show="mode === 'days'" class="h-4 w-4 text-neutral-400 dark:text-neutral-500" />
                    <flux:icon.chevron-up x-show="mode === 'months'" class="h-4 w-4 text-neutral-400 dark:text-neutral-500" />
                </button>
                <button type="button" x-on:click="mode === 'days' ? nextMonth() : nextYear()"
                    class="flex h-9 w-9 items-center justify-center rounded-lg text-neutral-600 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                    <flux:icon.chevron-right class="h-5 w-5" />
                </button>
            </div>
            <div x-show="mode === 'days'">
                <div class="mt-4 grid grid-cols-7 gap-1 text-center text-xs font-medium text-neutral-400 dark:text-neutral-500">
                    <template x-for="w in ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su']" :key="w">
                        <span x-text="w" class="py-1"></span>
                    </template>
                </div>
                <div class="mt-1 grid grid-cols-7 gap-1">
                    <template x-for="(cell, i) in days()" :key="i">
                        <div x-show="!cell.off" x-on:click="cell.disabled || select(cell.iso)"
                            x-bind:class="selected === cell.iso
                                ? 'bg-emerald-600 text-white shadow-sm'
                                : (cell.today
                                    ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-400'
                                    : (cell.disabled ? 'text-neutral-300 dark:text-neutral-600' : 'text-neutral-700 hover:bg-neutral-100 dark:text-neutral-200 dark:hover:bg-neutral-800'))"
                            class="flex h-11 cursor-pointer items-center justify-center rounded-lg text-sm font-medium">
                            <span x-text="cell.day"></span>
                        </div>
                    </template>
                </div>
                <div class="mt-4 flex items-center justify-between">
                    <flux:button variant="ghost" size="sm" x-on:click="select(todayISO())">{{ __('Today') }}</flux:button>
                    <flux:button variant="primary" size="sm" x-on:click="open = false">{{ __('Done') }}</flux:button>
                </div>
            </div>
            <div x-show="mode === 'months'">
                <div class="mt-4 grid grid-cols-3 gap-1">
                    <template x-for="m in months()" :key="m.i">
                        <button type="button" x-on:click="!m.disabled && pickMonth(m.i)" x-bind:disabled="m.disabled"
                            x-bind:class="viewMonth === m.i
                                ? 'bg-emerald-600 text-white shadow-sm'
                                : (m.disabled ? 'text-neutral-300 dark:text-neutral-600' : 'text-neutral-700 hover:bg-neutral-100 dark:text-neutral-200 dark:hover:bg-neutral-800')"
                            class="flex h-11 items-center justify-center rounded-lg text-sm font-medium disabled:cursor-not-allowed">
                            <span x-text="m.name"></span>
                        </button>
                    </template>
                </div>
            </div>
        </div>
    </div>
</div>
