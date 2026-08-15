<?php

use App\Models\PreRegistration;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Pre-Registration Complete')] #[Layout('layouts::kiosk')] class extends Component {
    public PreRegistration $preRegistration;

    public function mount(PreRegistration $preRegistration): void
    {
        $this->preRegistration = $preRegistration;
    }
}; ?>

<div class="mx-auto flex min-h-screen w-full max-w-xl flex-col justify-start px-4 py-5 sm:justify-center sm:px-6 sm:py-12">
    <a href="{{ route('home') }}" class="mb-4 inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-700 dark:text-neutral-400 dark:hover:text-neutral-300 sm:mb-8">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
        {{ __('Back to kiosk') }}
    </a>

    @if ($this->preRegistration->status === 'pending')
        <flux:callout variant="success" icon="check-circle" heading="{{ __('Pre-registration complete!') }}" class="mb-6">
            {{ __('Keep this pass handy. When you arrive, scan the QR code at the kiosk — or search your name — and your details will be pre-filled.') }}
        </flux:callout>

        <x-pages::components.pre-registration-card
            :token="$this->preRegistration->qr_code_token"
            :name="$this->preRegistration->name"
            :company="$this->preRegistration->company"
            :purpose="$this->preRegistration->purpose"
            :date="$this->preRegistration->expected_date?->format('M j, Y')"
        />

        <div class="mt-8 text-center">
            <flux:button variant="outline" wire:navigate href="{{ route('pre-register') }}">
                {{ __('Pre-register another visitor') }}
            </flux:button>
        </div>
    @else
        <flux:callout variant="secondary" icon="exclamation-circle" heading="{{ __('This pass is no longer valid') }}" class="mb-6">
            {{ __('This pre-registration has already been used or cancelled. Please register again or check in at the kiosk.') }}
        </flux:callout>

        <div class="text-center">
            <flux:button variant="primary" wire:navigate href="{{ route('pre-register') }}">
                {{ __('Pre-register another visitor') }}
            </flux:button>
        </div>
    @endif
</div>
