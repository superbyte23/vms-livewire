<?php

use App\Models\Visitor;
use App\Services\VisitorCheckInService;
use App\Models\Visit;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public int $step = 1;

    // Step 1: date schedule
    public string $expectedDate = '';

    // Step 2: visit type / event
    public string $visitType = '';

    public string $purpose = '';

    // Step 3: search your record first, new-visitor form as fallback
    public string $visitorSearch = '';

    public ?string $selectedVisitorId = null;

    public bool $isNewVisitor = false;

    public string $firstname = '';

    public string $middlename = '';

    public string $lastname = '';

    public string $email = '';

    public string $phone = '';

    public string $company = '';

    public string $address = '';

    public string $validIdPhoto = '';

    public string $profilePhoto = '';

    public ?string $createdBookingId = null;

    public ?string $checkedInBadge = null;

    public string $checkinPhoto = '';

    /**
     * Single rulebook for the wizard so inline error helpers stay reactive
     * while typing (see updated()) and identical on Next/Confirm.
     *
     * @return array<string, string>
     */
    protected function wizardRules(): array
    {
        return [
            'expectedDate' => 'required|date|after_or_equal:today',
            'visitType' => 'required|string|max:255',
            'purpose' => 'nullable|string|max:255',
            'selectedVisitorId' => 'required|string|exists:visitors,id',
            'firstname' => 'required|string|max:255',
            'middlename' => 'nullable|string|max:255',
            'lastname' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'company' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'validIdPhoto' => 'nullable|string',
            'profilePhoto' => 'nullable|string',
            'checkinPhoto' => 'nullable|string',
        ];
    }

    /**
     * Reactive error helpers: validate each field as it changes so inline
     * errors appear/clear while typing. Silent here — the warning toast only
     * fires on Next/Confirm attempts.
     */
    public function updated(string $name, mixed $value): void
    {
        if (! array_key_exists($name, $this->wizardRules())) {
            return;
        }

        try {
            $this->validateOnly($name, $this->wizardRules());
        } catch (ValidationException $e) {
            // Swallowing here keeps the framework from persisting the errors,
            // so push this field's message into the bag for inline helpers.
            // Silent otherwise — the warning toast only fires on Next/Confirm.
            if ($message = $e->validator->errors()->first($name)) {
                $this->addError($name, $message);
            }
        }
    }

    public function next(): void
    {
        try {
            if ($this->step === 1) {
                $this->validate(Arr::only($this->wizardRules(), ['expectedDate']));
                $this->step = 2;
            } elseif ($this->step === 2) {
                $this->validate(Arr::only($this->wizardRules(), ['visitType', 'purpose']));
                $this->step = 3;
            } elseif ($this->step === 3) {
                if ($this->isNewVisitor) {
                    $this->validate(Arr::only($this->wizardRules(), [
                        'firstname', 'middlename', 'lastname', 'email', 'phone',
                        'company', 'address', 'validIdPhoto', 'profilePhoto',
                    ]));
                } else {
                    $this->validate(Arr::only($this->wizardRules(), ['selectedVisitorId']));

                    if (Visit::hasActiveBooking($this->selectedVisitorId)) {
                        $this->addError('selectedVisitorId', __('This visitor already has a scheduled visit.'));
                        Flux::toast(variant: 'warning', text: __('Booking blocked: visitor already has a scheduled visit.'));

                        return;
                    }

                    if (Visit::isOnSite($this->selectedVisitorId)) {
                        $this->addError('selectedVisitorId', __('This visitor is already on-site.'));
                        Flux::toast(variant: 'warning', text: __('Booking blocked: visitor is already on-site.'));

                        return;
                    }
                }
                $this->step = 4;
            }
        } catch (ValidationException $e) {
            Flux::toast(variant: 'warning', text: __('Please complete the required fields before continuing.'));

            throw $e;
        }
    }

    #[Computed]
    public function visitorMatches(): Collection
    {
        if (trim($this->visitorSearch) === '') {
            return new Collection;
        }

        return Visitor::search($this->visitorSearch)->orderBy('name')->limit(6)->get();
    }

    #[Computed]
    public function selectedVisitor(): ?Visitor
    {
        return $this->selectedVisitorId ? Visitor::find($this->selectedVisitorId) : null;
    }

    #[Computed]
    public function selectedVisitorBooking(): ?Visit
    {
        if ($this->selectedVisitorId === null) {
            return null;
        }

        return Visit::activeBookingFor($this->selectedVisitorId);
    }

    #[Computed]
    public function selectedVisitorOnSite(): bool
    {
        if ($this->selectedVisitorId === null) {
            return false;
        }

        return Visit::isOnSite($this->selectedVisitorId);
    }

    public function selectVisitor(string $id): void
    {
        $this->selectedVisitorId = (string) Visitor::findOrFail($id)->id;
        $this->visitorSearch = '';
    }

    public function clearSelectedVisitor(): void
    {
        $this->selectedVisitorId = null;
        $this->visitorSearch = '';
    }

    public function startNewVisitor(): void
    {
        $this->isNewVisitor = true;
        $this->selectedVisitorId = null;
        $this->visitorSearch = '';
    }

    public function cancelNewVisitor(): void
    {
        $this->isNewVisitor = false;
        $this->reset('firstname', 'middlename', 'lastname', 'email', 'phone', 'company', 'address', 'validIdPhoto', 'profilePhoto');
    }

    public function updatedIsNewVisitor(bool $value): void
    {
        if ($value) {
            $this->selectedVisitorId = null;
            $this->visitorSearch = '';
        } else {
            $this->cancelNewVisitor();
        }
    }

    public function back(): void
    {
        $this->step = max(1, $this->step - 1);
    }

    public function confirm(): void
    {
        try {
            $this->validate(Arr::only($this->wizardRules(), ['expectedDate', 'visitType', 'purpose']));
        } catch (ValidationException $e) {
            Flux::toast(variant: 'warning', text: __('Please complete the required fields before confirming.'));

            throw $e;
        }

        // Single source of truth: link the found record, otherwise register
        // the new visitor (reusing a matching email as a final safety net).
        // Either way, one active booking per visitor — duplicates are blocked.
        $visitor = $this->selectedVisitorId ? Visitor::findOrFail($this->selectedVisitorId) : null;

        if ($visitor && Visit::hasActiveBooking($visitor->id)) {
            $this->addError('selectedVisitorId', __('This visitor already has a scheduled visit.'));
            Flux::toast(variant: 'warning', text: __('Booking blocked: visitor already has a scheduled visit.'));

            return;
        }

        if ($visitor && Visit::isOnSite($visitor->id)) {
            $this->addError('selectedVisitorId', __('This visitor is already on-site.'));
            Flux::toast(variant: 'warning', text: __('Booking blocked: visitor is already on-site.'));

            return;
        }

        if (! $visitor) {
            try {
                $this->validate(Arr::only($this->wizardRules(), [
                    'firstname', 'middlename', 'lastname', 'email', 'phone',
                    'company', 'address', 'validIdPhoto', 'profilePhoto',
                ]));
            } catch (ValidationException $e) {
                Flux::toast(variant: 'warning', text: __('Please complete the required fields before confirming.'));

                throw $e;
            }

            if ($this->email !== '') {
                $visitor = Visitor::where('email', $this->email)->first();

                if ($visitor && Visit::hasActiveBooking($visitor->id)) {
                    $this->addError('email', __('This email already has a scheduled visit.'));
                    Flux::toast(variant: 'warning', text: __('Booking blocked: visitor already has a scheduled visit.'));

                    return;
                }

                if ($visitor && Visit::isOnSite($visitor->id)) {
                    $this->addError('email', __('This visitor is already on-site.'));
                    Flux::toast(variant: 'warning', text: __('Booking blocked: visitor is already on-site.'));

                    return;
                }
            }

            $visitor ??= Visitor::create([
                'firstname' => $this->firstname,
                'middlename' => $this->middlename ?: null,
                'lastname' => $this->lastname,
                'name' => Visitor::composeName($this->firstname, $this->middlename ?: null, $this->lastname),
                'email' => $this->email ?: null,
                'phone' => $this->phone ?: null,
                'company' => $this->company ?: null,
                'address' => $this->address ?: null,
                'government_id_photo' => $this->validIdPhoto ?: null,
                'photo' => $this->profilePhoto ?: null,
                'qr_code_token' => Str::random(32),
            ]);
        }

        $booking = Visit::create([
            'visitor_id' => $visitor->id,
            'visit_type' => $this->visitType ?: null,
            'purpose' => $this->purpose ?: null,
            'expected_date' => $this->expectedDate,
            'status' => 'scheduled',
            'qr_code_token' => Str::random(32),
        ]);

        $this->createdBookingId = (string) $booking->id;
        $this->step = 5;

        $this->dispatch('visitor-registered', bookingId: (string) $booking->id);
    }

    #[Computed]
    public function canCheckInNow(): bool
    {
        $booking = $this->createdBooking;

        return $booking !== null
            && $booking->status === 'scheduled'
            && $booking->expected_date?->toDateString() === now()->toDateString()
            && $this->checkedInBadge === null;
    }

    public function checkInNow(): void
    {
        $booking = $this->createdBooking;

        if (! $booking || $booking->status !== 'scheduled') {
            Flux::toast(variant: 'danger', text: __('This booking is no longer available.'));

            return;
        }

        $result = app(VisitorCheckInService::class)->checkIn(['booking_id' => $booking->id, 'visit_photo' => $this->checkinPhoto ?: null]);

        $this->checkedInBadge = $result['badge_number'];

        Flux::toast(variant: 'success', text: __('Checked in! Badge: :badge', ['badge' => $result['badge_number']]));
    }

    public function bookAnother(): void
    {
        $this->reset(
            'step', 'expectedDate', 'visitType', 'purpose',
            'visitorSearch', 'selectedVisitorId', 'isNewVisitor',
            'firstname', 'middlename', 'lastname', 'email', 'phone', 'company', 'address', 'validIdPhoto', 'profilePhoto',
            'createdBookingId', 'checkedInBadge', 'checkinPhoto'
        );
        $this->step = 1;
    }

    #[Computed]
    public function createdBooking(): ?Visit
    {
        if ($this->createdBookingId === null) {
            return null;
        }

        return Visit::with('visitor')->find($this->createdBookingId);
    }
}; ?>

<div class="rounded-2xl border border-neutral-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 sm:p-8">
        <div>
            <div class="flex items-center justify-between text-xs font-medium">
                <span class="text-neutral-500 dark:text-neutral-400">{{ __('Step :current of 5', ['current' => min($this->step, 5)]) }}</span>
                <span class="text-neutral-400 dark:text-neutral-500">{{ round(min($this->step, 5) / 5 * 100) }}%</span>
            </div>
            <div class="mt-1.5 flex gap-1">
                @for ($number = 1; $number <= 5; $number++)
                    <div class="h-1.5 flex-1 rounded-full {{ $this->step >= $number ? 'bg-emerald-500' : 'bg-neutral-200 dark:bg-neutral-700' }}"></div>
                @endfor
            </div>
        </div>

        <div class="mt-5">
            @if ($this->step === 1)
                <flux:heading size="lg">{{ __('Date') }}</flux:heading>
                <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">{{ __('When are you visiting?') }}</p>
            @elseif ($this->step === 2)
                <flux:heading size="lg">{{ __('Visit Type') }}</flux:heading>
                <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">{{ __('What is this visit for?') }}</p>
            @elseif ($this->step === 3)
                <flux:heading size="lg">{{ __('Visitor') }}</flux:heading>
                <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">{{ __('Find your record, or add yourself.') }}</p>
            @elseif ($this->step === 4)
                <flux:heading size="lg">{{ __('Confirm') }}</flux:heading>
                <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">{{ __('Check the details, then book.') }}</p>
            @endif
        </div>

        @if (in_array($this->step, [2, 3, 4], true))
            <div class="mt-3 flex flex-wrap gap-1.5 text-xs">
                <span class="inline-flex items-center gap-1 rounded-full bg-neutral-100 px-2.5 py-1 font-medium text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">
                    {{ __('Date: :date', ['date' => $this->expectedDate ?: '—']) }}
                </span>
                @if ($this->step >= 3)
                    <span class="inline-flex items-center gap-1 rounded-full bg-neutral-100 px-2.5 py-1 font-medium text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">
                        {{ __('Type: :type', ['type' => $this->visitType ?: '—']) }}
                    </span>
                @endif
                @if ($this->step === 4)
                    <span class="inline-flex items-center gap-1 rounded-full bg-neutral-100 px-2.5 py-1 font-medium text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">
                        {{ __('Visitor: :name', ['name' => $this->isNewVisitor ? (trim($this->firstname.' '.$this->lastname) ?: '—') : ($this->selectedVisitor?->name ?? '—')]) }}
                    </span>
                @endif
            </div>
        @endif

        <div class="mt-4 space-y-3 sm:mt-6 sm:space-y-4">
            @if ($this->step === 1)
                <x-date-picker wire:model="expectedDate" label="{{ __('Schedule date (required)') }}" />
                <flux:error name="expectedDate" />
                <p class="text-xs text-neutral-400 dark:text-neutral-500">{{ __('Tip: your QR pass works all day on the date you pick.') }}</p>

                <flux:button variant="primary" class="w-full !py-3 text-base" wire:click="next">
                    {{ __('Continue') }}
                </flux:button>
            @elseif ($this->step === 2)
                <flux:select wire:model.live="visitType" label="{{ __('Visit Type or Purpose of Visit (required)') }}">
                    <option value="">{{ __('— Select type —') }}</option>
                    @foreach (\App\Models\Visit::VISIT_TYPES as $type)
                        <option value="{{ $type }}">{{ $type }}</option>
                    @endforeach
                </flux:select>
                <flux:textarea wire:model.live="purpose" label="{{ __('Details') }}" placeholder="{{ __('e.g. Q3 review meeting with Jane from Sales') }}" rows="3" />

                <div class="flex gap-2">
                    <flux:button variant="ghost" wire:click="back">{{ __('Back') }}</flux:button>
                    <flux:button variant="primary" class="flex-1 !py-3 text-base" wire:click="next">
                        {{ __('Continue') }}
                    </flux:button>
                </div>
            @elseif ($this->step === 3)
                @if ($this->isNewVisitor)
                                        @include('pages::components.visitor-identity-fields')
                @elseif ($this->selectedVisitor)
                    <div class="flex items-center justify-between rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 dark:border-emerald-800 dark:bg-emerald-900/20">
                        <span class="text-sm font-medium text-emerald-700 dark:text-emerald-300">{{ $this->selectedVisitor->name }}</span>
                        <flux:button variant="ghost" wire:click="clearSelectedVisitor">{{ __('Change') }}</flux:button>
                    </div>
                    @if ($this->selectedVisitorBooking)
                        <flux:callout variant="warning" icon="exclamation-circle" heading="{{ __('Already scheduled') }}" class="mt-2">
                            {{ __('This visitor already has a scheduled visit on :date. You cannot book another one.', ['date' => $this->selectedVisitorBooking->expected_date?->format('M j, Y') ?? '—']) }}
                        </flux:callout>
                    @elseif ($this->selectedVisitorOnSite)
                        <flux:callout variant="danger" icon="exclamation-circle" heading="{{ __('Already on-site') }}" class="mt-2">
                            {{ __('This visitor is currently checked in. You cannot schedule a visit for them.') }}
                        </flux:callout>
                    @endif
                @else
                    <flux:input wire:model.live.debounce.300ms="visitorSearch" label="{{ __('Search your visitor record') }}" placeholder="{{ __('Type your name, email, or phone...') }}" icon="magnifying-glass" />
                    <flux:error name="selectedVisitorId" />
                    @if (count($this->visitorMatches) > 0)
                        <div class="overflow-hidden rounded-lg border border-neutral-200 dark:border-neutral-700">
                            @foreach ($this->visitorMatches as $visitor)
                                <button type="button" wire:click="selectVisitor('{{ $visitor->id }}')" wire:key="schedule-search-{{ $visitor->id }}" class="flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-neutral-50 dark:hover:bg-neutral-800">
                                    <span>{{ $visitor->name }}</span>
                                    <span class="text-xs text-neutral-400">{{ $visitor->email ?: $visitor->phone }}</span>
                                </button>
                            @endforeach
                        </div>
                    @elseif (trim($this->visitorSearch) !== '')
                        <p class="text-sm font-medium text-red-600 dark:text-red-400">{{ __('No record found — add yourself as a new visitor below.') }}</p>
                    @endif
                @endif

                <flux:switch wire:model.live="isNewVisitor" label="{{ __('Registering a new visitor?') }}" description="{{ __('Turn on to enter their details instead of searching') }}" />

                <div class="flex gap-2">
                    <flux:button variant="ghost" wire:click="back">{{ __('Back') }}</flux:button>
                    <flux:button variant="primary" class="flex-1 !py-3 text-base" wire:click="next">
                        {{ __('Review') }}
                    </flux:button>
                </div>
            @elseif ($this->step === 4)
                <dl class="divide-y divide-neutral-100 rounded-lg border border-neutral-200 text-sm dark:divide-neutral-800 dark:border-neutral-700">
                    <div class="flex items-center justify-between gap-4 px-4 py-2.5">
                        <dt class="text-neutral-500 dark:text-neutral-400">{{ __('Date') }}</dt>
                        <dd class="font-medium text-neutral-900 dark:text-white">{{ $this->expectedDate ?: '—' }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-4 px-4 py-2.5">
                        <dt class="text-neutral-500 dark:text-neutral-400">{{ __('Visit type') }}</dt>
                        <dd class="font-medium text-neutral-900 dark:text-white">{{ $this->visitType ?: '—' }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-4 px-4 py-2.5">
                        <dt class="text-neutral-500 dark:text-neutral-400">{{ __('Visitor') }}</dt>
                        <dd class="text-right font-medium text-neutral-900 dark:text-white">{{ $this->isNewVisitor ? (\App\Models\Visitor::composeName($this->firstname, $this->middlename, $this->lastname) ?: '—') . ' (' . __('new') . ')' : ($this->selectedVisitor?->name ?? '—') }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-4 px-4 py-2.5">
                        <dt class="text-neutral-500 dark:text-neutral-400">{{ __('Purpose') }}</dt>
                        <dd class="max-w-60 truncate text-right font-medium text-neutral-900 dark:text-white">{{ $this->purpose ?: '—' }}</dd>
                    </div>
                </dl>

                <div class="flex gap-2">
                    <flux:button variant="ghost" wire:click="back">{{ __('Back') }}</flux:button>
                    <flux:button variant="primary" class="flex-1 !py-3 text-base" wire:click="confirm" icon="check">
                        {{ __('Confirm Schedule') }}
                    </flux:button>
                </div>
            @else
                <flux:callout variant="success" icon="check-circle" heading="{{ __('Visit scheduled!') }}">
                    {{ __('Show this pass at the kiosk on your visit date.') }}
                </flux:callout>

                @if ($this->createdBooking)
                    <x-pages::components.pre-registration-card
                        title="{{ __('Scheduled Visit Pass') }}"
                        :token="$this->createdBooking->qr_code_token"
                        :name="$this->createdBooking->visitor?->name ?? 'Visitor'"
                        :company="$this->createdBooking->visitor?->company"
                        :purpose="$this->createdBooking->purpose"
                        :date="$this->createdBooking->expected_date?->format('M j, Y')"
                    />
                @endif

                @if ($this->canCheckInNow)
                    @include('pages::components.photo-capture', ['model' => 'checkinPhoto', 'label' => __('Check-in photo (optional)'), 'hint' => __('Selfie at check-in time'), 'title' => __('Check-in photo')])
                    <flux:button variant="primary" class="w-full !py-3" wire:click="checkInNow">
                        {{ __('Check in now') }}
                    </flux:button>
                @elseif ($this->checkedInBadge)
                    <div class="rounded-xl bg-emerald-50 px-4 py-3 text-center ring-1 ring-emerald-200 dark:bg-emerald-900/20 dark:ring-emerald-800">
                        <p class="text-sm font-semibold text-emerald-700 dark:text-emerald-300">{{ __('Checked in!') }}</p>
                        <p class="mt-0.5 text-xs text-emerald-600 dark:text-emerald-400">{{ __('Badge: :badge', ['badge' => $this->checkedInBadge]) }}</p>
                    </div>
                @endif

                <flux:button variant="outline" class="w-full !py-3" wire:click="bookAnother">
                    {{ __('Schedule another visit') }}
                </flux:button>
            @endif
        </div>
    </div>
