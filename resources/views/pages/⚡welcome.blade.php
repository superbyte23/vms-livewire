<?php

use App\Models\PreRegistration;
use App\Models\User;
use App\Models\Visitor;
use App\Models\VisitorLog;
use App\Services\VisitorCheckInService;
use App\Services\VisitorCheckOutService;
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
    public string $activeTab = 'checkin';

    // Step 1: Search or create person
    public string $search = '';
    public ?string $selectedVisitorId = null;
    public ?string $selectedPreRegistrationId = null;
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
    public string $checkoutPhoto = '';

    // QR
    public bool $showQrScanner = false;
    public bool $showPreQrScanner = false;
    public bool $showQrCheckoutModal = false;
    public ?string $pendingQrCheckoutToken = null;

    public function mount(): void
    {
        $token = request()->query('checkout');
        if ($token) {
            if ($this->activeLogForQrToken($token)) {
                $this->pendingQrCheckoutToken = $token;
                $this->showQrCheckoutModal = true;
            } else {
                Flux::toast(variant: 'error', text: __('Invalid QR code or visitor is not on-site.'));
            }

            return;
        }

        $preToken = request()->query('pre');
        if ($preToken) {
            $pre = PreRegistration::pending()->where('qr_code_token', $preToken)->first();

            if ($pre) {
                $this->selectPreRegistration((string) $pre->id);
                Flux::toast(variant: 'success', text: __('Pre-registration found. Please confirm your details.'));
            } else {
                Flux::toast(variant: 'error', text: __('Invalid or already used pre-registration QR code.'));
            }
        }
    }

    protected function activeLogForQrToken(?string $token): ?VisitorLog
    {
        return app(VisitorCheckInService::class)->activeLogForToken($token);
    }

    #[Computed]
    public function pendingQrVisitor(): ?VisitorLog
    {
        return $this->activeLogForQrToken($this->pendingQrCheckoutToken);
    }

    public function confirmQrCheckOut(): void
    {
        $visitorLog = $this->pendingQrVisitor;

        if (! $visitorLog) {
            Flux::toast(variant: 'error', text: 'Invalid or already checked out QR code.');

            return;
        }

        app(VisitorCheckOutService::class)->checkOut($visitorLog, $this->checkoutPhoto);

        Flux::toast(variant: 'success', text: __(':name has been checked out.', ['name' => $visitorLog->visitor->name]));

        $this->pendingQrCheckoutToken = null;
        $this->showQrCheckoutModal = false;
        $this->checkoutPhoto = '';

        $this->resetForm();
    }

    public function cancelQrCheckOut(): void
    {
        $this->pendingQrCheckoutToken = null;
        $this->showQrCheckoutModal = false;
        $this->checkoutPhoto = '';
    }

    public function checkOutByToken(string $token): void
    {
        if (! $this->activeLogForQrToken($token)) {
            Flux::toast(variant: 'error', text: __('Invalid QR code or visitor is not on-site.'));

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
                    'phone' => 'required|string|max:20',
                    'validIdNumber' => 'nullable|string|max:50',
                ]);
            } elseif (! $this->selectedVisitorId && ! $this->selectedPreRegistrationId) {
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
        $this->selectedPreRegistrationId = null;
        $this->showCreateForm = false;
        $this->search = '';
    }

    public function startCreating(): void
    {
        $this->showCreateForm = true;
        $this->selectedVisitorId = null;
        $this->selectedPreRegistrationId = null;
        $this->search = '';
        $this->name = '';
        $this->email = '';
        $this->phone = '';
        $this->company = '';
        $this->validIdNumber = '';
        $this->photo = '';
        $this->idCardImage = null;
        $this->idCardPreview = '';
        $this->idOcrText = '';
        $this->showIdScanModal = false;
        $this->processingIdCard = false;
    }

    public function cancelCreating(): void
    {
        $this->showCreateForm = false;
        $this->search = '';
    }

    public function changeVisitor(): void
    {
        $this->selectedVisitorId = null;
        $this->selectedPreRegistrationId = null;
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

    // ── Pre-registration match ──

    #[Computed]
    public function preRegistrationMatches(): Collection
    {
        if ($this->search === '' || ! Schema::hasTable('pre_registrations')) {
            return new Collection;
        }

        return PreRegistration::pending()
            ->where(function ($q) {
                $q->where('name', 'like', '%' . $this->search . '%')
                    ->orWhere('email', 'like', '%' . $this->search . '%')
                    ->orWhere('phone', 'like', '%' . $this->search . '%');
            })
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();
    }

    public function selectPreRegistration(string $id): void
    {
        $pre = PreRegistration::pending()->findOrFail($id);

        $this->selectedPreRegistrationId = $id;
        $this->selectedVisitorId = null;
        $this->showCreateForm = false;
        $this->search = '';

        $this->name = $pre->name;
        $this->email = $pre->email ?? '';
        $this->phone = $pre->phone ?? '';
        $this->company = $pre->company ?? '';
        $this->host = $pre->host ?? '';
        $this->hostUserId = $pre->host_user_id;
        $this->hostSearch = $pre->host ?? '';
        $this->purpose = $pre->purpose ?? '';
    }

    #[Computed]
    public function selectedPreRegistration(): ?PreRegistration
    {
        if ($this->selectedPreRegistrationId === null) {
            return null;
        }

        return PreRegistration::find($this->selectedPreRegistrationId);
    }

    public function scanPreRegistrationByToken(string $token): void
    {
        $pre = PreRegistration::pending()->where('qr_code_token', $token)->first();

        $this->showPreQrScanner = false;

        if (! $pre) {
            Flux::toast(variant: 'error', text: __('Invalid or already used pre-registration QR code.'));

            return;
        }

        $this->activeTab = 'checkin';
        $this->selectPreRegistration((string) $pre->id);
        Flux::toast(variant: 'success', text: __('Pre-registration found. Please confirm your details.'));
    }

    public function selectVisitorByQrToken(string $token): void
    {
        $visitor = Visitor::where('qr_code_token', $token)->first();

        $this->showPreQrScanner = false;

        if (! $visitor) {
            Flux::toast(variant: 'error', text: __('Invalid or unknown badge QR code.'));

            return;
        }

        $this->activeTab = 'checkin';
        $this->selectVisitor((string) $visitor->id);
        Flux::toast(variant: 'success', text: __('Visitor found. Please confirm your details.'));
    }

    public function changePreRegistration(): void
    {
        $this->selectedPreRegistrationId = null;
        $this->selectedVisitorId = null;
        $this->showCreateForm = false;
        $this->search = '';

        $this->name = '';
        $this->email = '';
        $this->phone = '';
        $this->company = '';
        $this->host = '';
        $this->hostUserId = null;
        $this->hostSearch = '';
        $this->purpose = '';
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

        $originalPath = $this->idCardImage->getRealPath();
        $imagePath = $this->preprocessIdCardImage($originalPath);

        try {
            $ocr = new TesseractOCR($imagePath);
            $ocr->lang('eng');
            $ocr->psm(6);
            $this->idOcrText = $ocr->run();

            $this->parseIdCardText($this->idOcrText);

            Flux::toast(variant: 'success', text: __('ID card scanned successfully! Review the extracted information.'));
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: __('Failed to process ID card: ') . $e->getMessage());
        } finally {
            if ($imagePath !== $originalPath) {
                @unlink($imagePath);
            }
            $this->processingIdCard = false;
        }
    }

    protected function preprocessIdCardImage(string $path): string
    {
        $source = @imagecreatefromstring((string) file_get_contents($path));

        if (! $source) {
            return $path;
        }

        $srcW = imagesx($source);
        $srcH = imagesy($source);

        $maxDim = 1600;
        $scale = min(2, max(1, $maxDim / max($srcW, $srcH)));

        $dstW = max(1, (int) round($srcW * $scale));
        $dstH = max(1, (int) round($srcH * $scale));

        $dst = imagecreatetruecolor($dstW, $dstH);
        imagecopyresampled($dst, $source, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);

        imagefilter($dst, IMG_FILTER_GRAYSCALE);
        imagefilter($dst, IMG_FILTER_CONTRAST, -25);

        imageconvolution($dst, [[0, -1, 0], [-1, 5, -1], [0, -1, 0]], 1, 0);

        $tmp = tempnam(sys_get_temp_dir(), 'idcard_').'.png';
        imagepng($dst, $tmp);

        imagedestroy($source);
        imagedestroy($dst);

        return $tmp;
    }

    protected function parseIdCardText(string $text): void
    {
        $lines = array_map('trim', explode("\n", $text));
        $lines = array_filter($lines, fn($l) => $l !== '');

        $fullText = implode(' ', $lines);

        if ($this->name === '') {
            if (preg_match('/\b([A-Z]{2,}),\s*([A-Z][a-z]+(?:\s+[A-Z][a-z]+)*)\b/', $fullText, $matches)) {
                $this->name = $matches[2].' '.$matches[1];
            } elseif (preg_match('/\b([A-Z]{2,})\s*,\s*([A-Z][A-Z.\s]+)\b/', $fullText, $matches)) {
                $this->name = ucwords(strtolower($matches[2].' '.$matches[1]));
            } elseif (preg_match('/(?:\bName\b|NMN|NLN)[\s:.]*([A-Z][a-zA-Z\s.\'-]+)/', $fullText, $matches)) {
                $this->name = trim($matches[1]);
            } elseif (preg_match('/\b([A-Z][a-z]+\s+[A-Z][a-z]+)\b/', $fullText, $matches)) {
                $this->name = $matches[1];
            }
        }

        if ($this->email === '' && preg_match('/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/', $fullText, $matches)) {
            $this->email = $matches[0];
        }

        if ($this->phone === '' && preg_match('/(\+?1[\s.-]?)?\(?([0-9]{3})\)?[\s.-]?([0-9]{3})[\s.-]?([0-9]{4})/', $fullText, $matches)) {
            $phone = preg_replace('/[^0-9+]/', '', $matches[0]);
            $this->phone = strlen($phone) === 10 ? '('.substr($phone, 0, 3).') '.substr($phone, 3, 3).'-'.substr($phone, 6) : $phone;
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
        return app(VisitorCheckInService::class)->generateBadgeNumber();
    }

    // ── Check-in ──

    public function checkIn(): void
    {
        $this->validate([
            'visitPhoto' => 'nullable|string',
            'host' => 'nullable|string|max:255',
            'purpose' => 'nullable|string|max:255',
        ]);

        $result = app(VisitorCheckInService::class)->checkIn([
            'visitor_id' => $this->selectedVisitorId,
            'pre_registration_id' => $this->selectedPreRegistrationId,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'company' => $this->company,
            'valid_id_number' => $this->validIdNumber,
            'photo' => $this->photo,
            'host' => $this->host,
            'host_user_id' => $this->hostUserId,
            'purpose' => $this->purpose,
            'visit_photo' => $this->visitPhoto,
        ]);

        $this->lastLog = $result['log'];
        $this->justCheckedIn = true;

        Flux::toast(variant: 'success', text: __('Welcome, :name! Badge: :badge', ['name' => $result['visitor']->name, 'badge' => $result['badge_number']]));
    }

    public function resetForm(): void
    {
        $this->reset(
            'step', 'activeTab', 'search', 'selectedVisitorId', 'selectedPreRegistrationId', 'showCreateForm',
            'name', 'email', 'phone', 'company', 'validIdNumber', 'photo',
            'hostSearch', 'host', 'hostUserId', 'useCustomHost', 'purpose', 'visitPhoto',
            'justCheckedIn', 'lastLog', 'checkoutPhoto'
        );
        $this->step = 1;
    }

    // ── Check-out ──

    public function confirmCheckOut(string $visitorLogId): void
    {
        $this->pendingCheckoutId = $visitorLogId;
        $this->checkoutPhoto = '';
        $this->showCheckoutModal = true;
    }

    public function cancelCheckOut(): void
    {
        $this->pendingCheckoutId = null;
        $this->checkoutPhoto = '';
        $this->showCheckoutModal = false;
    }

    public function executeCheckOut(): void
    {
        $visitorLog = VisitorLog::with('visitor')->findOrFail($this->pendingCheckoutId);

        app(VisitorCheckOutService::class)->checkOut($visitorLog, $this->checkoutPhoto);

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
        return app(VisitorCheckInService::class)->flaggedMatch($this->selectedVisitor, $this->name);
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
    {{-- Pre-load skeleton --}}
    <div class="no-print fixed inset-0 z-[100] flex flex-col items-center gap-6 bg-white p-6 dark:bg-neutral-900"
         x-data="{ ready: false }"
         x-init="window.addEventListener('load', () => setTimeout(() => ready = true, 250))"
         x-show="!ready">
        <div class="flex w-full max-w-4xl flex-wrap items-center justify-between gap-y-2">
            <div class="flex items-center gap-2">
                <flux:skeleton animate="pulse" class="h-10 w-10 rounded-xl sm:h-12 sm:w-12" />
                <div class="space-y-2">
                    <flux:skeleton animate="pulse" class="h-4 w-32 rounded" />
                    <flux:skeleton animate="pulse" class="h-3 w-44 rounded" />
                </div>
            </div>
            <div class="flex items-center gap-3 sm:gap-6">
                <div class="space-y-2 text-right">
                    <flux:skeleton animate="pulse" class="ml-auto h-3 w-10 rounded" />
                    <flux:skeleton animate="pulse" class="ml-auto h-5 w-8 rounded" />
                </div>
                <flux:skeleton animate="pulse" class="h-8 w-px rounded sm:h-10" />
                <div class="space-y-2 text-right">
                    <flux:skeleton animate="pulse" class="ml-auto h-3 w-12 rounded" />
                    <flux:skeleton animate="pulse" class="ml-auto h-5 w-8 rounded" />
                </div>
            </div>
        </div>
        <div class="grid w-full max-w-sm grid-cols-2 gap-0.5 rounded-lg bg-neutral-100 p-1 dark:bg-neutral-800">
            <flux:skeleton animate="pulse" class="flex h-8" />
            <flux:skeleton animate="pulse" class="flex h-8" />
        </div>
        <div class="w-full max-w-4xl rounded-xl border border-neutral-200 bg-white p-6 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
            <flux:skeleton class="h-6 w-1/3" animate="pulse" />
            <div class="mt-4 space-y-4">
                <flux:skeleton.group animate="pulse" class="space-y-2">
                    <flux:skeleton.line />
                    <flux:skeleton.line />
                    <flux:skeleton.line />
                </flux:skeleton.group>
                <div class="flex gap-3">
                    <flux:skeleton class="h-12 flex-1" animate="shimmer" />
                </div>
            </div>
        </div>
    </div>
    <style>
        @page {
            size: 80mm 120mm;
            margin: 0;
        }

        @media print {
            body * { visibility: hidden; }
            #badge-print-area, #badge-print-area * { visibility: visible; }
            #badge-print-area {
                position: absolute;
                inset: 0;
                print-color-adjust: exact;
                -webkit-print-color-adjust: exact;
            }
            .no-print { display: none !important; }
        }
    </style>

    {{-- Header --}}
    <div class="no-print">
        <div class="mx-auto flex w-full max-w-4xl flex-wrap items-center justify-between gap-y-2 pb-4">
            <div class="flex items-center gap-2">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-neutral-900 text-base font-bold text-white dark:bg-white dark:text-neutral-900 sm:h-12 sm:w-12 sm:text-lg">
                    VK
                </div>
                <div class="min-w-0">
                    <h1 class="text-base font-semibold text-neutral-900 dark:text-white sm:text-lg">{{ __('Visita Kiosk') }}</h1>
                    <p class="text-[10px] text-neutral-500 dark:text-neutral-400">{{ __('Visitor Management System') }}</p>
                </div>
            </div>
            <div class="flex items-center gap-3 sm:gap-6">
                <div class="text-right">
                    <p class="text-xs text-neutral-500 dark:text-neutral-400 sm:text-sm">{{ __('Today') }}</p>
                    <p class="text-lg font-bold text-neutral-900 dark:text-white sm:text-xl">{{ $this->todayCount }}</p>
                </div>
                <div class="h-8 w-px bg-neutral-200 dark:bg-neutral-800 sm:h-10"></div>
                <div class="text-right">
                    <p class="text-xs text-neutral-500 dark:text-neutral-400 sm:text-sm">{{ __('On-site') }}</p>
                    <p class="text-lg font-bold text-emerald-600 dark:text-emerald-400 sm:text-xl">{{ $this->onSiteCount }}</p>
                </div>
            </div>
        </div>
    </div>

    {{-- Badge success view --}}
    @if ($this->justCheckedIn && $this->lastLog)
        @php $visitor = $this->lastLog->visitor; @endphp
        <div class="flex flex-1 items-center justify-center p-3 sm:p-6">
            <div class="w-full max-w-sm overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                {{-- Success header --}}
                <div class="no-print bg-gradient-to-br from-emerald-500 to-emerald-600 px-4 py-4 text-center">
                    <div class="mx-auto mb-1 flex h-10 w-10 items-center justify-center rounded-full bg-white/20 text-white">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                    </div>
                    <h2 class="text-base font-bold text-white">{{ __('Checked In!') }}</h2>
                    <p class="text-sm text-emerald-50/90">{{ __('Welcome, :name.', ['name' => $visitor->name]) }}</p>
                </div>

                {{-- Badge --}}
                <div class="p-4">
                    <div id="badge-print-area" class="rounded-xl border-2 border-dashed border-emerald-200 bg-emerald-50/50 p-4 dark:border-emerald-800 dark:bg-emerald-900/10">
                        <div class="flex items-center justify-between">
                            <p class="text-[11px] font-semibold uppercase tracking-widest text-neutral-400 dark:text-neutral-500">{{ __('Visitor Badge') }}</p>
                            <span class="rounded-md bg-emerald-100 px-2 py-0.5 text-xs font-bold text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-400">{{ $this->lastLog->badge_number }}</span>
                        </div>

                        @if ($this->lastLog->photo)
                            <img src="{{ $this->lastLog->photo }}" alt="Visitor photo" class="mx-auto mt-3 h-24 w-24 rounded-full object-cover border-2 border-emerald-200 dark:border-emerald-800">
                        @elseif ($visitor->photo)
                            <img src="{{ $visitor->photo }}" alt="Visitor photo" class="mx-auto mt-3 h-24 w-24 rounded-full object-cover border-2 border-emerald-200 dark:border-emerald-800">
                        @endif

                        <div class="mt-2 space-y-0.5 text-center">
                            <p class="text-lg font-semibold text-neutral-900 dark:text-white">{{ $visitor->name }}</p>
                            @if ($visitor->company)
                                <p class="text-sm text-neutral-500 dark:text-neutral-400">{{ $visitor->company }}</p>
                            @endif
                            @if ($this->lastLog->host)
                                <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ __('Visiting: ') }}<span class="font-medium text-neutral-700 dark:text-neutral-300">{{ $this->lastLog->host }}</span></p>
                            @endif
                            <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ $this->lastLog->checked_in_at->format('g:i A, M j, Y') }}</p>
                        </div>

                        @if ($visitor->qr_code_token)
                            <div class="mt-3 flex justify-center">
                                <img src="{{ route('qr.code', $visitor->qr_code_token) }}" alt="QR Code" class="h-20 w-20">
                            </div>
                        @endif
                    </div>

                    <div class="no-print mt-3 flex gap-2">
                        <flux:button variant="primary" class="flex-1 !py-2.5 text-sm" onclick="window.print()">
                            {{ __('Print Badge') }}
                        </flux:button>
                        <flux:button variant="ghost" class="flex-1 !py-2.5 text-sm" wire:click="resetForm">
                            {{ __('Check In Another') }}
                        </flux:button>
                    </div>
                </div>
            </div>
        </div>
    @else
        {{-- Main content --}}
        <div class="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-6" x-data="{ activeTab: 'checkin' }">
            {{-- Segmented tabs (Flux segmented style, built from scratch) --}}
            <div class="no-print mx-auto grid w-full max-w-sm grid-cols-2 gap-0.5 rounded-lg bg-neutral-100 p-1 dark:bg-neutral-800">
                <button type="button" x-on:click="activeTab = 'checkin'"
                    class="flex h-8 items-center justify-center gap-1 whitespace-nowrap rounded px-2.5 text-xs font-medium transition-all"
                    x-bind:class="activeTab === 'checkin'
                        ? 'bg-white text-neutral-900 shadow-sm dark:bg-neutral-900 dark:text-white'
                        : 'text-neutral-600 hover:text-neutral-900 dark:text-neutral-400 dark:hover:text-white'">
                    <flux:icon.arrow-right-circle class="h-3.5 w-3.5 shrink-0" />
                    {{ __('Check In') }}
                </button>

                <button type="button" x-on:click="activeTab = 'onsite'"
                    class="flex h-8 items-center justify-center gap-1 whitespace-nowrap rounded px-2.5 text-xs font-medium transition-all"
                    x-bind:class="activeTab === 'onsite'
                        ? 'bg-white text-neutral-900 shadow-sm dark:bg-neutral-900 dark:text-white'
                        : 'text-neutral-600 hover:text-neutral-900 dark:text-neutral-400 dark:hover:text-white'">
                    <flux:icon.building-office-2 class="h-3.5 w-3.5 shrink-0" />
                    {{ __('On-Site Visitors') }}
                    <span class="whitespace-nowrap rounded-full px-1 py-0 text-[9px] font-bold"
                        x-bind:class="activeTab === 'onsite'
                            ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-400'
                            : 'bg-neutral-200 text-neutral-600 dark:bg-neutral-700 dark:text-neutral-300'">{{ $this->onSiteCount }}</span>
                </button>
            </div>

            <div x-show="activeTab === 'checkin'" x-cloak>
                {{-- Check-in wizard --}}
                <div class="w-full">
                <div class="rounded-xl border border-neutral-200 bg-white p-6 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                    <h2 class="text-lg font-semibold text-neutral-900 dark:text-white">{{ __('Check In') }}</h2>

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
                            @if ($this->selectedPreRegistration)
                                {{-- Pre-registration selected – confirm --}}
                                <p class="mb-4 text-sm text-neutral-500 dark:text-neutral-400">{{ __('We found your pre-registration. Is this you?') }}</p>
                                <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-800 dark:bg-emerald-900/20">
                                    <div class="flex items-center gap-4">
                                        <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-lg font-medium text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-400">
                                            {{ substr($this->selectedPreRegistration->name, 0, 2) }}
                                        </div>
                                        <div class="min-w-0">
                                            <p class="font-semibold text-neutral-900 dark:text-white">{{ $this->selectedPreRegistration->name }}</p>
                                            @if ($this->selectedPreRegistration->company)
                                                <p class="text-sm text-neutral-500 dark:text-neutral-400">{{ $this->selectedPreRegistration->company }}</p>
                                            @endif
                                            <p class="text-xs text-neutral-400 dark:text-neutral-500">
                                                {{ $this->selectedPreRegistration->host ? __('Visiting: ') . $this->selectedPreRegistration->host : '' }}
                                                {{ $this->selectedPreRegistration->purpose ? ' · ' . $this->selectedPreRegistration->purpose : '' }}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                <div class="mt-4 flex flex-col gap-2 sm:flex-row">
                                    <flux:button variant="primary" icon:trailing="arrow-up-right" class="w-full sm:w-auto" wire:click="nextStep">
                                        {{ __('Yes, its me') }}
                                    </flux:button>
                                    <flux:button variant="ghost" class="!py-3 w-full sm:w-auto" wire:click="changePreRegistration">
                                        {{ __('Not me') }}
                                    </flux:button>
                                </div>

                            @elseif ($this->selectedVisitor)
                                {{-- Person selected – confirm --}}
                                <p class="mb-4 text-sm text-neutral-500 dark:text-neutral-400">{{ __('Is this you?') }}</p>
                                <div class="rounded-lg border border-neutral-100 bg-neutral-50 p-4 dark:border-neutral-700 dark:bg-neutral-800/50">
                                    <div class="flex items-center gap-4">
                                        @if ($this->selectedVisitor->photo)
                                            <img src="{{ $this->selectedVisitor->photo }}" alt="" class="h-14 w-14 shrink-0 rounded-full object-cover border border-neutral-200 dark:border-neutral-700">
                                        @else
                                            <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-neutral-100 text-lg font-medium text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400">
                                                {{ substr($this->selectedVisitor->name, 0, 2) }}
                                            </div>
                                        @endif
                                        <div class="min-w-0">
                                            <p class="break-words font-semibold text-neutral-900 dark:text-white">{{ $this->selectedVisitor->name }}</p>
                                            @if ($this->selectedVisitor->email && $this->selectedVisitor->email !== $this->selectedVisitor->name)
                                                <p class="truncate text-sm text-neutral-500 dark:text-neutral-400">{{ $this->selectedVisitor->email }}</p>
                                            @endif
                                            @if ($this->selectedVisitor->valid_id_number)
                                                <p class="text-xs text-neutral-400 dark:text-neutral-500">ID: {{ $this->selectedVisitor->valid_id_number }}</p>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                                <div class="mt-4 flex flex-col gap-2 sm:flex-row">
                                    <flux:button variant="primary" icon:trailing="arrow-up-right" class="w-full sm:w-auto" wire:click="nextStep">
                                        {{ __('Yes, its me') }}
                                    </flux:button>
                                    <flux:button variant="ghost" class="!py-3 w-full sm:w-auto" wire:click="changeVisitor">
                                        {{ __('Not me') }}
                                    </flux:button>
                                </div>

                            @else
                                {{-- Create new person — full-screen modal --}}
                                <div class="fixed inset-0 z-40 overflow-y-auto bg-white dark:bg-neutral-900 sm:bg-black/60 sm:dark:bg-black/60 sm:backdrop-blur-sm"
                                     x-data="{ show: @entangle('showCreateForm') }"
                                     x-show="show"
                                     x-cloak
                                     x-transition:enter="transition ease-out duration-300"
                                     x-transition:enter-start="translate-y-full sm:translate-y-0 sm:translate-x-full"
                                     x-transition:enter-end="translate-y-0 sm:translate-x-0"
                                     x-transition:leave="transition ease-in duration-200"
                                     x-transition:leave-start="translate-y-0 sm:translate-x-0"
                                     x-transition:leave-end="translate-y-full sm:translate-y-0 sm:translate-x-full"
                                     x-on:click.self="$wire.cancelCreating()">
                                    <div class="flex min-h-full items-start p-4 pt-6 sm:items-center sm:justify-center sm:p-6">
                                        <div class="w-full p-0 sm:max-w-md sm:rounded-2xl sm:bg-white sm:p-6 sm:shadow-2xl sm:dark:bg-neutral-900">
                                            <div class="mb-4 flex items-start justify-between gap-4">
                                                <div>
                                                    <h2 class="text-lg font-semibold text-neutral-900 dark:text-white">{{ __('Register New Visitor') }}</h2>
                                                    <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">{{ __('Enter your details below.') }}</p>
                                                </div>
                                                <button type="button" wire:click="cancelCreating" class="shrink-0 rounded-full p-2 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-600 dark:hover:bg-neutral-800 dark:hover:text-neutral-300" aria-label="{{ __('Close') }}">
                                                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                                </button>
                                            </div>
                                            <div class="space-y-4">
                                    <flux:input wire:model="name" name="name" label="{{ __('Full Name') }}" type="text" required placeholder="{{ __('e.g. John Doe') }}" />
                                    <flux:input wire:model="email" name="email" label="{{ __('Email') }}" type="email" placeholder="{{ __('e.g. john@example.com') }}" />
                                    <flux:input wire:model="phone" name="phone" label="{{ __('Phone or Mobile') }}" type="tel" required placeholder="{{ __('e.g. +1 555-1234') }}" />
                                    <flux:input wire:model="company" name="company" label="{{ __('Company') }}" placeholder="{{ __('e.g. Acme Corp') }}" />
                                    <flux:input wire:model="validIdNumber" name="validIdNumber" label="{{ __('Valid ID Number') }}" placeholder="{{ __('e.g. DL-12345678') }}" />

                                    {{-- ID photo capture --}}
                                    <div x-data="{
                                        photo: @entangle('photo'),
                                        stream: null,
                                        cameraActive: false,
                                        videoReady: false,
                                        async startCamera() {
                                            try {
                                                this.stream = await navigator.mediaDevices.getUserMedia({ video: { width: 1280, height: 720, facingMode: 'environment' } });
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
                                            <div x-show="!photo">
                                                <svg class="mx-auto h-8 w-8 text-neutral-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                                <p class="mt-2 text-sm text-neutral-500 dark:text-neutral-400">{{ __('Take a photo of your ID card') }}</p>
                                                <flux:button variant="primary" size="sm" class="mt-2" x-on:click="startCamera()">{{ __('Capture ID Photo') }}</flux:button>
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

                                        {{-- Full-screen camera scanner --}}
                                        <div x-show="cameraActive" x-cloak class="fixed inset-0 z-50 flex flex-col bg-black">
                                            <div class="relative flex flex-1 items-center justify-center overflow-hidden">
                                                <video x-ref="idVideo" autoplay playsinline class="h-full w-full object-cover"></video>
                                                <div class="pointer-events-none absolute inset-0 flex items-center justify-center">
                                                    <div class="h-44 w-72 rounded-2xl border-2 border-white/80 shadow-[0_0_0_9999px_rgba(0,0,0,0.35)] sm:h-48 sm:w-80"></div>
                                                </div>
                                                <div class="pointer-events-none absolute inset-x-0 top-0 flex items-center justify-between p-4">
                                                    <p class="text-sm font-medium text-white drop-shadow">{{ __('Scan ID Card') }}</p>
                                                </div>
                                                <p class="pointer-events-none absolute bottom-20 px-6 text-center text-sm text-white/80 drop-shadow">
                                                    {{ __('Align your ID card within the frame') }}
                                                </p>
                                            </div>
                                            <div class="flex items-center justify-center gap-8 bg-black px-6 py-5">
                                                <flux:button variant="ghost" class="!text-white/80" x-on:click="stopCamera()">
                                                    {{ __('Cancel') }}
                                                </flux:button>
                                                <button type="button" x-on:click="capture()" x-bind:disabled="!videoReady" x-bind:class="videoReady ? 'opacity-100' : 'opacity-40'" class="h-16 w-16 rounded-full border-4 border-white bg-white/20 transition">
                                                    <span class="mx-auto block h-10 w-10 rounded-full bg-white"></span>
                                                </button>
                                                <span class="w-24"></span>
                                            </div>
                                        </div>
                                    </div>

                                    {{-- ID Scan Button --}}
                                    <flux:button variant="outline" class="w-full !py-3" wire:click="openIdScanModal" icon="camera">
                                        {{ __("Scan ID Card (Driver's License)") }}
                                    </flux:button>
                                    <p class="-mt-2 text-center text-xs text-neutral-500 dark:text-neutral-400">
                                        {{ __("Or scan to auto-fill details") }}
                                    </p>

                                    <flux:button variant="primary" class="w-full !py-3 text-base" x-on:click="$wire.nextStep().then(focusFirstError)" wire:loading.attr="data-flux-loading" wire:target="nextStep">
                                        {{ __('Continue') }} &rarr;
                                    </flux:button>

                                    <div class="text-center">
                                        <button type="button" wire:click="cancelCreating" class="text-sm text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-300">
                                            {{ __('Search existing visitor') }}
                                        </button>
                                    </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                                        {{-- Search first --}}
                                <p class="mb-4 text-sm text-neutral-500 dark:text-neutral-400">{{ __('Search for your name or scan your badge.') }}</p>
                                <div class="space-y-4">
                                    <div class="flex gap-2">
                                        <flux:input wire:model.live.debounce.300ms="search" placeholder="{{ __('Search by name, email, phone, or ID...') }}" icon="magnifying-glass" class="flex-1" />
                                        @if (! $this->showPreQrScanner)
                                            <flux:button variant="outline" wire:click="$set('showPreQrScanner', true)" icon="qr-code-scan" class="shrink-0" title="{{ __('Scan pre-registration QR') }}" aria-label="{{ __('Scan pre-registration QR') }}" />
                                        @endif
                                    </div>

                                    @if ($this->showPreQrScanner)
                                        <div class="rounded-lg border border-emerald-200 p-4 dark:border-emerald-800"
                                             x-data="{
                                                 reader: null,
                                                 init() {
                                                     this.$nextTick(() => this.startScanner());
                                                 },
                                                 startScanner() {
                                                     if (typeof Html5Qrcode === 'undefined') return;
                                                     this.reader = new Html5Qrcode('pre-qr-reader');
                                                     this.reader.start(
                                                         { facingMode: 'environment' },
                                                         { fps: 10, qrbox: { width: 250, height: 250 } },
                                                         (decodedText) => {
                                                             try {
                                                                 const url = new URL(decodedText);
                                                                 const pre = url.searchParams.get('pre');
                                                                 const checkout = url.searchParams.get('checkout');
                                                                 if (pre || checkout) {
                                                                     this.reader.stop().catch(() => {});
                                                                     this.reader = null;
                                                                     if (pre) {
                                                                         $wire.scanPreRegistrationByToken(pre);
                                                                     } else {
                                                                         $wire.selectVisitorByQrToken(checkout);
                                                                     }
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
                                                <p class="text-sm font-medium text-neutral-900 dark:text-white">{{ __('Scan Pre-Registration QR') }}</p>
                                                <flux:button size="sm" variant="danger" x-on:click="destroy(); $wire.$set('showPreQrScanner', false)">
                                                    {{ __('Close') }}
                                                </flux:button>
                                            </div>
                                            <div id="pre-qr-reader" class="mx-auto max-w-sm overflow-hidden rounded-lg"></div>
                                            <p class="mt-2 text-center text-xs text-neutral-400 dark:text-neutral-500">
                                                {{ __('Point the pre-registration QR code at the camera') }}
                                            </p>
                                        </div>
                                    @endif

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
                                                </button>
                                            @endforeach
                                        </div>
                                    @elseif ($this->search !== '' && $this->searchResults->isEmpty())
                                        <p class="text-sm text-neutral-400 dark:text-neutral-500 text-center py-2">{{ __('No matches found.') }}</p>
                                    @endif

                                    @if ($this->search !== '' && $this->preRegistrationMatches->isNotEmpty())
                                        <p class="mt-3 text-xs font-medium uppercase tracking-wide text-emerald-600 dark:text-emerald-400">{{ __('Pre-registered visit') }}</p>
                                        <div class="mt-1 max-h-48 overflow-y-auto rounded-lg border border-emerald-200 dark:border-emerald-700">
                                            @foreach ($this->preRegistrationMatches as $pre)
                                                <button type="button" wire:click="selectPreRegistration('{{ $pre->id }}')" class="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-emerald-50 dark:hover:bg-emerald-900/20 border-b border-emerald-100 dark:border-emerald-800 last:border-0" wire:key="{{ $pre->id }}">
                                                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-sm font-medium text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-400">
                                                        {{ substr($pre->name, 0, 2) }}
                                                    </div>
                                                    <div class="min-w-0 flex-1">
                                                        <p class="truncate font-medium text-neutral-900 dark:text-white">{{ $pre->name }}</p>
                                                        <p class="truncate text-xs text-neutral-500 dark:text-neutral-400">
                                                            {{ $pre->host ? __('Visiting: ') . $pre->host : '' }}{{ $pre->purpose ? ' · ' . $pre->purpose : '' }}
                                                        </p>
                                                    </div>
                                                </button>
                                            @endforeach
                                        </div>
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
                                    <flux:input wire:model="host" name="host" label="{{ __('Whom are you visiting?') }}" placeholder="{{ __('e.g. Sarah Johnson') }}" />
                                    <button type="button" wire:click="$set('useCustomHost', false)" class="text-sm text-emerald-600 hover:text-emerald-700 dark:text-emerald-400 dark:hover:text-emerald-300">
                                        {{ __('Search employee directory') }}
                                    </button>
                                @endif

                                <flux:input wire:model="purpose" name="purpose" label="{{ __('Purpose of visit') }}" placeholder="{{ __('e.g. Meeting, Interview, Delivery') }}" />

                                {{-- Visit selfie capture --}}
                                <div x-data="{
                                    photo: @entangle('visitPhoto'),
                                    stream: null,
                                    cameraActive: false,
                                    videoReady: false,
                                    async startCamera() {
                                        try {
                                            this.stream = await navigator.mediaDevices.getUserMedia({ video: { width: 1280, height: 720, facingMode: 'user' } });
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
                                        <div x-show="!photo">
                                            <svg class="mx-auto h-8 w-8 text-neutral-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                            <p class="mt-2 text-sm text-neutral-500 dark:text-neutral-400">{{ __('Take a selfie for your badge') }}</p>
                                            <flux:button variant="primary" size="sm" class="mt-2" x-on:click="startCamera()">{{ __('Open Camera') }}</flux:button>
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

                                    {{-- Full-screen selfie scanner --}}
                                    <div x-show="cameraActive" x-cloak class="fixed inset-0 z-50 flex flex-col bg-black">
                                        <div class="relative flex flex-1 items-center justify-center overflow-hidden">
                                            <video x-ref="visitVideo" autoplay playsinline class="h-full w-full object-cover"></video>
                                            <div class="pointer-events-none absolute inset-0 flex items-center justify-center">
                                                <div class="h-64 w-64 rounded-full border-2 border-white/80 shadow-[0_0_0_9999px_rgba(0,0,0,0.35)] sm:h-72 sm:w-72"></div>
                                            </div>
                                            <p class="pointer-events-none absolute inset-x-0 top-4 text-center text-sm font-medium text-white drop-shadow">
                                                {{ __('Scan Selfie') }}
                                            </p>
                                            <p class="pointer-events-none absolute bottom-20 px-6 text-center text-sm text-white/80 drop-shadow">
                                                {{ __('Position your face within the circle') }}
                                            </p>
                                        </div>
                                        <div class="flex items-center justify-center gap-8 bg-black px-6 py-5">
                                            <flux:button variant="ghost" class="!text-white/80" x-on:click="stopCamera()">
                                                {{ __('Cancel') }}
                                            </flux:button>
                                            <button type="button" x-on:click="capture()" x-bind:disabled="!videoReady" x-bind:class="videoReady ? 'opacity-100' : 'opacity-40'" class="h-16 w-16 rounded-full border-4 border-white bg-white/20 transition">
                                                <span class="mx-auto block h-10 w-10 rounded-full bg-white"></span>
                                            </button>
                                            <span class="w-24"></span>
                                        </div>
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
                                    <flux:button variant="primary" class="flex-1 !py-3 text-base" x-on:click="$wire.checkIn().then(focusFirstError)" wire:loading.attr="data-flux-loading" wire:target="checkIn">
                                        {{ __('Check In') }}
                                    </flux:button>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
                </div>
            </div>

            <div x-show="activeTab === 'onsite'" x-cloak>
                {{-- On-site visitors --}}
                <div class="w-full">
                <div class="rounded-xl border border-neutral-200 bg-white p-6 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                    <h2 class="mb-1 text-lg font-semibold text-neutral-900 dark:text-white">{{ __('On-Site Visitors') }}</h2>
                    <p class="mb-4 text-sm text-neutral-500 dark:text-neutral-400">{{ __('Find your name and check out when leaving.') }}</p>

                    <div class="mb-4 flex gap-2">
                        <flux:input wire:model.live="checkoutSearch" placeholder="{{ __('Search by name or host...') }}" icon="magnifying-glass" class="flex-1" />

                        @if (! $this->showQrScanner)
                            <flux:button variant="outline" wire:click="$set('showQrScanner', true)" icon="qr-code-scan" class="shrink-0" title="{{ __('Scan QR') }}" aria-label="{{ __('Scan QR') }}" />
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
                                <flux:button size="sm" variant="danger" x-on:click="destroy(); $wire.$set('showQrScanner', false)">
                                    {{ __('Close') }}
                                </flux:button>
                            </div>
                            <div id="qr-reader" class="mx-auto max-w-sm overflow-hidden rounded-lg"></div>
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
                                                @if ($v->company)
                                                    <div class="text-xs text-neutral-400 dark:text-neutral-500">
                                                        {{ $v->company }}
                                                        @if ($visitorLog->badge_number)
                                                            {{ ' · ' . __('Badge #') . Str::afterLast($visitorLog->badge_number, '-') }}
                                                        @endif
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
        </div>
    @endif

    {{-- Check-out confirmation modal --}}
    <flux:modal wire:model="showCheckoutModal" name="checkout-confirm" class="w-full sm:min-w-sm">
        @if ($this->pendingVisitor)
            <flux:heading size="lg">{{ __('Confirm Check Out') }}</flux:heading>
            <flux:text class="mt-2">
                {{ __('Confirm that :name is leaving the premises.', ['name' => $this->pendingVisitor->visitor->name]) }}
            </flux:text>
            <div class="mt-5 rounded-lg border border-dashed border-neutral-300 bg-neutral-50 p-4 text-center dark:border-neutral-700 dark:bg-neutral-800/50"
                 x-data="{
                     photo: @entangle('checkoutPhoto'),
                     stream: null,
                     cameraActive: false,
                     videoReady: false,
                     async startCamera() {
                         try {
                             this.stream = await navigator.mediaDevices.getUserMedia({ video: { width: 640, height: 480, facingMode: 'environment' } });
                             const video = this.$refs.checkoutVideo;
                             video.srcObject = this.stream;
                             video.onloadedmetadata = () => { video.play(); this.videoReady = true; };
                             this.cameraActive = true;
                         } catch (e) { alert('Camera error: ' + e.message); }
                     },
                     capture() {
                         const video = this.$refs.checkoutVideo;
                         const canvas = this.$refs.checkoutCanvas;
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
                <div x-show="!cameraActive && !photo">
                    <svg class="mx-auto h-8 w-8 text-neutral-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    <p class="mt-2 text-sm text-neutral-500 dark:text-neutral-400">{{ __('Capture a photo on check out (optional)') }}</p>
                    <flux:button variant="primary" size="sm" class="mt-2" x-on:click="startCamera()">{{ __('Capture Checkout Photo') }}</flux:button>
                </div>
                <div x-show="cameraActive">
                    <video x-ref="checkoutVideo" autoplay playsinline class="w-full aspect-square rounded-lg object-cover"></video>
                    <div class="mt-3 flex gap-2 justify-center">
                        <flux:button variant="primary" x-on:click="capture()" x-bind:disabled="!videoReady">{{ __('Capture') }}</flux:button>
                        <flux:button variant="ghost" x-on:click="stopCamera()">{{ __('Cancel') }}</flux:button>
                    </div>
                </div>
                <div x-show="photo">
                    <div class="flex items-center gap-3 justify-center">
                        <img :src="photo" alt="Checkout photo" class="h-16 w-16 rounded-lg object-cover border border-neutral-300">
                        <div class="text-left">
                            <p class="text-sm font-medium text-emerald-600">{{ __('Checkout photo captured') }}</p>
                            <button type="button" x-on:click="clearPhoto()" class="text-xs text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-300">{{ __('Remove') }}</button>
                        </div>
                    </div>
                </div>
                <canvas x-ref="checkoutCanvas" class="hidden"></canvas>
            </div>
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
        <flux:modal wire:model="showQrCheckoutModal" name="qr-checkout-confirm" class="w-full sm:min-w-sm">
            @if ($this->pendingQrVisitor)
                <flux:heading size="lg">{{ __('Confirm Check Out') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('A QR code was scanned for :name. Confirm they are leaving the premises.', ['name' => $this->pendingQrVisitor->visitor->name]) }}
                </flux:text>
                <div class="mt-5 rounded-lg border border-dashed border-neutral-300 bg-neutral-50 p-4 text-center dark:border-neutral-700 dark:bg-neutral-800/50"
                     x-data="{
                         photo: @entangle('checkoutPhoto'),
                         stream: null,
                         cameraActive: false,
                         videoReady: false,
                         async startCamera() {
                             try {
                                 this.stream = await navigator.mediaDevices.getUserMedia({ video: { width: 640, height: 480, facingMode: 'environment' } });
                                 const video = this.$refs.qrVideo;
                                 video.srcObject = this.stream;
                                 video.onloadedmetadata = () => { video.play(); this.videoReady = true; };
                                 this.cameraActive = true;
                             } catch (e) { alert('Camera error: ' + e.message); }
                         },
                         capture() {
                             const video = this.$refs.qrVideo;
                             const canvas = this.$refs.qrCanvas;
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
                    <div x-show="!cameraActive && !photo">
                        <svg class="mx-auto h-8 w-8 text-neutral-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        <p class="mt-2 text-sm text-neutral-500 dark:text-neutral-400">{{ __('Capture a photo on check out (optional)') }}</p>
                        <flux:button variant="primary" size="sm" class="mt-2" x-on:click="startCamera()">{{ __('Capture Checkout Photo') }}</flux:button>
                    </div>
                    <div x-show="cameraActive">
                        <video x-ref="qrVideo" autoplay playsinline class="w-full aspect-square rounded-lg object-cover"></video>
                        <div class="mt-3 flex gap-2 justify-center">
                            <flux:button variant="primary" x-on:click="capture()" x-bind:disabled="!videoReady">{{ __('Capture') }}</flux:button>
                            <flux:button variant="ghost" x-on:click="stopCamera()">{{ __('Cancel') }}</flux:button>
                        </div>
                    </div>
                    <div x-show="photo">
                        <div class="flex items-center gap-3 justify-center">
                            <img :src="photo" alt="Checkout photo" class="h-16 w-16 rounded-lg object-cover border border-neutral-300">
                            <div class="text-left">
                                <p class="text-sm font-medium text-emerald-600">{{ __('Checkout photo captured') }}</p>
                                <button type="button" x-on:click="clearPhoto()" class="text-xs text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-300">{{ __('Remove') }}</button>
                            </div>
                        </div>
                    </div>
                    <canvas x-ref="qrCanvas" class="hidden"></canvas>
                </div>
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
    <flux:modal wire:model="showIdScanModal" name="id-scan" class="w-full sm:min-w-md">
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

            @if ($idCardPreview && ! $this->idOcrText && ! $this->processingIdCard)
                <div class="flex gap-2">
                    <flux:button variant="primary" class="flex-1" wire:click="processIdCard">
                        {{ __('Scan ID Card') }}
                    </flux:button>
                    <flux:button variant="ghost" class="flex-1" wire:click="closeIdScanModal">
                        {{ __('Cancel') }}
                    </flux:button>
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