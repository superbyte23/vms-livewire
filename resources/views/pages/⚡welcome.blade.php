<?php

use App\Models\User;
use App\Models\Visitor;
use App\Models\VisitorLog;
use App\Notifications\VisitorCheckedIn;
use App\Notifications\VisitorCheckedOut;
use App\Notifications\VisitorFlagged;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use thiagoalessio\TesseractOCR\TesseractOCR;

new #[Title('Visitor Kiosk')] #[Layout('layouts::kiosk')] class extends Component {
    use WithFileUploads;

    public int $step = 1;

    // Step 1: Search or create person
    public string $search = '';
    public ?string $selectedVisitorId = null;
    public bool $showCreateForm = false;

    // Step 1: New person fields (ID photo → visitors.photo)
    public string $name = '';
    public string $email = '';
    public string $phone = '';
    public string $company = '';
    public string $validIdNumber = '';
    public string $photo = '';

    // ID scan (OCR)
    public ?TemporaryUploadedFile $idCardImage = null;
    public string $idCardPreview = '';
    public string $idOcrText = '';
    public bool $showIdScanModal = false;
    public bool $processingIdCard = false;

    // Step 2: Visit details
    public string $hostSearch = '';
    public string $host = '';
    public ?string $hostUserId = null;
    public bool $useCustomHost = false;
    public string $purpose = '';
    public string $visitPhoto = '';  // visit selfie → visitor_logs.photo

    // Check-out
    public bool $justCheckedIn = false;
    public ?VisitorLog $lastLog = null;
    public string $checkoutSearch = '';
    public ?string $pendingCheckoutId = null;
    public bool $showCheckoutModal = false;

    // QR
    public bool $showQrScanner = false;
    public bool $showQrCheckoutModal = false;
    public ?string $pendingQrCheckoutToken = null;

    public function mount(): void
    {
        $token = request()->query('checkout');
        if ($token) {
            $visitorLog = VisitorLog::where('qr_code_token', $token)
                ->where('status', 'checked_in')
                ->with('visitor')
                ->first();

            if ($visitorLog) {
                $this->pendingQrCheckoutToken = $token;
                $this->showQrCheckoutModal = true;
            } else {
                Flux::toast(variant: 'error', text: __('Invalid or already checked out QR code.'));
            }
        }
    }

    #[Computed]
    public function pendingQrVisitor(): ?VisitorLog
    {
        if (! $this->pendingQrCheckoutToken) {
            return null;
        }

        return VisitorLog::where('qr_code_token', $this->pendingQrCheckoutToken)
            ->where('status', 'checked_in')
            ->with('visitor')
            ->first();
    }

    public function confirmQrCheckOut(): void
    {
        $visitorLog = $this->pendingQrVisitor;

        if (! $visitorLog) {
            Flux::toast(variant: 'error', text: 'Invalid or already checked out QR code.');

            return;
        }

        $visitorLog->update([
            'status' => 'checked_out',
            'checked_out_at' => now(),
        ]);

        if ($visitorLog->host_user_id && $hostUser = $visitorLog->hostUser) {
            $hostUser->notify(new VisitorCheckedOut($visitorLog));
        }

        Flux::toast(variant: 'success', text: __(':name has been checked out.', ['name' => $visitorLog->visitor->name]));

        $this->pendingQrCheckoutToken = null;
        $this->showQrCheckoutModal = false;

        $this->resetForm();
    }

    public function cancelQrCheckOut(): void
    {
        $this->pendingQrCheckoutToken = null;
        $this->showQrCheckoutModal = false;
    }

    public function checkOutByToken(string $token): void
    {
        $visitorLog = VisitorLog::where('qr_code_token', $token)
            ->where('status', 'checked_in')
            ->first();

        if (! $visitorLog) {
            Flux::toast(variant: 'error', text: __('Invalid or already checked out QR code.'));

            return;
        }

        $this->pendingQrCheckoutToken = $token;
        $this->showQrCheckoutModal = true;
        $this->showQrScanner = false;
    }

    public function goToStep(int $step): void
    {
        $this->step = $step;
    }

    public function nextStep(): void
    {
        if ($this->step === 1) {
            if ($this->showCreateForm) {
                $this->validate([
                    'name' => 'required|string|max:255',
                    'email' => 'nullable|email|max:255',
                    'phone' => 'nullable|string|max:20',
                    'company' => 'nullable|string|max:255',
                    'validIdNumber' => 'nullable|string|max:50',
                ]);
            } elseif (! $this->selectedVisitorId) {
                Flux::toast(variant: 'warning', text: __('Please search and select a visitor, or register as a new person.'));

                return;
            }
            $this->step = 2;
        } elseif ($this->step === 2) {
            $this->step = 3;
        }
    }

    public function prevStep(): void
    {
        $this->step = max(1, $this->step - 1);
    }

    // ── Person search ──

    #[Computed]
    public function searchResults(): Collection
    {
        if ($this->search === '' || ! Schema::hasTable('visitors')) {
            return new Collection;
        }

        return Visitor::search($this->search)->orderBy('name')->limit(8)->get();
    }

    public function selectVisitor(string $id): void
    {
        $this->selectedVisitorId = $id;
        $this->showCreateForm = false;
        $this->search = '';
    }

    public function startCreating(): void
    {
        $this->showCreateForm = true;
        $this->selectedVisitorId = null;
    }

    public function cancelCreating(): void
    {
        $this->showCreateForm = false;
        $this->search = '';
    }

    public function changeVisitor(): void
    {
        $this->selectedVisitorId = null;
        $this->showCreateForm = false;
        $this->search = '';
    }

    #[Computed]
    public function selectedVisitor(): ?Visitor
    {
        if ($this->selectedVisitorId === null) {
            return null;
        }

        return Visitor::find($this->selectedVisitorId);
    }

    // ── Host ──

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

    // ── ID Scan ──

    public function openIdScanModal(): void
    {
        $this->reset('idCardImage', 'idCardPreview', 'idOcrText');
        $this->showIdScanModal = true;
    }

    public function closeIdScanModal(): void
    {
        $this->showIdScanModal = false;
        $this->reset('idCardImage', 'idCardPreview', 'idOcrText');
    }

    public function updatedIdCardImage(): void
    {
        if ($this->idCardImage) {
            $this->idCardPreview = $this->idCardImage->temporaryUrl();
        }
    }

    public function processIdCard(): void
    {
        $this->validate([
            'idCardImage' => 'required|image|max:5120',
        ]);

        $this->processingIdCard = true;

        try {
            $imagePath = $this->idCardImage->getRealPath();

            $ocr = new TesseractOCR($imagePath);
            $ocr->lang('eng');
            $this->idOcrText = $ocr->run();

            $this->parseIdCardText($this->idOcrText);

            Flux::toast(variant: 'success', text: __('ID card scanned successfully! Review the extracted information.'));
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: __('Failed to process ID card: ') . $e->getMessage());
        } finally {
            $this->processingIdCard = false;
        }
    }

    protected function parseIdCardText(string $text): void
    {
        $lines = array_map('trim', explode("\n", $text));
        $lines = array_filter($lines, fn($l) => $l !== '');

        $fullText = implode(' ', $lines);

        if ($this->name === '') {
            if (preg_match('/\b([A-Z]{2,}),\s*([A-Z][a-z]+(?:\s+[A-Z][a-z]+)*)\b/', $fullText, $matches)) {
                $this->name = $matches[2] . ' ' . $matches[1];
            } elseif (preg_match('/\b([A-Z][a-z]+\s+[A-Z][a-z]+)\b/', $fullText, $matches)) {
                $this->name = $matches[1];
            }
        }

        if ($this->email === '' && preg_match('/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/', $fullText, $matches)) {
            $this->email = $matches[0];
        }

        if ($this->phone === '' && preg_match('/(\+?1[\s.-]?)?\(?([0-9]{3})\)?[\s.-]?([0-9]{3})[\s.-]?([0-9]{4})/', $fullText, $matches)) {
            $this->phone = $matches[0];
        }

        if (preg_match('/\b(0[1-9]|1[0-2])[\/\-\.](0[1-9]|[12][0-9]|3[01])[\/\-\.](19|20)\d{2}\b/', $fullText, $matches)) {
        }

        if ($this->company === '') {
            foreach ($lines as $line) {
                if (stripos($line, 'name') !== false || stripos($line, 'sex') !== false ||
                    stripos($line, 'dob') !== false || stripos($line, 'exp') !== false ||
                    stripos($line, 'iss') !== false || stripos($line, 'class') !== false ||
                    stripos($line, 'restr') !== false || stripos($line, 'end') !== false ||
                    stripos($line, 'height') !== false || stripos($line, 'weight') !== false ||
                    stripos($line, 'eyes') !== false || stripos($line, 'hair') !== false ||
                    stripos($line, 'donor') !== false || stripos($line, 'vet') !== false) {
                    continue;
                }
                if (strlen($line) > 5 && strlen($line) < 50 &&
                    preg_match('/^[A-Z][a-zA-Z\s&\.]+$/', $line) &&
                    !preg_match('/^[A-Z]{2,}\s*$/', $line)) {
                    $this->company = $line;
                    break;
                }
            }
        }
    }

    public function applyIdScanData(): void
    {
        $this->closeIdScanModal();
        Flux::toast(variant: 'success', text: __('ID scan data applied. Please review and continue.'));
    }

    public function clearIdScanData(): void
    {
        $this->reset('name', 'email', 'phone', 'company');
        $this->closeIdScanModal();
        Flux::toast(variant: 'info', text: __('ID scan data cleared. You can enter details manually.'));
    }

    // ── Badge ──

    public function generateBadgeNumber(): string
    {
        if (! Schema::hasTable('visitor_logs')) {
            return 'V-' . strtoupper(Str::random(6));
        }

        $count = VisitorLog::whereDate('created_at', today())->count() + 1;

        return 'V-' . now()->format('Ymd') . '-' . str_pad($count, 3, '0', STR_PAD_LEFT);
    }

    // ── Check-in ──

    public function checkIn(): void
    {
        $this->validate([
            'visitPhoto' => 'nullable|string',
            'host' => 'nullable|string|max:255',
            'purpose' => 'nullable|string|max:255',
        ]);

        if ($this->selectedVisitorId) {
            $visitor = Visitor::findOrFail($this->selectedVisitorId);
        } else {
            $visitor = Visitor::create([
                'name' => $this->name,
                'email' => $this->email ?: null,
                'phone' => $this->phone ?: null,
                'company' => $this->company ?: null,
                'valid_id_number' => $this->validIdNumber ?: null,
                'photo' => $this->photo ?: null,
            ]);
        }

        $badgeNumber = $this->generateBadgeNumber();

        $visitorLog = VisitorLog::create([
            'visitor_id' => $visitor->id,
            'host' => $this->host,
            'host_user_id' => $this->hostUserId,
            'purpose' => $this->purpose,
            'photo' => $this->visitPhoto ?: null,
            'badge_number' => $badgeNumber,
            'qr_code_token' => Str::random(32),
            'status' => 'checked_in',
            'checked_in_at' => now(),
        ]);

        $this->lastLog = $visitorLog->load('visitor');
        $this->justCheckedIn = true;

        if ($visitorLog->host_user_id && $hostUser = $visitorLog->hostUser) {
            $hostUser->notify(new VisitorCheckedIn($visitorLog));
        }

        if ($this->flaggedMatch) {
            $visitor->update(['is_flagged' => true]);

            User::chunk(100, fn ($users) => $users->each->notify(new VisitorFlagged($visitorLog)));
        }

        Flux::toast(variant: 'success', text: __('Welcome, :name! Badge: :badge', ['name' => $visitor->name, 'badge' => $badgeNumber]));
    }

    public function resetForm(): void
    {
        $this->reset(
            'step', 'search', 'selectedVisitorId', 'showCreateForm',
            'name', 'email', 'phone', 'company', 'validIdNumber', 'photo',
            'hostSearch', 'host', 'hostUserId', 'useCustomHost', 'purpose', 'visitPhoto',
            'justCheckedIn', 'lastLog'
        );
        $this->step = 1;
    }

    // ── Check-out ──

    public function confirmCheckOut(string $visitorLogId): void
    {
        $this->pendingCheckoutId = $visitorLogId;
        $this->showCheckoutModal = true;
    }

    public function cancelCheckOut(): void
    {
        $this->pendingCheckoutId = null;
        $this->showCheckoutModal = false;
    }

    public function executeCheckOut(): void
    {
        $visitorLog = VisitorLog::with('visitor')->findOrFail($this->pendingCheckoutId);

        $visitorLog->update([
            'status' => 'checked_out',
            'checked_out_at' => now(),
        ]);

        if ($visitorLog->host_user_id && $hostUser = $visitorLog->hostUser) {
            $hostUser->notify(new VisitorCheckedOut($visitorLog));
        }

        Flux::toast(variant: 'success', text: __(':name has been checked out.', ['name' => $visitorLog->visitor->name]));

        $this->pendingCheckoutId = null;
        $this->showCheckoutModal = false;
    }

    #[Computed]
    public function pendingVisitor(): ?VisitorLog
    {
        if ($this->pendingCheckoutId === null) {
            return null;
        }

        return VisitorLog::with('visitor')->find($this->pendingCheckoutId);
    }

    // ── Computed ──

    #[Computed]
    public function checkedInVisitors(): Collection
    {
        if (! Schema::hasTable('visitor_logs')) {
            return new Collection;
        }

        return VisitorLog::with('visitor')
            ->where('status', 'checked_in')
            ->orderBy('checked_in_at', 'desc')
            ->get();
    }

    #[Computed]
    public function filteredVisitors(): Collection
    {
        if ($this->checkoutSearch === '') {
            return $this->checkedInVisitors;
        }

        return $this->checkedInVisitors->filter(function ($visitorLog) {
            $name = strtolower($visitorLog->visitor->name ?? '');
            $host = strtolower($visitorLog->host ?? '');

            return str_contains($name, strtolower($this->checkoutSearch))
                || str_contains($host, strtolower($this->checkoutSearch));
        })->values();
    }

    #[Computed]
    public function flaggedMatch(): ?Visitor
    {
        $name = $this->selectedVisitor?->name ?? $this->name;

        if ($name === '' || ! Schema::hasTable('visitors')) {
            return null;
        }

        return Visitor::flagged()
            ->where('name', $name)
            ->first();
    }

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
}; ?>

<div class="mx-auto flex min-h-svh max-w-7xl flex-col p-4 md:p-6 lg:p-8">
    <style>
        @media print {
            body * { visibility: hidden; }
            #badge-print-area, #badge-print-area * { visibility: visible; }
            #badge-print-area { position: absolute; inset: 0; }
            .no-print { display: none !important; }
        }
    </style>

    {{-- Header --}}
    <div class="no-print flex items-center justify-between border-b border-neutral-200 pb-4 dark:border-neutral-800">
        <div class="flex items-center gap-4">
            <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-neutral-900 text-lg font-bold text-white dark:bg-white dark:text-neutral-900">
                VMS
            </div>
            <div>
                <h1 class="text-xl font-semibold text-neutral-900 dark:text-white">{{ __('Visitor Kiosk') }}</h1>
                <p class="text-sm text-neutral-500 dark:text-neutral-400">{{ __('Self-service check-in and check-out') }}</p>
            </div>
        </div>
        <div class="hidden items-center gap-6 sm:flex">
            <div class="text-right">
                <p class="text-sm text-neutral-500 dark:text-neutral-400">{{ __('Today') }}</p>
                <p class="text-2xl font-bold text-neutral-900 dark:text-white">{{ $this->todayCount }}</p>
            </div>
            <div class="h-10 w-px bg-neutral-200 dark:bg-neutral-800"></div>
            <div class="text-right">
                <p class="text-sm text-neutral-500 dark:text-neutral-400">{{ __('On-site') }}</p>
                <p class="text-2xl font-bold text-emerald-600 dark:text-emerald-400">{{ $this->onSiteCount }}</p>
            </div>
        </div>
    </div>

    {{-- Badge success view --}}
    @if ($this->justCheckedIn && $this->lastLog)
        @php $visitor = $this->lastLog->visitor; @endphp
        <div class="mt-6 flex-1 flex items-center justify-center">
            <div class="w-full max-w-lg rounded-xl border border-neutral-200 bg-white p-8 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                <div class="no-print text-center">
                    <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-emerald-100 text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-400">
                        <svg class="h-8 w-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    </div>
                    <h2 class="text-2xl font-bold text-neutral-900 dark:text-white">{{ __('Checked In!') }}</h2>
                    <p class="mt-1 text-neutral-500 dark:text-neutral-400">{{ __('Welcome, :name.', ['name' => $visitor->name]) }}</p>
                </div>

                <div id="badge-print-area" class="mt-6 rounded-lg border-2 border-dashed border-neutral-200 bg-neutral-50 p-6 dark:border-neutral-700 dark:bg-neutral-800/50">
                    <div class="text-center">
                        <p class="text-xs font-semibold uppercase tracking-widest text-neutral-400 dark:text-neutral-500">{{ __('Visitor Badge') }}</p>
                        <p class="mt-1 text-lg font-bold text-neutral-900 dark:text-white">{{ $this->lastLog->badge_number }}</p>
                    </div>

                    @if ($this->lastLog->photo)
                        <img src="{{ $this->lastLog->photo }}" alt="Visitor photo" class="mx-auto mt-4 h-32 w-32 rounded-full object-cover border-2 border-neutral-200 dark:border-neutral-700">
                    @elseif ($visitor->photo)
                        <img src="{{ $visitor->photo }}" alt="Visitor photo" class="mx-auto mt-4 h-32 w-32 rounded-full object-cover border-2 border-neutral-200 dark:border-neutral-700">
                    @endif

                    <div class="mt-4 space-y-2 text-center">
                        <p class="text-xl font-semibold text-neutral-900 dark:text-white">{{ $visitor->name }}</p>
                        @if ($visitor->company)
                            <p class="text-neutral-500 dark:text-neutral-400">{{ $visitor->company }}</p>
                        @endif
                        @if ($this->lastLog->host)
                            <p class="text-sm text-neutral-500 dark:text-neutral-400">{{ __('Visiting: ') }}<span class="font-medium text-neutral-700 dark:text-neutral-300">{{ $this->lastLog->host }}</span></p>
                        @endif
                        <p class="text-sm text-neutral-500 dark:text-neutral-400">{{ $this->lastLog->checked_in_at->format('g:i A, M j, Y') }}</p>
                    </div>

                    @if ($this->lastLog->qr_code_token)
                        <div class="mt-4 flex justify-center">
                            <img src="{{ route('qr.code', $this->lastLog->qr_code_token) }}" alt="QR Code" class="h-24 w-24">
                        </div>
                    @endif
                </div>

                <div class="no-print mt-6 flex gap-3">
                    <flux:button variant="primary" class="flex-1 !py-3" onclick="window.print()">
                        {{ __('Print Badge') }}
                    </flux:button>
                    <flux:button variant="ghost" class="flex-1 !py-3" wire:click="resetForm">
                        {{ __('Check In Another') }}
                    </flux:button>
                </div>
            </div>
        </div>
    @else
        {{-- Main content --}}
        <div class="mt-6 grid flex-1 gap-6 lg:grid-cols-5">
            {{-- Check-in wizard --}}
            <div class="lg:col-span-2">
                <div class="rounded-xl border border-neutral-200 bg-white p-6 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                    <h2 class="text-xl font-semibold text-neutral-900 dark:text-white">{{ __('Check In') }}</h2>

                    {{-- Step indicator --}}
                    <div class="mt-4 flex items-center gap-2 text-sm">
                        <span @class(['flex items-center gap-1.5', 'text-emerald-600 dark:text-emerald-400' => $this->step >= 1, 'text-neutral-400 dark:text-neutral-500' => $this->step < 1])>
                            <span @class(['flex h-6 w-6 items-center justify-center rounded-full text-xs font-bold', 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400' => $this->step >= 1, 'bg-neutral-100 text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400' => $this->step < 1])>1</span>
                            {{ __('Identity') }}
                        </span>
                        <span class="h-px flex-1 bg-neutral-200 dark:bg-neutral-700"></span>
                        <span @class(['flex items-center gap-1.5', 'text-emerald-600 dark:text-emerald-400' => $this->step >= 2, 'text-neutral-400 dark:text-neutral-500' => $this->step < 2])>
                            <span @class(['flex h-6 w-6 items-center justify-center rounded-full text-xs font-bold', 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400' => $this->step >= 2, 'bg-neutral-100 text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400' => $this->step < 2])>2</span>
                            {{ __('Visit') }}
                        </span>
                        <span class="h-px flex-1 bg-neutral-200 dark:bg-neutral-700"></span>
                        <span @class(['flex items-center gap-1.5', 'text-emerald-600 dark:text-emerald-400' => $this->step >= 3, 'text-neutral-400 dark:text-neutral-500' => $this->step < 3])>
                            <span @class(['flex h-6 w-6 items-center justify-center rounded-full text-xs font-bold', 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400' => $this->step >= 3, 'bg-neutral-100 text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400' => $this->step < 3])>3</span>
                            {{ __('Confirm') }}
                        </span>
                    </div>

                    <div class="mt-6">
                        {{-- Step 1: Search or Create Person --}}
                        @if ($this->step === 1)
                            @if ($this->selectedVisitor)
                                {{-- Person selected – confirm --}}
                                <p class="mb-4 text-sm text-neutral-500 dark:text-neutral-400">{{ __('Is this you?') }}</p>
                                <div class="rounded-lg border border-neutral-100 bg-neutral-50 p-4 dark:border-neutral-700 dark:bg-neutral-800/50">
                                    <div class="flex items-center gap-4">
                                        @if ($this->selectedVisitor->photo)
                                            <img src="{{ $this->selectedVisitor->photo }}" alt="" class="h-14 w-14 rounded-full object-cover border border-neutral-200 dark:border-neutral-700">
                                        @else
                                            <div class="flex h-14 w-14 items-center justify-center rounded-full bg-neutral-100 text-lg font-medium text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400">
                                                {{ substr($this->selectedVisitor->name, 0, 2) }}
                                            </div>
                                        @endif
                                        <div>
                                            <p class="font-semibold text-neutral-900 dark:text-white">{{ $this->selectedVisitor->name }}</p>
                                            @if ($this->selectedVisitor->email)
                                                <p class="text-sm text-neutral-500 dark:text-neutral-400">{{ $this->selectedVisitor->email }}</p>
                                            @endif
                                            @if ($this->selectedVisitor->valid_id_number)
                                                <p class="text-xs text-neutral-400 dark:text-neutral-500">ID: {{ $this->selectedVisitor->valid_id_number }}</p>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                                <div class="mt-4 flex gap-2">
                                    <flux:button variant="primary" class="flex-1 !py-3 text-base" wire:click="nextStep">
                                        {{ __('Yes, it\u2019s me') }} &rarr;
                                    </flux:button>
                                    <flux:button variant="ghost" class="!py-3" wire:click="changeVisitor">
                                        {{ __('Not me') }}
                                    </flux:button>
                                </div>

                            @elseif ($this->showCreateForm)
                                {{-- Create new person --}}
                                <p class="mb-4 text-sm text-neutral-500 dark:text-neutral-400">{{ __('Enter your details below.') }}</p>
                                <div class="space-y-4">
                                    <flux:input wire:model="name" label="{{ __('Full Name') }}" type="text" required placeholder="{{ __('e.g. John Doe') }}" />
                                    <flux:input wire:model="email" label="{{ __('Email') }}" type="email" placeholder="{{ __('e.g. john@example.com') }}" />
                                    <flux:input wire:model="phone" label="{{ __('Phone') }}" type="tel" placeholder="{{ __('e.g. +1 555-1234') }}" />
                                    <flux:input wire:model="company" label="{{ __('Company') }}" placeholder="{{ __('e.g. Acme Corp') }}" />
                                    <flux:input wire:model="validIdNumber" label="{{ __('Valid ID Number') }}" placeholder="{{ __('e.g. DL-12345678') }}" />

                                    {{-- ID photo capture --}}
                                    <div x-data="{
                                        photo: @entangle('photo'),
                                        stream: null,
                                        cameraActive: false,
                                        videoReady: false,
                                        async startCamera() {
                                            try {
                                                this.stream = await navigator.mediaDevices.getUserMedia({ video: { width: 640, height: 480, facingMode: 'environment' } });
                                                const video = this.$refs.idVideo;
                                                video.srcObject = this.stream;
                                                video.onloadedmetadata = () => { video.play(); this.videoReady = true; };
                                                this.cameraActive = true;
                                            } catch (e) { alert('Camera error: ' + e.message); }
                                        },
                                        capture() {
                                            const video = this.$refs.idVideo;
                                            const canvas = this.$refs.idCanvas;
                                            canvas.width = video.videoWidth || 640;
                                            canvas.height = video.videoHeight || 480;
                                            canvas.getContext('2d').drawImage(video, 0, 0);
                                            this.photo = canvas.toDataURL('image/jpeg', 0.8);
                                            this.stopCamera();
                                        },
                                        stopCamera() {
                                            if (this.stream) { this.stream.getTracks().forEach(t => t.stop()); this.stream = null; }
                                            this.cameraActive = false; this.videoReady = false;
                                        },
                                        clearPhoto() { this.photo = ''; },
                                        destroy() { this.stopCamera(); }
                                    }">
                                        <div class="rounded-lg border border-dashed border-neutral-300 bg-neutral-50 p-4 text-center dark:border-neutral-700 dark:bg-neutral-800/50">
                                            <div x-show="!cameraActive && !photo">
                                                <svg class="mx-auto h-8 w-8 text-neutral-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                                <p class="mt-2 text-sm text-neutral-500 dark:text-neutral-400">{{ __('Take a photo of your ID card') }}</p>
                                                <flux:button variant="primary" size="sm" class="mt-2" x-on:click="startCamera()">{{ __('Capture ID Photo') }}</flux:button>
                                            </div>
                                            <div x-show="cameraActive">
                                                <video x-ref="idVideo" autoplay playsinline class="mx-auto max-h-48 rounded-lg"></video>
                                                <div class="mt-3 flex gap-2 justify-center">
                                                    <flux:button variant="primary" x-on:click="capture()" x-bind:disabled="!videoReady">{{ __('Capture') }}</flux:button>
                                                    <flux:button variant="ghost" x-on:click="stopCamera()">{{ __('Cancel') }}</flux:button>
                                                </div>
                                            </div>
                                            <div x-show="photo">
                                                <div class="flex items-center gap-3 justify-center">
                                                    <img :src="photo" alt="ID photo" class="h-16 w-16 rounded-lg object-cover border border-neutral-300">
                                                    <div class="text-left">
                                                        <p class="text-sm font-medium text-emerald-600">{{ __('ID photo captured') }}</p>
                                                        <button type="button" x-on:click="clearPhoto()" class="text-xs text-neutral-400 hover:text-neutral-600">{{ __('Remove') }}</button>
                                                    </div>
                                                </div>
                                            </div>
                                            <canvas x-ref="idCanvas" class="hidden"></canvas>
                                        </div>
                                    </div>

                                    {{-- ID Scan Button --}}
                                    <flux:button variant="outline" class="w-full !py-3" wire:click="openIdScanModal" icon="camera">
                                        {{ __("Scan ID Card (Driver's License)") }}
                                    </flux:button>
                                    <p class="-mt-2 text-center text-xs text-neutral-500 dark:text-neutral-400">
                                        {{ __("Or scan to auto-fill details") }}
                                    </p>

                                    <flux:button variant="primary" class="w-full !py-3 text-base" wire:click="nextStep">
                                        {{ __('Continue') }} &rarr;
                                    </flux:button>

                                    <div class="text-center">
                                        <button type="button" wire:click="cancelCreating" class="text-sm text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-300">
                                            {{ __('Search existing visitor') }}
                                        </button>
                                    </div>
                                </div>

                            @else
                                {{-- Search first --}}
                                <p class="mb-4 text-sm text-neutral-500 dark:text-neutral-400">{{ __('Search for your name or scan your badge.') }}</p>
                                <div class="space-y-4">
                                    <flux:input wire:model.live.debounce.300ms="search" placeholder="{{ __('Search by name, email, phone, or ID...') }}" icon="magnifying-glass" />

                                    @if ($this->search !== '' && $this->searchResults->isNotEmpty())
                                        <div class="max-h-64 overflow-y-auto rounded-lg border border-neutral-200 dark:border-neutral-700">
                                            @foreach ($this->searchResults as $person)
                                                <button type="button" wire:click="selectVisitor('{{ $person->id }}')" class="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-neutral-50 dark:hover:bg-neutral-800 border-b border-neutral-100 dark:border-neutral-700 last:border-0">
                                                    @if ($person->photo)
                                                        <img src="{{ $person->photo }}" alt="" class="h-10 w-10 rounded-full object-cover border border-neutral-200 dark:border-neutral-700">
                                                    @else
                                                        <div class="flex h-10 w-10 items-center justify-center rounded-full bg-neutral-100 text-sm font-medium text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400">
                                                            {{ substr($person->name, 0, 2) }}
                                                        </div>
                                                    @endif
                                                    <div class="min-w-0 flex-1">
                                                        <p class="font-medium text-neutral-900 dark:text-white truncate">{{ $person->name }}</p>
                                                        <p class="text-xs text-neutral-400 dark:text-neutral-500 truncate">
                                                            {{ $person->company ?: '' }}{{ $person->company && $person->valid_id_number ? ' · ' : '' }}{{ $person->valid_id_number ?: '' }}
                                                        </p>
                                                    </div>
                                                    <flux:button size="sm" variant="outline">{{ __('Select') }}</flux:button>
                                                </button>
                                            @endforeach
                                        </div>
                                    @elseif ($this->search !== '' && $this->searchResults->isEmpty())
                                        <p class="text-sm text-neutral-400 dark:text-neutral-500 text-center py-2">{{ __('No matches found.') }}</p>
                                    @endif

                                    <div class="relative">
                                        <div class="absolute inset-0 flex items-center"><span class="w-full border-t border-neutral-200 dark:border-neutral-700"></span></div>
                                        <div class="relative flex justify-center text-xs uppercase"><span class="bg-white px-2 text-neutral-400 dark:bg-neutral-900 dark:text-neutral-500">{{ __('or') }}</span></div>
                                    </div>

                                    <flux:button variant="outline" class="w-full !py-3" wire:click="startCreating">
                                        {{ __('First time? Register as new visitor') }}
                                    </flux:button>
                                </div>
                            @endif
                        @endif

                        {{-- Step 2: Host, Purpose & Visit Selfie --}}
                        @if ($this->step === 2)
                            <p class="mb-4 text-sm text-neutral-500 dark:text-neutral-400">{{ __('Who are you visiting, why, and take a selfie.') }}</p>
                            <div class="space-y-4">
                                @if (! $this->useCustomHost)
                                    <flux:input wire:model.live="hostSearch" label="{{ __('Search for an employee') }}" placeholder="{{ __('Type name to search...') }}" />
                                    @if ($this->hostSearch !== '')
                                        <div class="max-h-40 overflow-y-auto rounded-lg border border-neutral-200 dark:border-neutral-700">
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
                                    @if ($this->host === '')
                                        <button type="button" wire:click="useCustomHostField" class="text-sm text-emerald-600 hover:text-emerald-700 dark:text-emerald-400 dark:hover:text-emerald-300">
                                            {{ __('Visiting someone else?') }}
                                        </button>
                                    @else
                                        <div class="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 dark:border-emerald-800 dark:bg-emerald-900/20">
                                            <span class="text-sm text-neutral-700 dark:text-neutral-300">{{ __('Visiting: ') }}<strong>{{ $this->host }}</strong></span>
                                            <button type="button" wire:click="useCustomHostField" class="ml-auto text-xs text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-300">{{ __('Change') }}</button>
                                        </div>
                                    @endif
                                @else
                                    <flux:input wire:model="host" label="{{ __('Whom are you visiting?') }}" placeholder="{{ __('e.g. Sarah Johnson') }}" />
                                    <button type="button" wire:click="$set('useCustomHost', false)" class="text-sm text-emerald-600 hover:text-emerald-700 dark:text-emerald-400 dark:hover:text-emerald-300">
                                        {{ __('Search employee directory') }}
                                    </button>
                                @endif

                                <flux:input wire:model="purpose" label="{{ __('Purpose of visit') }}" placeholder="{{ __('e.g. Meeting, Interview, Delivery') }}" />

                                {{-- Visit selfie capture --}}
                                <div x-data="{
                                    photo: @entangle('visitPhoto'),
                                    stream: null,
                                    cameraActive: false,
                                    videoReady: false,
                                    async startCamera() {
                                        try {
                                            this.stream = await navigator.mediaDevices.getUserMedia({ video: { width: 640, height: 480, facingMode: 'user' } });
                                            const video = this.$refs.visitVideo;
                                            video.srcObject = this.stream;
                                            video.onloadedmetadata = () => { video.play(); this.videoReady = true; };
                                            this.cameraActive = true;
                                        } catch (e) { alert('Camera error: ' + e.message); }
                                    },
                                    capture() {
                                        const video = this.$refs.visitVideo;
                                        const canvas = this.$refs.visitCanvas;
                                        canvas.width = video.videoWidth || 640;
                                        canvas.height = video.videoHeight || 480;
                                        canvas.getContext('2d').drawImage(video, 0, 0);
                                        this.photo = canvas.toDataURL('image/jpeg', 0.8);
                                        this.stopCamera();
                                    },
                                    stopCamera() {
                                        if (this.stream) { this.stream.getTracks().forEach(t => t.stop()); this.stream = null; }
                                        this.cameraActive = false; this.videoReady = false;
                                    },
                                    clearPhoto() { this.photo = ''; },
                                    destroy() { this.stopCamera(); }
                                }">
                                    <div class="rounded-lg border border-dashed border-neutral-300 bg-neutral-50 p-4 text-center dark:border-neutral-700 dark:bg-neutral-800/50">
                                        <div x-show="!cameraActive && !photo">
                                            <svg class="mx-auto h-8 w-8 text-neutral-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                            <p class="mt-2 text-sm text-neutral-500 dark:text-neutral-400">{{ __('Take a selfie for your badge') }}</p>
                                            <flux:button variant="primary" size="sm" class="mt-2" x-on:click="startCamera()">{{ __('Open Camera') }}</flux:button>
                                        </div>
                                        <div x-show="cameraActive">
                                            <video x-ref="visitVideo" autoplay playsinline class="mx-auto max-h-48 rounded-lg"></video>
                                            <div class="mt-3 flex gap-2 justify-center">
                                                <flux:button variant="primary" x-on:click="capture()" x-bind:disabled="!videoReady">{{ __('Capture') }}</flux:button>
                                                <flux:button variant="ghost" x-on:click="stopCamera()">{{ __('Cancel') }}</flux:button>
                                            </div>
                                        </div>
                                        <div x-show="photo">
                                            <div class="flex items-center gap-3 justify-center">
                                                <img :src="photo" alt="Selfie" class="h-14 w-14 rounded-full object-cover border-2 border-emerald-400">
                                                <div class="text-left">
                                                    <p class="text-sm font-medium text-emerald-600">{{ __('Selfie captured') }}</p>
                                                    <button type="button" x-on:click="clearPhoto()" class="text-xs text-neutral-400 hover:text-neutral-600">{{ __('Retake') }}</button>
                                                </div>
                                            </div>
                                        </div>
                                        <canvas x-ref="visitCanvas" class="hidden"></canvas>
                                    </div>
                                </div>

                                <div class="flex gap-3">
                                    <flux:button variant="ghost" class="!py-3" wire:click="prevStep">
                                        &larr; {{ __('Back') }}
                                    </flux:button>
                                    <flux:button variant="primary" class="flex-1 !py-3 text-base" wire:click="nextStep">
                                        {{ __('Review') }} &rarr;
                                    </flux:button>
                                </div>
                            </div>
                        @endif

                        {{-- Step 3: Confirm & Check In --}}
                        @if ($this->step === 3)
                            <p class="mb-4 text-sm text-neutral-500 dark:text-neutral-400">{{ __('Review your details before checking in.') }}</p>
                            <div class="space-y-4">
                                @php $person = $this->selectedVisitor ?? null; @endphp

                                @if ($this->flaggedMatch)
                                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-900/20">
                                        <div class="flex items-start gap-3">
                                            <svg class="mt-0.5 h-5 w-5 shrink-0 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                                            <div>
                                                <p class="text-sm font-medium text-amber-800 dark:text-amber-300">{{ __('Watchlist Match') }}</p>
                                                <p class="mt-1 text-sm text-amber-700 dark:text-amber-400">
                                                    {{ __('This name matches a flagged record in the visitor watchlist.') }}
                                                    @if ($this->flaggedMatch->notes)
                                                        <span class="block mt-1 italic">"{{ $this->flaggedMatch->notes }}"</span>
                                                    @endif
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                @endif

                                {{-- Person summary --}}
                                <div class="rounded-lg border border-neutral-100 bg-neutral-50 p-4 dark:border-neutral-700 dark:bg-neutral-800/50">
                                    <p class="mb-2 text-xs font-semibold uppercase tracking-widest text-neutral-400 dark:text-neutral-500">{{ __('Person') }}</p>
                                    <div class="space-y-2 text-sm">
                                        <div class="flex justify-between">
                                            <span class="text-neutral-500 dark:text-neutral-400">{{ __('Name') }}</span>
                                            <span class="font-medium text-neutral-900 dark:text-white">{{ $person?->name ?? $this->name }}</span>
                                        </div>
                                        @if ($person?->email ?? $this->email)
                                            <div class="flex justify-between"><span class="text-neutral-500 dark:text-neutral-400">{{ __('Email') }}</span><span class="text-neutral-900 dark:text-white">{{ $person?->email ?? $this->email }}</span></div>
                                        @endif
                                        @if ($person?->valid_id_number ?? $this->validIdNumber)
                                            <div class="flex justify-between"><span class="text-neutral-500 dark:text-neutral-400">{{ __('ID Number') }}</span><span class="text-neutral-900 dark:text-white">{{ $person?->valid_id_number ?? $this->validIdNumber }}</span></div>
                                        @endif
                                    </div>
                                </div>

                                {{-- Visit summary --}}
                                <div class="rounded-lg border border-neutral-100 bg-neutral-50 p-4 dark:border-neutral-700 dark:bg-neutral-800/50">
                                    <p class="mb-2 text-xs font-semibold uppercase tracking-widest text-neutral-400 dark:text-neutral-500">{{ __('Visit') }}</p>
                                    <div class="space-y-2 text-sm">
                                        @if ($this->host)
                                            <div class="flex justify-between"><span class="text-neutral-500 dark:text-neutral-400">{{ __('Visiting') }}</span><span class="text-neutral-900 dark:text-white">{{ $this->host }}</span></div>
                                        @endif
                                        @if ($this->purpose)
                                            <div class="flex justify-between"><span class="text-neutral-500 dark:text-neutral-400">{{ __('Purpose') }}</span><span class="text-neutral-900 dark:text-white">{{ $this->purpose }}</span></div>
                                        @endif
                                        @if ($this->visitPhoto)
                                            <div class="flex justify-center pt-2">
                                                <img src="{{ $this->visitPhoto }}" alt="Selfie" class="h-16 w-16 rounded-full object-cover border-2 border-emerald-400">
                                            </div>
                                        @endif
                                    </div>
                                </div>

                                <div class="flex gap-3">
                                    <flux:button variant="ghost" class="!py-3" wire:click="prevStep">
                                        &larr; {{ __('Back') }}
                                    </flux:button>
                                    <flux:button variant="primary" class="flex-1 !py-3 text-base" wire:click="checkIn">
                                        {{ __('Check In') }}
                                    </flux:button>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            {{-- On-site visitors --}}
            <div class="lg:col-span-3">
                <div class="rounded-xl border border-neutral-200 bg-white p-6 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                    <h2 class="mb-1 text-xl font-semibold text-neutral-900 dark:text-white">{{ __('On-Site Visitors') }}</h2>
                    <p class="mb-4 text-sm text-neutral-500 dark:text-neutral-400">{{ __('Find your name and check out when leaving.') }}</p>

                    <div class="mb-4 flex gap-2">
                        <flux:input wire:model.live="checkoutSearch" placeholder="{{ __('Search by name or host...') }}" icon="magnifying-glass" class="flex-1" />

                        @if (! $this->showQrScanner)
                            <flux:button variant="outline" wire:click="$set('showQrScanner', true)" icon="camera" class="shrink-0">
                                {{ __('Scan QR') }}
                            </flux:button>
                        @endif
                    </div>

                    @if ($this->showQrScanner)
                        <div class="mb-4 rounded-lg border border-neutral-200 p-4 dark:border-neutral-700"
                             x-data="{
                                 reader: null,
                                 init() {
                                     this.$nextTick(() => this.startScanner());
                                 },
                                 startScanner() {
                                     if (typeof Html5Qrcode === 'undefined') return;
                                     this.reader = new Html5Qrcode('qr-reader');
                                     this.reader.start(
                                         { facingMode: 'environment' },
                                         { fps: 10, qrbox: { width: 250, height: 250 } },
                                         (decodedText) => {
                                             try {
                                                 const url = new URL(decodedText);
                                                 const token = url.searchParams.get('checkout');
                                                 if (token) {
                                                     this.reader.stop().catch(() => {});
                                                     this.reader = null;
                                                     $wire.checkOutByToken(token);
                                                 }
                                             } catch {}
                                         },
                                     ).catch(() => {});
                                 },
                                 destroy() {
                                     if (this.reader) {
                                         this.reader.stop().catch(() => {});
                                     }
                                 }
                             }">
                            <div class="mb-2 flex items-center justify-between">
                                <p class="text-sm font-medium text-neutral-900 dark:text-white">{{ __('Scan QR Code') }}</p>
                                <flux:button size="sm" variant="ghost" x-on:click="destroy(); $wire.$set('showQrScanner', false)">
                                    {{ __('Close') }}
                                </flux:button>
                            </div>
                            <div id="qr-reader" class="mx-auto max-w-xs overflow-hidden rounded-lg"></div>
                            <p class="mt-2 text-center text-xs text-neutral-400 dark:text-neutral-500">
                                {{ __('Point your badge QR code at the camera') }}
                            </p>
                        </div>
                    @endif

                    @if ($this->filteredVisitors->isEmpty())
                        <div class="flex flex-col items-center justify-center py-12 text-center">
                            @if ($this->checkoutSearch !== '')
                                <p class="text-neutral-500 dark:text-neutral-400">{{ __('No visitors match your search.') }}</p>
                            @else
                                <p class="text-neutral-400 dark:text-neutral-500">{{ __('No visitors on-site.') }}</p>
                                <p class="text-sm text-neutral-300 dark:text-neutral-600">{{ __('Complete the check-in form on the left.') }}</p>
                            @endif
                        </div>
                    @else
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm">
                                <thead>
                                    <tr class="border-b border-neutral-100 text-neutral-500 dark:border-neutral-800 dark:text-neutral-400">
                                        <th class="pb-3 pr-4 font-medium">{{ __('Name') }}</th>
                                        <th class="pb-3 pr-4 font-medium hidden md:table-cell">{{ __('Host') }}</th>
                                        <th class="pb-3 pr-4 font-medium hidden lg:table-cell">{{ __('Purpose') }}</th>
                                        <th class="pb-3 pr-4 font-medium hidden sm:table-cell">{{ __('In Since') }}</th>
                                        <th class="pb-3 font-medium"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                                    @foreach ($this->filteredVisitors as $visitorLog)
                                        @php $v = $visitorLog->visitor; @endphp
                                        <tr class="group" wire:key="{{ $visitorLog->id }}">
                                            <td class="py-4 pr-4">
                                                <span class="font-medium text-neutral-900 dark:text-white">{{ $v->name }}</span>
                                                @if ($visitorLog->badge_number || $v->company)
                                                    <div class="text-xs text-neutral-400 dark:text-neutral-500">
                                                        {{ $visitorLog->badge_number }}{{ $v->company ? ' &middot; ' . $v->company : '' }}
                                                    </div>
                                                @endif
                                            </td>
                                            <td class="py-4 pr-4 text-neutral-600 dark:text-neutral-300 hidden md:table-cell">{{ $visitorLog->host ?: '—' }}</td>
                                            <td class="py-4 pr-4 text-neutral-600 dark:text-neutral-300 hidden lg:table-cell">{{ Str::limit($visitorLog->purpose ?: '—', 20) }}</td>
                                            <td class="py-4 pr-4 text-neutral-500 dark:text-neutral-400 hidden sm:table-cell whitespace-nowrap">
                                                {{ $visitorLog->checked_in_at->format('g:i A') }}
                                            </td>
                                            <td class="py-4 text-right">
                                                <flux:button size="sm" variant="ghost" wire:click="confirmCheckOut('{{ $visitorLog->id }}')">
                                                    {{ __('Check Out') }}
                                                </flux:button>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- Check-out confirmation modal --}}
    <flux:modal wire:model="showCheckoutModal" name="checkout-confirm" class="min-w-sm">
        @if ($this->pendingVisitor)
            <flux:heading size="lg">{{ __('Confirm Check Out') }}</flux:heading>
            <flux:text class="mt-2">
                {{ __('Confirm that :name is leaving the premises.', ['name' => $this->pendingVisitor->visitor->name]) }}
            </flux:text>
            <div class="mt-6 flex gap-2 justify-end">
                <flux:button variant="ghost" x-on:click="$flux.modal('checkout-confirm').close(); $wire.cancelCheckOut()">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button variant="primary" wire:click="executeCheckOut">
                    {{ __('Confirm Check Out') }}
                </flux:button>
            </div>
        @endif
    </flux:modal>

    {{-- QR check-out confirmation modal --}}
    @if ($this->pendingQrCheckoutToken)
        <flux:modal wire:model="showQrCheckoutModal" name="qr-checkout-confirm" class="min-w-sm">
            @if ($this->pendingQrVisitor)
                <flux:heading size="lg">{{ __('Confirm Check Out') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('A QR code was scanned for :name. Confirm they are leaving the premises.', ['name' => $this->pendingQrVisitor->visitor->name]) }}
                </flux:text>
                <div class="mt-6 flex gap-2 justify-end">
                    <flux:button variant="ghost" x-on:click="$flux.modal('qr-checkout-confirm').close(); $wire.cancelQrCheckOut()">
                        {{ __('Cancel') }}
                    </flux:button>
                    <flux:button variant="primary" wire:click="confirmQrCheckOut">
                        {{ __('Confirm Check Out') }}
                    </flux:button>
                </div>
            @endif
        </flux:modal>
    @endif

    {{-- ID Scan Modal --}}
    <flux:modal wire:model="showIdScanModal" name="id-scan" class="min-w-md">
        <flux:heading size="lg">{{ __('Scan ID Card') }}</flux:heading>
        <flux:text class="mt-2">
            {{ __("Upload a photo of your driver's license or ID card to automatically fill your details.") }}
        </flux:text>

        <div class="mt-6 space-y-4">
            @if ($idCardPreview)
                <div class="overflow-hidden rounded-lg border border-neutral-200 dark:border-neutral-700">
                    <img src="{{ $idCardPreview }}" alt="ID card preview" class="w-full max-h-64 object-contain bg-neutral-100 dark:bg-neutral-800">
                </div>
            @endif

            @if (! $idCardPreview && ! $this->idOcrText)
                <div x-data="{
                    upload(e) {
                        const file = e.target.files[0] || e.dataTransfer?.files[0];
                        if (file) {
                            $wire.upload('idCardImage', file);
                        }
                    },
                    dragover: false
                }"
                x-on:dragover.prevent="dragover = true"
                x-on:dragleave.prevent="dragover = false"
                x-on:drop.prevent="dragover = false; upload($event)"
                class="flex flex-col items-center justify-center rounded-lg border-2 border-dashed border-neutral-300 p-8 text-center transition-colors dark:border-neutral-600"
                :class="dragover && 'border-emerald-400 bg-emerald-50 dark:bg-emerald-900/20'">
                    <svg class="mb-3 h-12 w-12 text-neutral-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                    <p class="mb-1 text-sm font-medium text-neutral-600 dark:text-neutral-300">{{ __('Drop your ID card image here') }}</p>
                    <p class="mb-3 text-xs text-neutral-400 dark:text-neutral-500">{{ __('or') }}</p>
                    <flux:button variant="primary" size="sm" x-on:click="$refs.fileInput.click()">
                        {{ __('Choose Image') }}
                    </flux:button>
                    <input type="file" x-ref="fileInput" accept="image/*" class="hidden" x-on:change="upload($event)">
                </div>
            @endif

            @if ($this->processingIdCard)
                <div class="flex items-center justify-center gap-3 py-6">
                    <svg class="h-6 w-6 animate-spin text-emerald-600" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                    <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Processing ID card...') }}</p>
                </div>
            @endif

            @if ($this->idOcrText && ! $this->processingIdCard)
                <div x-data="{ showRaw: false }">
                    <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-800 dark:bg-emerald-900/20">
                        <div class="space-y-2 text-sm">
                            <p><span class="font-medium text-neutral-700 dark:text-neutral-300">{{ __('Name') }}:</span> <span class="text-neutral-900 dark:text-white">{{ $this->name }}</span></p>
                            <p><span class="font-medium text-neutral-700 dark:text-neutral-300">{{ __('Email') }}:</span> <span class="text-neutral-900 dark:text-white">{{ $this->email ?: '—' }}</span></p>
                            <p><span class="font-medium text-neutral-700 dark:text-neutral-300">{{ __('Phone') }}:</span> <span class="text-neutral-900 dark:text-white">{{ $this->phone ?: '—' }}</span></p>
                            <p><span class="font-medium text-neutral-700 dark:text-neutral-300">{{ __('Company') }}:</span> <span class="text-neutral-900 dark:text-white">{{ $this->company ?: '—' }}</span></p>
                        </div>
                    </div>

                    <div class="mt-3">
                        <button type="button" @click="showRaw = !showRaw" class="text-xs text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-300">
                            <span x-text="showRaw ? '{{ __('Hide') }}' : '{{ __('Show') }}'"></span> {{ __('raw OCR text') }}
                        </button>
                        <div x-show="showRaw" class="mt-2 rounded bg-neutral-50 p-3 text-xs text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400 whitespace-pre-wrap max-h-40 overflow-y-auto">{{ $this->idOcrText }}</div>
                    </div>
                </div>

                <div class="flex gap-2">
                    <flux:button variant="primary" class="flex-1" wire:click="applyIdScanData">
                        {{ __('Apply & Continue') }}
                    </flux:button>
                    <flux:button variant="ghost" class="flex-1" wire:click="clearIdScanData">
                        {{ __('Clear & Re-scan') }}
                    </flux:button>
                </div>
            @endif
        </div>
    </flux:modal>
</div>