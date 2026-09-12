<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Schedule Your Visit')] #[Layout('layouts::kiosk')] class extends Component
{
    //
};
?>

<div class="mx-auto flex min-h-screen w-full max-w-xl flex-col justify-start px-4 py-5 sm:justify-center sm:px-6 sm:py-12">
    <a href="{{ route('home') }}" class="mb-4 inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-700 dark:text-neutral-400 dark:hover:text-neutral-300 sm:mb-8">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
        {{ __('Back to kiosk') }}
    </a>

    <livewire:pages::components.booking-wizard />
</div>
