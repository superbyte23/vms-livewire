<?php

use App\Models\PreRegistration;
use App\Models\User;
use App\Notifications\VisitorPreRegistered;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Pre-Register Your Visit')] #[Layout('layouts::kiosk')] class extends Component {
    public string $name = '';
    public string $email = '';
    public string $phone = '';
    public string $company = '';
    public string $hostSearch = '';
    public string $host = '';
    public ?string $hostUserId = null;
    public bool $useCustomHost = false;
    public string $purpose = '';
    public string $expectedDate = '';

    public bool $submitted = false;

    #[Computed]
    public function availableHosts(): Collection
    {
        if ($this->hostSearch === '') {
            return User::orderBy('name')->limit(10)->get();
        }

        return User::where('name', 'like', '%' . $this->hostSearch . '%')
            ->orderBy('name')
            ->limit(10)
            ->get();
    }

    public function selectHost(string $id, string $name): void
    {
        $this->host = $name;
        $this->hostUserId = $id;
        $this->hostSearch = $name;
        $this->useCustomHost = false;
    }

    public function useCustomHostField(): void
    {
        $this->useCustomHost = true;
        $this->host = '';
        $this->hostUserId = null;
        $this->hostSearch = '';
    }

    public function submit(): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'company' => 'nullable|string|max:255',
            'host' => 'nullable|string|max:255',
            'purpose' => 'nullable|string|max:255',
            'expectedDate' => 'nullable|date',
        ]);

        $preRegistration = PreRegistration::create([
            'name' => $this->name,
            'email' => $this->email ?: null,
            'phone' => $this->phone ?: null,
            'company' => $this->company ?: null,
            'host' => $this->host ?: null,
            'host_user_id' => $this->hostUserId,
            'purpose' => $this->purpose ?: null,
            'expected_date' => $this->expectedDate ?: null,
            'status' => 'pending',
        ]);

        if ($this->hostUserId && $hostUser = User::find($this->hostUserId)) {
            $hostUser->notify(new VisitorPreRegistered($preRegistration));
        }

        $this->submitted = true;
        $this->reset('name', 'email', 'phone', 'company', 'hostSearch', 'host', 'hostUserId', 'useCustomHost', 'purpose', 'expectedDate');

        Flux::toast(variant: 'success', text: 'Pre-registration submitted. You can check in at the kiosk when you arrive.');
    }

    public function registerAnother(): void
    {
        $this->submitted = false;
    }
}; ?>

<div class="mx-auto flex min-h-screen w-full max-w-xl flex-col justify-center px-6 py-12">
    <a href="{{ route('home') }}" class="mb-8 inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-700 dark:text-neutral-400 dark:hover:text-neutral-300">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
        {{ __('Back to kiosk') }}
    </a>

    <div class="rounded-2xl border border-neutral-200 bg-white p-8 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        @if ($this->submitted)
            <div class="text-center">
                <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-900/40">
                    <svg class="h-8 w-8 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                </div>
                <flux:heading size="xl" class="mt-4">{{ __('Pre-registration complete!') }}</flux:heading>
                <p class="mt-2 text-neutral-500 dark:text-neutral-400">
                    {{ __('When you arrive, search your name at the kiosk and your details will be pre-filled.') }}
                </p>
                <flux:button variant="outline" class="mt-6" wire:click="registerAnother">
                    {{ __('Pre-register another visitor') }}
                </flux:button>
            </div>
        @else
            <flux:heading size="xl">{{ __('Pre-Register Your Visit') }}</flux:heading>
            <p class="mt-2 text-sm text-neutral-500 dark:text-neutral-400">
                {{ __('Book your visit ahead of time so check-in at the kiosk is faster.') }}
            </p>

            <div class="mt-6 space-y-4">
                <flux:input wire:model="name" label="{{ __('Full Name') }}" type="text" required placeholder="{{ __('e.g. John Doe') }}" />
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <flux:input wire:model="email" label="{{ __('Email') }}" type="email" placeholder="{{ __('e.g. john@example.com') }}" />
                    <flux:input wire:model="phone" label="{{ __('Phone') }}" type="tel" placeholder="{{ __('e.g. +1 555-1234') }}" />
                </div>
                <flux:input wire:model="company" label="{{ __('Company') }}" placeholder="{{ __('e.g. Acme Corp') }}" />

                @if (! $this->useCustomHost)
                    <div>
                        <flux:input wire:model.live="hostSearch" label="{{ __('Who are you visiting?') }}" placeholder="{{ __('Type name to search...') }}" />
                        @if ($this->hostSearch !== '')
                            <div class="mt-2 max-h-40 overflow-y-auto rounded-lg border border-neutral-200 dark:border-neutral-700">
                                @forelse ($this->availableHosts as $employee)
                                    <button type="button" wire:click="selectHost('{{ $employee->id }}', '{{ $employee->name }}')" class="w-full px-4 py-3 text-left text-sm hover:bg-neutral-50 dark:hover:bg-neutral-800 border-b border-neutral-100 dark:border-neutral-700 last:border-0">
                                        <span class="font-medium text-neutral-900 dark:text-white">{{ $employee->name }}</span>
                                        <span class="ml-2 text-neutral-400 dark:text-neutral-500">{{ $employee->email }}</span>
                                    </button>
                                @empty
                                    <p class="p-4 text-sm text-neutral-400 dark:text-neutral-500">{{ __('No employees found.') }}</p>
                                @endforelse
                            </div>
                        @endif
                        @if ($this->host !== '')
                            <div class="mt-2 flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 dark:border-emerald-800 dark:bg-emerald-900/20">
                                <span class="text-sm text-neutral-700 dark:text-neutral-300">{{ __('Visiting: ') }}<strong>{{ $this->host }}</strong></span>
                                <button type="button" wire:click="useCustomHostField" class="ml-auto text-xs text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-300">{{ __('Change') }}</button>
                            </div>
                        @else
                            <button type="button" wire:click="useCustomHostField" class="mt-2 text-sm text-emerald-600 hover:text-emerald-700 dark:text-emerald-400 dark:hover:text-emerald-300">
                                {{ __('Someone not in the directory?') }}
                            </button>
                        @endif
                    </div>
                @else
                    <flux:input wire:model="host" label="{{ __('Whom are you visiting?') }}" placeholder="{{ __('e.g. Sarah Johnson') }}" />
                    <button type="button" wire:click="$set('useCustomHost', false)" class="text-sm text-emerald-600 hover:text-emerald-700 dark:text-emerald-400 dark:hover:text-emerald-300">
                        {{ __('Search employee directory') }}
                    </button>
                @endif

                <flux:input wire:model="purpose" label="{{ __('Purpose of visit') }}" placeholder="{{ __('e.g. Meeting, Interview, Delivery') }}" />
                <flux:input wire:model="expectedDate" label="{{ __('Expected date (optional)') }}" type="date" />

                <flux:button variant="primary" class="w-full !py-3 text-base" wire:click="submit">
                    {{ __('Submit Pre-Registration') }}
                </flux:button>
            </div>
        @endif
    </div>
</div>
