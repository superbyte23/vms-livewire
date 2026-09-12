<?php

use App\Models\User;
use App\Models\Visitor;
use App\Models\Visit;
use App\Services\VisitorCheckInService;
use App\Services\VisitorCheckOutService;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use thiagoalessio\TesseractOCR\TesseractOCR;

new #[Title('Visitor Kiosk')] #[Layout('layouts::kiosk')] class extends Component
{
    use WithFileUploads;

    public int $step = 1;

    public string $activeTab = 'checkin';

    // Step 1: Search or create person
    public string $search = '';

    public ?string $selectedVisitorId = null;

    public ?string $selectedBookingId = null;

    public bool $showCreateForm = false;

    public int $registerKey = 0;

    // Step 1: New person fields (government ID capture → visitors.government_id_photo)
    public string $firstname = '';

    public string $middlename = '';

    public string $lastname = '';

    public string $email = '';

    public string $phone = '';

    public string $company = '';

    public string $address = '';

    public string $governmentId = '';

    public string $validIdPhoto = '';

    public string $profilePhoto = '';

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

    public string $visitType = '';

    public string $visitPhoto = '';  // visit selfie → visits.photo

    // Check-out
    public bool $justCheckedIn = false;

    public ?Visit $lastVisit = null;

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
            if ($this->activeVisitForQrToken($token)) {
                $this->pendingQrCheckoutToken = $token;
                $this->showQrCheckoutModal = true;
            } else {
                Flux::toast(variant: 'error', text: __('Invalid QR code or visitor is not on-site.'));
            }

            return;
        }

        // ?booking= is current; ?pre= / ?scheduled= are legacy aliases.
        $bookingToken = request()->query('booking', request()->query('pre', request()->query('scheduled')));
        if ($bookingToken) {
            $booking = Visit::scheduled()->where('qr_code_token', $bookingToken)->first();

            if ($booking) {
                $this->selectBooking((string) $booking->id);
        Flux::toast(variant: 'success', text: __('Booked visit found. Continue with the visit details.'));
            } else {
                Flux::toast(variant: 'error', text: __('Invalid or already used booking QR code.'));
            }
        }
    }

    protected function activeVisitForQrToken(?string $token): ?Visit
    {
        return app(VisitorCheckInService::class)->activeVisitForToken($token);
    }

    #[Computed]
    public function pendingQrVisitor(): ?Visit
    {
        return $this->activeVisitForQrToken($this->pendingQrCheckoutToken);
    }

    public function confirmQrCheckOut(): void
    {
        $visit = $this->pendingQrVisitor;

        if (! $visit) {
            Flux::toast(variant: 'error', text: 'Invalid or already checked out QR code.');

            return;
        }

        app(VisitorCheckOutService::class)->checkOut($visit, $this->checkoutPhoto);

        Flux::toast(variant: 'success', text: __(':name has been checked out.', ['name' => $visit->visitor?->name ?? 'Visitor']));

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
        if (! $this->activeVisitForQrToken($token)) {
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
                    'firstname' => 'required|string|max:255',
                    'middlename' => 'nullable|string|max:255',
                    'lastname' => 'required|string|max:255',
                    'email' => 'nullable|email|max:255',
                    'phone' => 'required|string|max:20',
                    'address' => 'nullable|string|max:255',
                    'governmentId' => 'nullable|string|max:50',
                ]);
            } elseif (! $this->selectedVisitorId && ! $this->selectedBookingId) {
                Flux::toast(variant: 'warning', text: __('Please search and select a visitor, or register as a new person.'));

                return;
            }
            $this->step = 2;
        } elseif ($this->step === 2) {
            try {
                $this->validate([
                    'visitType' => 'required|string|max:255',
                    'purpose' => 'required|string|max:255',
                ]);
            } catch (ValidationException $e) {
                Flux::toast(variant: 'warning', text: __('Please select a visit type and enter the purpose.'));

                throw $e;
            }
            $this->step = 3;
        }
    }

    public function prevStep(): void
    {
        $this->step = max(1, $this->step - 1);
    }

    /**
     * Reactive error helpers: validate each visit field as it changes so the
     * invalid styling appears/clears while typing. Silent here — the warning
     * toast only fires on Next attempts.
     */
    public function updated(string $name, mixed $value): void
    {
        if (! in_array($name, ['visitType', 'purpose'], true)) {
            return;
        }

        try {
            $this->validateOnly($name, [
                'visitType' => 'required|string|max:255',
                'purpose' => 'required|string|max:255',
            ]);
        } catch (ValidationException $e) {
            // Swallowing here keeps the framework from persisting the errors,
            // so push this field's message into the bag for inline helpers.
            // Silent otherwise — the warning toast only fires on Next attempts.
            if ($message = $e->validator->errors()->first($name)) {
                $this->addError($name, $message);
            }
        }
    }

    // ── Person search ──

    #[Computed]
    public function searchResults(): Collection
    {
        if ($this->search === '' || ! Schema::hasTable('visitors')) {
            return new Collection;
        }

        // A visitor with a pending booking already appears under "Booked
        // visit" below — hide the duplicate plain row so each person shows
        // once. The booking flow reuses the same record.
        $linkedVisitorIds = $this->bookingMatches->pluck('visitor_id')
            ->filter()->unique()->all();

        return Visitor::search($this->search)
            ->when(! empty($linkedVisitorIds), fn ($q) => $q->whereNotIn('id', $linkedVisitorIds))
            ->orderBy('name')
            ->limit(8)
            ->get();
    }

    public function selectVisitor(string $id): void
    {
        if (Visit::isOnSite($id)) {
            Flux::toast(variant: 'warning', text: __('This visitor is already on-site. Please check out first.'));

            return;
        }

        $this->selectedVisitorId = $id;
        $this->selectedBookingId = null;
        $this->showCreateForm = false;
        $this->search = '';
        $this->hostSearch = '';
        $this->host = '';
        $this->hostUserId = null;
        $this->useCustomHost = false;
        $this->purpose = '';
        $this->visitType = '';
        $this->step = 2;
    }

    public function startCreating(): void
    {
        $this->showCreateForm = true;
        $this->registerKey++;
        $this->selectedVisitorId = null;
        $this->selectedBookingId = null;
        $this->search = '';
        $this->firstname = '';
        $this->middlename = '';
        $this->lastname = '';
        $this->email = '';
        $this->phone = '';
        $this->company = '';
        $this->address = '';
        $this->governmentId = '';
        $this->validIdPhoto = '';
        $this->profilePhoto = '';
        $this->hostSearch = '';
        $this->host = '';
        $this->hostUserId = null;
        $this->useCustomHost = false;
        $this->purpose = '';
        $this->visitType = '';
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

    #[On('visitor-registered')]
    public function registerFromWizard(string $bookingId): void
    {
        $booking = Visit::scheduled()->find($bookingId);

        if (! $booking) {
            return;
        }

        $this->cancelCreating();
        $this->selectBooking($bookingId);
    }

    public function changeVisitor(): void
    {
        $this->selectedVisitorId = null;
        $this->selectedBookingId = null;
        $this->showCreateForm = false;
        $this->search = '';
        $this->hostSearch = '';
        $this->host = '';
        $this->hostUserId = null;
        $this->useCustomHost = false;
        $this->purpose = '';
        $this->visitType = '';
    }

    public function switchVisitor(): void
    {
        $this->changeVisitor();
        $this->step = 1;
    }

    #[Computed]
    public function selectedVisitor(): ?Visitor
    {
        if ($this->selectedVisitorId === null) {
            return null;
        }

        return Visitor::find($this->selectedVisitorId);
    }

    // ── Booked visit match (single booking flow on visits) ──

    #[Computed]
    public function bookingMatches(): Collection
    {
        if ($this->search === '') {
            return new Collection;
        }

        return Visit::scheduled()
            ->with('visitor')
            ->whereHas('visitor', function ($q) {
                $q->where('name', 'like', '%'.$this->search.'%')
                    ->orWhere('email', 'like', '%'.$this->search.'%')
                    ->orWhere('phone', 'like', '%'.$this->search.'%');
            })
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();
    }

    #[Computed]
    public function todayScheduled(): Collection
    {
        return Visit::scheduled()
            ->with('visitor')
            ->whereDate('expected_date', today())
            ->orderBy('expected_date')
            ->orderBy('created_at')
            ->limit(10)
            ->get();
    }

    public function selectBooking(string $id): void
    {
        $booking = Visit::scheduled()->with('visitor')->findOrFail($id);

        if ($booking->visitor_id && Visit::isOnSite($booking->visitor_id)) {
            Flux::toast(variant: 'warning', text: __('This visitor is already on-site. Please check out first.'));

            return;
        }

        $this->selectedBookingId = $id;
        // The linked visitor is the source of truth — reuse it instead of
        // creating a duplicate record at check-in.
        $this->selectedVisitorId = $booking->visitor_id;
        $this->showCreateForm = false;
        $this->search = '';
        $this->useCustomHost = false;

        $this->firstname = $booking->visitor?->firstname ?? '';
        $this->middlename = $booking->visitor?->middlename ?? '';
        $this->lastname = $booking->visitor?->lastname ?? '';
        $this->email = $booking->visitor?->email ?? '';
        $this->phone = $booking->visitor?->phone ?? '';
        $this->company = $booking->visitor?->company ?? '';
        $this->address = $booking->visitor?->address ?? '';
        $this->governmentId = $booking->visitor?->government_id ?? '';
        $this->host = $booking->host ?? '';
        $this->hostUserId = $booking->host_user_id;
        $this->hostSearch = $booking->host ?? '';
        $this->purpose = $booking->purpose ?? '';
        $this->visitType = $booking->visit_type ?? '';
        $this->step = 2;
    }

    public function scanBookingByToken(string $token): void
    {
        $booking = Visit::scheduled()->where('qr_code_token', $token)->first();

        $this->showPreQrScanner = false;

        if (! $booking) {
            Flux::toast(variant: 'error', text: __('Invalid or already used booking QR code.'));

            return;
        }

        $this->activeTab = 'checkin';
        $this->selectBooking((string) $booking->id);
        Flux::toast(variant: 'success', text: __('Booked visit found. Please confirm your details.'));
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
        Flux::toast(variant: 'success', text: __('Visitor found. Continue with the visit details.'));
    }

    // ── Host ──

    #[Computed]
    public function availableHosts(): Collection
    {
        if ($this->hostSearch === '') {
            return User::orderBy('name')->limit(10)->get();
        }

        return User::where('name', 'like', '%'.$this->hostSearch.'%')
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

    public function updatedUseCustomHost(bool $value): void
    {
        if ($value) {
            $this->host = '';
            $this->hostUserId = null;
            $this->hostSearch = '';
        }
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
        } catch (Throwable $e) {
            Flux::toast(variant: 'danger', text: __('Failed to process ID card: ').$e->getMessage());
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

    protected function fillNameParts(string $fullName): void
    {
        $tokens = preg_split('/\s+/', trim($fullName), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $this->firstname = $tokens[0] ?? '';
        $this->lastname = count($tokens) > 1 ? end($tokens) : '';
        $this->middlename = count($tokens) > 2 ? implode(' ', array_slice($tokens, 1, -1)) : '';
    }

    protected function parseIdCardText(string $text): void
    {
        $lines = array_map('trim', explode("\n", $text));
        $lines = array_filter($lines, fn ($l) => $l !== '');

        $fullText = implode(' ', $lines);

        if ($this->firstname === '' && $this->lastname === '') {
            $scanned = null;

            if (preg_match('/\b([A-Z]{2,}),\s*([A-Z][a-z]+(?:\s+[A-Z][a-z]+)*)\b/', $fullText, $matches)) {
                $scanned = $matches[2].' '.$matches[1];
            } elseif (preg_match('/\b([A-Z]{2,})\s*,\s*([A-Z][A-Z.\s]+)\b/', $fullText, $matches)) {
                $scanned = ucwords(strtolower($matches[2].' '.$matches[1]));
            } elseif (preg_match('/(?:\bName\b|NMN|NLN)[\s:.]*([A-Z][a-zA-Z\s.\'-]+)/', $fullText, $matches)) {
                $scanned = trim($matches[1]);
            } elseif (preg_match('/\b([A-Z][a-z]+\s+[A-Z][a-z]+)\b/', $fullText, $matches)) {
                $scanned = $matches[1];
            }

            if ($scanned) {
                $this->fillNameParts($scanned);
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
                    ! preg_match('/^[A-Z]{2,}\s*$/', $line)) {
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
        $this->reset('firstname', 'middlename', 'lastname', 'email', 'phone', 'company', 'address', 'governmentId');
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
            'visitType' => 'nullable|string|max:255',
        ]);

        $typedName = trim(preg_replace('/\s+/', ' ', $this->firstname.' '.$this->middlename.' '.$this->lastname)) ?? '';

        $result = app(VisitorCheckInService::class)->checkIn([
            'visitor_id' => $this->selectedVisitorId,
            'booking_id' => $this->selectedBookingId,
            'name' => $typedName,
            'firstname' => $this->firstname ?: null,
            'middlename' => $this->middlename ?: null,
            'lastname' => $this->lastname ?: null,
            'email' => $this->email,
            'phone' => $this->phone,
            'company' => $this->company,
            'address' => $this->address ?: null,
            'government_id' => $this->governmentId ?: null,
            'government_id_photo' => $this->validIdPhoto ?: null,
            'photo' => $this->profilePhoto ?: null,
            'host' => $this->host,
            'host_user_id' => $this->hostUserId,
            'purpose' => $this->purpose,
            'visit_type' => $this->visitType ?: null,
            'visit_photo' => $this->visitPhoto,
        ]);

        $this->lastVisit = $result['visit'];
        $this->justCheckedIn = true;

        Flux::toast(variant: 'success', text: __('Welcome, :name! Badge: :badge', ['name' => $result['visitor']->name, 'badge' => $result['badge_number']]));
    }

    public function resetForm(): void
    {
        $this->reset(
            'step', 'activeTab', 'search', 'selectedVisitorId', 'selectedBookingId', 'showCreateForm',
            'firstname', 'middlename', 'lastname', 'email', 'phone', 'company', 'address', 'governmentId', 'validIdPhoto', 'profilePhoto',
            'hostSearch', 'host', 'hostUserId', 'useCustomHost', 'purpose', 'visitType', 'visitPhoto',
            'justCheckedIn', 'lastVisit', 'checkoutPhoto'
        );
        $this->step = 1;
    }

    // ── Check-out ──

    public function confirmCheckOut(string $visitId): void
    {
        $this->pendingCheckoutId = $visitId;
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
        $visit = Visit::with('visitor')->findOrFail($this->pendingCheckoutId);

        app(VisitorCheckOutService::class)->checkOut($visit, $this->checkoutPhoto);

        Flux::toast(variant: 'success', text: __(':name has been checked out.', ['name' => $visit->visitor?->name ?? 'Visitor']));

        $this->pendingCheckoutId = null;
        $this->showCheckoutModal = false;
    }

    #[Computed]
    public function confirmDateLabel(): string
    {
        $date = $this->selectedBookingId
            ? Visit::find($this->selectedBookingId)?->expected_date
            : null;

        return ($date ?? now())->format('l, M j, Y');
    }

    #[Computed]
    public function pendingVisitor(): ?Visit
    {
        if ($this->pendingCheckoutId === null) {
            return null;
        }

        return Visit::with('visitor')->find($this->pendingCheckoutId);
    }

    // ── Computed ──

    #[Computed]
    public function checkedInVisitors(): Collection
    {
        if (! Schema::hasTable('visits')) {
            return new Collection;
        }

        return Visit::with('visitor')
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

        return $this->checkedInVisitors->filter(function ($visit) {
            $name = strtolower($visit->visitor->name ?? '');
            $host = strtolower($visit->host ?? '');

            return str_contains($name, strtolower($this->checkoutSearch))
                || str_contains($host, strtolower($this->checkoutSearch));
        })->values();
    }

    #[Computed]
    public function onSiteVisitorIds(): array
    {
        if (! Schema::hasTable('visits')) {
            return [];
        }

        return Visit::where('status', 'checked_in')
            ->pluck('visitor_id')
            ->filter()
            ->unique()
            ->all();
    }

    #[Computed]
    public function flaggedMatch(): ?Visitor
    {
        $typedName = trim(preg_replace('/\s+/', ' ', $this->firstname.' '.$this->middlename.' '.$this->lastname)) ?? '';

        return app(VisitorCheckInService::class)->flaggedMatch($this->selectedVisitor, $typedName);
    }

    #[Computed]
    public function todayCount(): int
    {
        if (! Schema::hasTable('visits')) {
            return 0;
        }

        return Visit::whereDate('created_at', today())->count();
    }

    #[Computed]
    public function onSiteCount(): int
    {
        if (! Schema::hasTable('visits')) {
            return 0;
        }

        return Visit::where('status', 'checked_in')->count();
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
        <div class="mx-auto flex w-full max-w-2xl flex-wrap items-center justify-between gap-y-2 pb-4">
            <div class="flex items-center gap-2">
                <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-neutral-900 text-base font-bold text-white dark:bg-white dark:text-neutral-900 sm:h-12 sm:w-12 sm:text-lg">
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
                    <p class="text-xl font-bold text-neutral-900 dark:text-white sm:text-2xl">{{ $this->todayCount }}</p>
                </div>
                <div class="h-8 w-px bg-neutral-200 dark:bg-neutral-800 sm:h-10"></div>
                <div class="text-right">
                    <p class="text-xs text-neutral-500 dark:text-neutral-400 sm:text-sm">{{ __('On-site') }}</p>
                    <p class="text-xl font-bold text-emerald-600 dark:text-emerald-400 sm:text-2xl">{{ $this->onSiteCount }}</p>
                </div>
            </div>
        </div>
    </div>

    {{-- Badge success view --}}
    @if ($this->justCheckedIn && $this->lastVisit)
        @php $visitor = $this->lastVisit->visitor; @endphp
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
                            <span class="rounded-md bg-emerald-100 px-2 py-0.5 text-xs font-bold text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-400">{{ $this->lastVisit->badge_number }}</span>
                        </div>

                        @if ($this->lastVisit->photo)
                            <img src="{{ $this->lastVisit->photo }}" alt="Visitor photo" class="mx-auto mt-3 h-24 w-24 rounded-full object-cover border-2 border-emerald-200 dark:border-emerald-800">
                        @elseif ($visitor->photo)
                            <img src="{{ $visitor->photo }}" alt="Visitor photo" class="mx-auto mt-3 h-24 w-24 rounded-full object-cover border-2 border-emerald-200 dark:border-emerald-800">
                        @endif

                        <div class="mt-2 space-y-0.5 text-center">
                            <p class="text-lg font-semibold text-neutral-900 dark:text-white">{{ $visitor->name }}</p>
                            @if ($visitor->company)
                                <p class="text-sm text-neutral-500 dark:text-neutral-400">{{ $visitor->company }}</p>
                            @endif
                            @if ($this->lastVisit->host)
                                <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ __('Visiting: ') }}<span class="font-medium text-neutral-700 dark:text-neutral-300">{{ $this->lastVisit->host }}</span></p>
                            @endif
                            <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ $this->lastVisit->checked_in_at->format('g:i A, M j, Y') }}</p>
                        </div>

                        @if ($visitor->qr_code_token)
                            <div class="mt-3 flex justify-center">
                                <img src="{{ route('qr.code', $visitor->qr_code_token) }}" alt="QR Code" class="h-20 w-20">
                            </div>
                        @endif
                    </div>

                    <div class="no-print mt-3 flex gap-2">
                        <flux:button variant="primary" class="flex-1 !py-3 text-sm" onclick="window.print()">
                            {{ __('Print Badge') }}
                        </flux:button>
                        <flux:button variant="ghost" class="flex-1 !py-3 text-sm" wire:click="resetForm">
                            {{ __('Check In Another') }}
                        </flux:button>
                    </div>
                </div>
            </div>
        </div>
    @else
        {{-- Main content --}}
        <div class="mx-auto flex w-full max-w-2xl flex-1 flex-col gap-6" x-data="{ activeTab: 'checkin' }">
            {{-- Segmented tabs (Flux segmented style, built from scratch) --}}
            <div class="no-print mx-auto grid w-full max-w-md grid-cols-2 gap-1 rounded-xl bg-neutral-100 p-1.5 dark:bg-neutral-800">
                <button type="button" x-on:click="activeTab = 'checkin'"
                    class="flex h-11 items-center justify-center gap-1.5 whitespace-nowrap rounded-lg px-3 text-sm font-medium transition-all"
                    x-bind:class="activeTab === 'checkin'
                        ? 'bg-white text-neutral-900 shadow-sm dark:bg-neutral-900 dark:text-white'
                        : 'text-neutral-600 hover:text-neutral-900 dark:text-neutral-400 dark:hover:text-white'">
                    <flux:icon.arrow-right-circle class="h-4 w-4 shrink-0" />
                    {{ __('Check In') }}
                </button>

                <button type="button" x-on:click="activeTab = 'onsite'"
                    class="flex h-11 items-center justify-center gap-1.5 whitespace-nowrap rounded-lg px-3 text-sm font-medium transition-all"
                    x-bind:class="activeTab === 'onsite'
                        ? 'bg-white text-neutral-900 shadow-sm dark:bg-neutral-900 dark:text-white'
                        : 'text-neutral-600 hover:text-neutral-900 dark:text-neutral-400 dark:hover:text-white'">
                    <flux:icon.building-office-2 class="h-4 w-4 shrink-0" />
                    {{ __('On-Site Visitors') }}
                    <span class="whitespace-nowrap rounded-full px-1.5 py-0.5 text-[10px] font-bold"
                        x-bind:class="activeTab === 'onsite'
                            ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-400'
                            : 'bg-neutral-200 text-neutral-600 dark:bg-neutral-700 dark:text-neutral-300'">{{ $this->onSiteCount }}</span>
                </button>
            </div>

            <div x-show="activeTab === 'checkin'" x-cloak>
                {{-- Check-in wizard --}}
                <div class="w-full">
                <div class="rounded-xl border border-neutral-200 bg-white p-6 shadow-sm sm:p-8 dark:border-neutral-800 dark:bg-neutral-900">
                    <h2 class="text-xl font-semibold text-neutral-900 dark:text-white">{{ __('Check In') }}</h2>

                    {{-- Step indicator --}}
                    <div class="mt-5 flex items-center gap-3 text-sm font-medium">
                        <span @class(['flex items-center gap-2', 'text-emerald-600 dark:text-emerald-400' => $this->step >= 1, 'text-neutral-400 dark:text-neutral-500' => $this->step < 1])>
                            <span @class(['flex h-8 w-8 items-center justify-center rounded-full text-sm font-bold', 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400' => $this->step >= 1, 'bg-neutral-100 text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400' => $this->step < 1])>1</span>
                            {{ __('Identity') }}
                        </span>
                        <span class="h-px flex-1 bg-neutral-200 dark:bg-neutral-700"></span>
                        <span @class(['flex items-center gap-2', 'text-emerald-600 dark:text-emerald-400' => $this->step >= 2, 'text-neutral-400 dark:text-neutral-500' => $this->step < 2])>
                            <span @class(['flex h-8 w-8 items-center justify-center rounded-full text-sm font-bold', 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400' => $this->step >= 2, 'bg-neutral-100 text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400' => $this->step < 2])>2</span>
                            {{ __('Visit') }}
                        </span>
                        <span class="h-px flex-1 bg-neutral-200 dark:bg-neutral-700"></span>
                        <span @class(['flex items-center gap-2', 'text-emerald-600 dark:text-emerald-400' => $this->step >= 3, 'text-neutral-400 dark:text-neutral-500' => $this->step < 3])>
                            <span @class(['flex h-8 w-8 items-center justify-center rounded-full text-sm font-bold', 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400' => $this->step >= 3, 'bg-neutral-100 text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400' => $this->step < 3])>3</span>
                            {{ __('Confirm') }}
                        </span>
                    </div>

                    <div class="mt-6">
                        {{-- Step 1: Search or Create Person --}}
                        @if ($this->step === 1)
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
                                                    <h2 class="text-lg font-semibold text-neutral-900 dark:text-white">{{ __('Schedule a visit') }}</h2>
                                                    <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">{{ __('Enter your details below.') }}</p>
                                                </div>
                                                <button type="button" wire:click="cancelCreating" class="shrink-0 rounded-full p-2 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-600 dark:hover:bg-neutral-800 dark:hover:text-neutral-300" aria-label="{{ __('Close') }}">
                                                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                                </button>
                                            </div>
                                            <div class="space-y-4">
                                                <livewire:pages::components.booking-wizard :key="'kiosk-register-'.$this->registerKey" />

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
                                            <flux:button variant="outline" wire:click="$set('showPreQrScanner', true)" icon="qr-code" class="shrink-0" title="{{ __('Scan booking QR') }}" aria-label="{{ __('Scan booking QR') }}" />
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
                                                                  const booking = url.searchParams.get('booking') || url.searchParams.get('pre') || url.searchParams.get('scheduled');
                                                                  const checkout = url.searchParams.get('checkout');
                                                                  if (booking || checkout) {
                                                                      this.reader.stop().catch(() => {});
                                                                      this.reader = null;
                                                                      if (booking) {
                                                                          $wire.scanBookingByToken(booking);
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
                                                <p class="text-sm font-medium text-neutral-900 dark:text-white">{{ __('Scan Booking QR') }}</p>
                                                <flux:button size="sm" variant="danger" x-on:click="destroy(); $wire.$set('showPreQrScanner', false)">
                                                    {{ __('Close') }}
                                                </flux:button>
                                            </div>
                                            <div id="pre-qr-reader" class="mx-auto max-w-sm overflow-hidden rounded-lg"></div>
                                            <p class="mt-2 text-center text-xs text-neutral-400 dark:text-neutral-500">
                                                {{ __('Point the booking QR code at the camera') }}
                                            </p>
                                        </div>
                                    @endif

                                    @if ($this->search !== '' && $this->searchResults->isNotEmpty())
                                        <div class="max-h-64 overflow-y-auto rounded-lg border border-neutral-200 dark:border-neutral-700">
                                            @foreach ($this->searchResults as $person)
                                                @php $isOnSite = in_array($person->id, $this->onSiteVisitorIds, true); @endphp
                                                <button type="button" wire:click="selectVisitor('{{ $person->id }}')" title="{{ $isOnSite ? __('Already on-site — check out first') : '' }}" wire:key="visitor-{{ $person->id }}" class="flex w-full items-center gap-3 px-4 py-3 text-left border-b border-neutral-100 dark:border-neutral-700 last:border-0 {{ $isOnSite ? 'cursor-pointer bg-red-50/60 opacity-90 dark:bg-red-900/10' : 'hover:bg-neutral-50 dark:hover:bg-neutral-800' }}">
                                                    @if ($person->photo)
                                                        <img src="{{ $person->photo }}" alt="" class="h-10 w-10 rounded-full object-cover border {{ $isOnSite ? 'border-red-200 dark:border-red-800' : 'border-neutral-200 dark:border-neutral-700' }}">
                                                    @else
                                                        <div class="flex h-10 w-10 items-center justify-center rounded-full text-sm font-medium {{ $isOnSite ? 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-400' : 'bg-neutral-100 text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400' }}">
                                                            {{ substr($person->name, 0, 2) }}
                                                        </div>
                                                    @endif
                                                    <div class="min-w-0 flex-1">
                                                        <p class="font-medium {{ $isOnSite ? 'text-red-700 dark:text-red-400' : 'text-neutral-900 dark:text-white' }} truncate">{{ $person->name }}
                                                            @if ($isOnSite)
                                                                <span class="ml-1.5 inline-flex items-center gap-1 rounded-full bg-red-50 px-1.5 py-0.5 align-middle text-[10px] font-medium text-red-700 dark:bg-red-900/30 dark:text-red-400"><span class="h-1.5 w-1.5 rounded-full bg-red-500"></span>{{ __('On-site') }}</span>
                                                            @endif
                                                        </p>
                                                        <p class="text-xs text-neutral-400 dark:text-neutral-500 truncate">
                                                            {{ $person->company ?: '' }}{{ $person->company && $person->government_id ? ' · ' : '' }}{{ $person->government_id ?: '' }}
                                                        </p>
                                                    </div>
                                                </button>
                                            @endforeach
                                        </div>
                                    @elseif ($this->search !== '' && $this->searchResults->isEmpty())
                                        <p class="text-sm text-neutral-400 dark:text-neutral-500 text-center py-2">{{ __('No matches found.') }}</p>
                                    @endif

                                    @if ($this->search !== '' && $this->bookingMatches->isNotEmpty())
                                        <p class="mt-3 text-xs font-medium uppercase tracking-wide text-emerald-600 dark:text-emerald-400">{{ __('Booked visit') }}</p>
                                        <div class="mt-1 max-h-48 overflow-y-auto rounded-lg border border-emerald-200 dark:border-emerald-700">
                                            @foreach ($this->bookingMatches as $booking)
                                                @php
                                                    $isOnSite = $booking->visitor_id && in_array($booking->visitor_id, $this->onSiteVisitorIds, true);
                                                    $isOverdue = $booking->expected_date && $booking->expected_date->lt(today());
                                                @endphp
                                                <button type="button" wire:click="selectBooking('{{ $booking->id }}')" title="{{ $isOnSite ? __('Already on-site — check out first') : ($isOverdue ? __('Scheduled date has passed') : '') }}" class="flex w-full items-center gap-3 px-4 py-3 text-left border-b border-emerald-100 dark:border-emerald-800 last:border-0 {{ $isOnSite ? 'cursor-pointer bg-red-50/60 opacity-90 dark:bg-red-900/10' : ($isOverdue ? 'bg-amber-50/60 dark:bg-amber-900/10 hover:bg-amber-50 dark:hover:bg-amber-900/20' : 'hover:bg-emerald-50 dark:hover:bg-emerald-900/20') }}" wire:key="booking-{{ $booking->id }}">
                                                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-sm font-medium {{ $isOnSite ? 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-400' : ($isOverdue ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-400' : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-400') }}">
                                                        {{ substr($booking->visitor?->name ?? '?', 0, 2) }}
                                                    </div>
                                                    <div class="min-w-0 flex-1">
                                                        <p class="truncate font-medium {{ $isOnSite ? 'text-red-700 dark:text-red-400' : ($isOverdue ? 'text-amber-700 dark:text-amber-400' : 'text-neutral-900 dark:text-white') }}">{{ $booking->visitor?->name }}
                                                            @if ($isOnSite)
                                                                <span class="ml-1.5 inline-flex items-center gap-1 rounded-full bg-red-50 px-1.5 py-0.5 align-middle text-[10px] font-medium text-red-700 dark:bg-red-900/30 dark:text-red-400"><span class="h-1.5 w-1.5 rounded-full bg-red-500"></span>{{ __('On-site') }}</span>
                                                            @endif
                                                            @if ($isOverdue)
                                                                <span class="ml-1.5 inline-flex items-center gap-1 rounded-full bg-amber-50 px-1.5 py-0.5 align-middle text-[10px] font-medium text-amber-700 dark:bg-amber-900/30 dark:text-amber-400"><span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>{{ __('Overdue') }}</span>
                                                            @endif
                                                        </p>
                                                        <p class="truncate text-xs text-neutral-500 dark:text-neutral-400">
                                                            {{ $booking->visit_type ?: '' }}{{ $booking->host ? ' · ' . __('Visiting: ') . $booking->host : '' }}{{ $booking->purpose ? ' · ' . $booking->purpose : '' }}
                                                        </p>
                                                    </div>
                                                    <div class="flex shrink-0 flex-col items-center rounded-lg px-2 py-1 {{ $isOnSite ? 'bg-neutral-100 dark:bg-neutral-800' : ($isOverdue ? 'bg-amber-100 dark:bg-amber-900/30' : 'bg-neutral-100 dark:bg-neutral-800') }}" title="{{ $booking->expected_date?->format('M j, Y') }}">
                                                        <span class="text-[10px] font-bold uppercase leading-tight {{ $isOverdue && ! $isOnSite ? 'text-amber-600 dark:text-amber-400' : 'text-neutral-500 dark:text-neutral-400' }}">{{ $booking->expected_date?->format('M') }}</span>
                                                        <span class="text-sm font-bold leading-tight {{ $isOverdue && ! $isOnSite ? 'text-amber-700 dark:text-amber-300' : 'text-neutral-900 dark:text-white' }}">{{ $booking->expected_date?->format('j') }}</span>
                                                    </div>
                                                </button>
                                            @endforeach
                                        </div>
                                    @endif

                                    @if ($this->todayScheduled->isNotEmpty())
                                        <p class="mt-3 text-xs font-medium uppercase tracking-wide text-emerald-600 dark:text-emerald-400">{{ __('Today\'s scheduled visits') }}</p>
                                        <div class="mt-1 max-h-64 overflow-y-auto rounded-lg border border-emerald-200 dark:border-emerald-700">
                                            @foreach ($this->todayScheduled as $booking)
                                                @php $isOnSite = $booking->visitor_id && in_array($booking->visitor_id, $this->onSiteVisitorIds, true); @endphp
                                                <button type="button" wire:click="selectBooking('{{ $booking->id }}')" title="{{ $isOnSite ? __('Already on-site — check out first') : '' }}" class="flex w-full items-center gap-3 border-b border-emerald-100 px-4 py-3 text-left last:border-0 dark:border-emerald-800 {{ $isOnSite ? 'cursor-pointer bg-red-50/60 opacity-90 dark:bg-red-900/10' : 'hover:bg-emerald-50 dark:hover:bg-emerald-900/20' }}" wire:key="today-booking-{{ $booking->id }}">
                                                    @if ($booking->visitor?->photo)
                                                        <img src="{{ $booking->visitor->photo }}" alt="" class="h-10 w-10 shrink-0 rounded-full border {{ $isOnSite ? 'border-red-200 dark:border-red-800' : 'border-emerald-200 dark:border-emerald-700' }} object-cover">
                                                    @else
                                                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-sm font-medium {{ $isOnSite ? 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-400' : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-400' }}">
                                                            {{ substr($booking->visitor?->name ?? '?', 0, 2) }}
                                                        </div>
                                                    @endif
                                                    <div class="min-w-0 flex-1">
                                                        <p class="truncate font-medium {{ $isOnSite ? 'text-red-700 dark:text-red-400' : 'text-neutral-900 dark:text-white' }}">{{ $booking->visitor?->name }}
                                                            @if ($isOnSite)
                                                                <span class="ml-1.5 inline-flex items-center gap-1 rounded-full bg-red-50 px-1.5 py-0.5 align-middle text-[10px] font-medium text-red-700 dark:bg-red-900/30 dark:text-red-400"><span class="h-1.5 w-1.5 rounded-full bg-red-500"></span>{{ __('On-site') }}</span>
                                                            @endif
                                                        </p>
                                                        <p class="truncate text-xs text-neutral-500 dark:text-neutral-400">
                                                            {{ $booking->visit_type ?: '' }}{{ $booking->host ? ' · ' . __('Visiting: ') . $booking->host : '' }}{{ $booking->purpose ? ' · ' . $booking->purpose : '' }}
                                                        </p>
                                                    </div>
                                                    <div class="flex shrink-0 flex-col items-center rounded-lg bg-neutral-100 px-2 py-1 dark:bg-neutral-800">
                                                        <span class="text-[10px] font-bold uppercase leading-tight text-neutral-500 dark:text-neutral-400">{{ $booking->expected_date?->format('M') }}</span>
                                                        <span class="text-sm font-bold leading-tight text-neutral-900 dark:text-white">{{ $booking->expected_date?->format('j') }}</span>
                                                    </div>
                                                </button>
                                            @endforeach
                                        </div>
                                    @endif

                                    <div class="relative">
                                        <div class="absolute inset-0 flex items-center"><span class="w-full border-t border-neutral-200 dark:border-neutral-700"></span></div>
                                        <div class="relative flex justify-center text-xs uppercase"><span class="bg-white px-2 text-neutral-400 dark:bg-neutral-900 dark:text-neutral-500">{{ __('or') }}</span></div>
                                    </div>

                                    <flux:button variant="outline" class="w-full !py-3" wire:click="startCreating" icon="user-plus">
                                        {{ __('Schedule/Register new visitor') }}
                                    </flux:button>
                                </div>
                        @endif

                        {{-- Step 2: Host, Purpose & Visit Selfie --}}
                        @if ($this->step === 2)
                            <p class="mb-4 text-sm text-neutral-500 dark:text-neutral-400">{{ __('Who are you visiting, why, and take a selfie.') }}</p>
                            @if ($this->selectedVisitor)
                                <div class="mb-4 flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 dark:border-emerald-800 dark:bg-emerald-900/20">
                                    @if ($this->selectedVisitor->photo)
                                        <img src="{{ $this->selectedVisitor->photo }}" alt="" class="h-10 w-10 shrink-0 rounded-full border border-emerald-200 object-cover dark:border-emerald-700">
                                    @else
                                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-sm font-medium text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-400">
                                            {{ substr($this->selectedVisitor->name, 0, 2) }}
                                        </div>
                                    @endif
                                    <p class="min-w-0 flex-1 truncate text-sm text-neutral-700 dark:text-neutral-300">{{ __('Checking in as :name', ['name' => $this->selectedVisitor->name]) }}</p>
                                    <button type="button" wire:click="switchVisitor" class="shrink-0 text-xs font-medium text-emerald-700 hover:text-emerald-800 dark:text-emerald-300 dark:hover:text-emerald-200">{{ __('Switch') }}</button>
                                </div>
                            @endif
                            <div class="space-y-4">
                                @if (! $this->useCustomHost)
                                    <flux:input wire:model.live="hostSearch" label="{{ __('Host / Employee') }}" placeholder="{{ __('Type name to search...') }}" icon="magnifying-glass" />
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
                                    @if ($this->host !== '')
                                        <div class="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 dark:border-emerald-800 dark:bg-emerald-900/20">
                                            <span class="text-sm text-neutral-700 dark:text-neutral-300">{{ __('Visiting: ') }}<strong>{{ $this->host }}</strong></span>
                                            <button type="button" wire:click="useCustomHostField" class="ml-auto text-xs text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-300">{{ __('Change') }}</button>
                                        </div>
                                    @endif
                                @else
                                    <flux:input wire:model="host" name="host" label="{{ __('Whom are you visiting?') }}" placeholder="{{ __('e.g. Sarah Johnson') }}" />
                                @endif

                                <flux:switch wire:model.live="useCustomHost" label="{{ __('Visiting someone else?') }}" description="{{ __('Turn on to type the name manually') }}" />

                                <div>
                                    <flux:select wire:model.live="visitType" label="{{ __('Visit type') }}">
                                        <option value="">{{ __('— Select type —') }}</option>
                                        @foreach (\App\Models\Visit::VISIT_TYPES as $type)
                                            <option value="{{ $type }}">{{ $type }}</option>
                                        @endforeach
                                    </flux:select>
                                </div>

                                <flux:textarea wire:model.live="purpose" name="purpose" label="{{ __('Purpose of visit') }}" placeholder="{{ __('e.g. Meeting, Interview, Delivery') }}" rows="3" />

                                {{-- Visit selfie capture --}}
                                <div x-data="{
                                    photo: @entangle('visitPhoto'),
                                    captureModal: false,
                                    captureMode: 'camera',
                                    cameraActive: false,
                                    videoReady: false,
                                    stream: null,
                                    openCapture() {
                                        this.captureMode = 'camera';
                                        this.captureModal = true;
                                        this.$nextTick(() => this.startCamera());
                                    },
                                    closeCapture() {
                                        this.stopCamera();
                                        this.captureModal = false;
                                    },
                                    async startCamera() {
                                        this.stopCamera();
                                        try {
                                            this.stream = await navigator.mediaDevices.getUserMedia({ video: { width: 640, height: 480, facingMode: 'user' } });
                                            const video = this.$refs.selfieVideo;
                                            video.srcObject = this.stream;
                                            video.onloadedmetadata = () => { video.play(); this.videoReady = true; };
                                            this.cameraActive = true;
                                        } catch (e) {
                                            this.cameraActive = false;
                                            this.captureMode = 'upload';
                                        }
                                    },
                                    stopCamera() {
                                        if (this.stream) { this.stream.getTracks().forEach(t => t.stop()); this.stream = null; }
                                        this.cameraActive = false;
                                        this.videoReady = false;
                                    },
                                    downscale(dataUrl) {
                                        return new Promise((resolve) => {
                                            const img = new Image();
                                            img.onload = () => {
                                                const max = 1024;
                                                let width = img.width;
                                                let height = img.height;
                                                if (width > max || height > max) {
                                                    const scale = max / Math.max(width, height);
                                                    width = Math.round(width * scale);
                                                    height = Math.round(height * scale);
                                                }
                                                const canvas = document.createElement('canvas');
                                                canvas.width = width;
                                                canvas.height = height;
                                                canvas.getContext('2d').drawImage(img, 0, 0, width, height);
                                                resolve(canvas.toDataURL('image/jpeg', 0.82));
                                            };
                                            img.onerror = () => resolve(dataUrl);
                                            img.src = dataUrl;
                                        });
                                    },
                                    async capture() {
                                        const video = this.$refs.selfieVideo;
                                        const canvas = this.$refs.selfieCanvas;
                                        const side = Math.min(video.videoWidth || 640, video.videoHeight || 480);
                                        canvas.width = side;
                                        canvas.height = side;
                                        canvas.getContext('2d').drawImage(
                                            video,
                                            (video.videoWidth - side) / 2, (video.videoHeight - side) / 2, side, side,
                                            0, 0, side, side
                                        );
                                        this.photo = await this.downscale(canvas.toDataURL('image/jpeg', 0.8));
                                        this.closeCapture();
                                    },
                                    handleFile(event) {
                                        const file = event.target.files[0];
                                        if (!file) return;
                                        const reader = new FileReader();
                                        reader.onload = async (ev) => { this.photo = await this.downscale(ev.target.result); this.closeCapture(); };
                                        reader.readAsDataURL(file);
                                        event.target.value = '';
                                    },
                                }">
                                    <p class="text-sm font-medium text-neutral-700 dark:text-neutral-300">{{ __('Badge selfie (optional)') }}</p>
                                    <button type="button" x-on:click="openCapture()" class="mt-1 flex w-full items-center gap-3 rounded-xl border border-neutral-200 bg-white px-3 py-2.5 text-left transition-colors hover:border-neutral-300 hover:bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-900 dark:hover:border-neutral-600">
                                        <span class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-full bg-neutral-100 text-neutral-400 dark:bg-neutral-800 dark:text-neutral-500">
                                            <svg x-show="! photo" class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                            <img x-show="photo" :src="photo" alt="" class="h-full w-full object-cover">
                                        </span>
                                        <span class="min-w-0 flex-1">
                                            <span class="block text-sm font-medium text-neutral-900 dark:text-white">{{ __('Take a Selfie') }}</span>
                                            <span x-show="! photo" class="block truncate text-xs text-neutral-400">{{ __('For your visitor badge') }}</span>
                                            <span x-show="photo" class="block truncate text-xs font-medium text-emerald-600 dark:text-emerald-400">{{ __('Selfie added — tap to retake') }}</span>
                                        </span>
                                        <span class="inline-flex shrink-0 items-center gap-0.5 text-xs font-medium text-emerald-600 dark:text-emerald-400">{{ __('Open camera') }}<svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg></span>
                                    </button>
                                    <button type="button" x-show="photo" x-on:click="photo = ''" class="mt-1 text-xs text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-300">{{ __('Remove photo') }}</button>

                                    <div x-show="captureModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4" x-on:click.self="closeCapture()">
                                        <div class="w-full max-w-xl overflow-hidden rounded-2xl bg-white shadow-2xl dark:bg-neutral-900" x-on:click.stop>
                                            <div class="flex items-center justify-between px-4 py-3">
                                                <p class="text-sm font-semibold text-neutral-900 dark:text-white">{{ __('Badge selfie') }}</p>
                                                <button type="button" x-on:click="closeCapture()" class="flex h-8 w-8 items-center justify-center rounded-full text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-neutral-600 dark:hover:bg-neutral-800 dark:hover:text-neutral-300" aria-label="{{ __('Close') }}">
                                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                                </button>
                                            </div>
                                            <div class="px-4">
                                                <div class="flex rounded-full bg-neutral-100 p-1 text-xs font-medium dark:bg-neutral-800">
                                                    <button type="button" x-on:click="captureMode = 'camera'; $nextTick(() => startCamera())" :class="captureMode === 'camera' ? 'bg-white text-neutral-900 shadow-sm dark:bg-neutral-700 dark:text-white' : 'text-neutral-500 dark:text-neutral-400'" class="flex-1 rounded-full px-3 py-1.5 transition-colors">{{ __('Camera') }}</button>
                                                    <button type="button" x-on:click="captureMode = 'upload'; stopCamera()" :class="captureMode === 'upload' ? 'bg-white text-neutral-900 shadow-sm dark:bg-neutral-700 dark:text-white' : 'text-neutral-500 dark:text-neutral-400'" class="flex-1 rounded-full px-3 py-1.5 transition-colors">{{ __('Upload') }}</button>
                                                </div>
                                            </div>
                                            <div class="p-4">
                                                <div x-show="captureMode === 'camera'">
                                                    <div class="overflow-hidden rounded-xl bg-black">
                                                        <video x-ref="selfieVideo" autoplay playsinline class="aspect-square w-full object-cover"></video>
                                                    </div>
                                                    <p x-show="! cameraActive" class="mt-2 text-center text-xs text-neutral-400">{{ __('Camera unavailable — upload a photo instead.') }}</p>
                                                    <div class="mt-3 flex gap-2">
                                                        <flux:button variant="ghost" x-on:click="closeCapture()">{{ __('Cancel') }}</flux:button>
                                                        <flux:button variant="primary" class="flex-1" x-on:click="capture()" x-bind:disabled="! videoReady">{{ __('Capture') }}</flux:button>
                                                    </div>
                                                </div>
                                                <div x-show="captureMode === 'upload'">
                                                    <label class="flex cursor-pointer flex-col items-center gap-1.5 rounded-xl border border-dashed border-neutral-300 bg-neutral-50 px-4 py-8 text-center transition-colors hover:border-neutral-400 dark:border-neutral-700 dark:bg-neutral-800/50 dark:hover:border-neutral-600">
                                                        <svg class="h-8 w-8 text-neutral-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/></svg>
                                                        <span class="text-sm font-medium text-neutral-700 dark:text-neutral-300">{{ __('Tap to choose a photo') }}</span>
                                                        <span class="text-xs text-neutral-400">JPG or PNG</span>
                                                        <input type="file" accept="image/*" class="hidden" x-on:change="handleFile">
                                                    </label>
                                                    <div class="mt-3 flex gap-2">
                                                        <flux:button variant="ghost" x-on:click="closeCapture()">{{ __('Cancel') }}</flux:button>
                                                        <flux:button variant="ghost" x-on:click="captureMode = 'camera'; $nextTick(() => startCamera())">{{ __('Use camera instead') }}</flux:button>
                                                    </div>
                                                </div>
                                                <canvas x-ref="selfieCanvas" class="hidden"></canvas>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="flex gap-3">
                                    <flux:button variant="ghost" icon="chevron-left" class="!py-3" wire:click="prevStep">
                                        {{ __('Back') }}
                                    </flux:button>
                                    <flux:button variant="primary" icon:trailing="eye" class="flex-1 !py-3 text-base" wire:click="nextStep">
                                        {{ __('Review') }}
                                    </flux:button>
                                </div>
                            </div>
                        @endif

                        {{-- Step 3: Confirm & Check In --}}
                        @if ($this->step === 3)
                            <p class="mb-4 text-sm text-neutral-500 dark:text-neutral-400">{{ __('Review your details before checking in.') }}</p>
                            <div class="mx-auto w-full max-w-md space-y-4">
                                @php $person = $this->selectedVisitor ?? null; @endphp

                                @if ($this->visitPhoto)
                                    <img src="{{ $this->visitPhoto }}" alt="Selfie" class="aspect-square w-full rounded-xl border border-neutral-100 object-cover sm:mx-auto sm:max-w-md dark:border-neutral-700">
                                @endif

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
                                <div class="rounded-xl border border-neutral-100 bg-neutral-50 p-4 dark:border-neutral-700 dark:bg-neutral-800/50">
                                    <div class="mb-3 flex items-center gap-3">
                                        @if ($person?->photo)
                                            <img src="{{ $person->photo }}" alt="" class="h-12 w-12 shrink-0 rounded-full border border-neutral-200 object-cover dark:border-neutral-700">
                                        @else
                                            <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-neutral-200 text-base font-medium text-neutral-500 dark:bg-neutral-700 dark:text-neutral-300">
                                                {{ substr($person?->name ?? \App\Models\Visitor::composeName($this->firstname, $this->middlename, $this->lastname) ?: '?', 0, 2) }}
                                            </div>
                                        @endif
                                        <div class="min-w-0">
                                            <p class="truncate text-base font-semibold text-neutral-900 dark:text-white">{{ $person?->name ?? \App\Models\Visitor::composeName($this->firstname, $this->middlename, $this->lastname) }}</p>
                                        </div>
                                    </div>
                                    <div class="space-y-2 border-t border-neutral-200/70 pt-3 text-sm dark:border-neutral-700/70">
                                        <div class="flex justify-between">
                                            <span class="text-neutral-500 dark:text-neutral-400">{{ __('Name') }}</span>
                                            <span class="font-medium text-neutral-900 dark:text-white">{{ $person?->name ?? \App\Models\Visitor::composeName($this->firstname, $this->middlename, $this->lastname) }}</span>
                                        </div>
                                        @if ($person?->email ?? $this->email)
                                            <div class="flex justify-between"><span class="text-neutral-500 dark:text-neutral-400">{{ __('Email') }}</span><span class="text-neutral-900 dark:text-white">{{ $person?->email ?? $this->email }}</span></div>
                                        @else
                                            <div class="flex justify-between"><span class="text-neutral-500 dark:text-neutral-400">{{ __('Email') }}</span><span class="text-neutral-400 dark:text-neutral-500">—</span></div>
                                        @endif
                                        @if ($person?->phone ?? $this->phone)
                                            <div class="flex justify-between"><span class="text-neutral-500 dark:text-neutral-400">{{ __('Phone') }}</span><span class="text-neutral-900 dark:text-white">{{ $person?->phone ?? $this->phone }}</span></div>
                                        @else
                                            <div class="flex justify-between"><span class="text-neutral-500 dark:text-neutral-400">{{ __('Phone') }}</span><span class="text-neutral-400 dark:text-neutral-500">—</span></div>
                                        @endif
                                        @if ($person?->company ?? $this->company)
                                            <div class="flex justify-between"><span class="text-neutral-500 dark:text-neutral-400">{{ __('Company') }}</span><span class="text-neutral-900 dark:text-white">{{ $person?->company ?? $this->company }}</span></div>
                                        @else
                                            <div class="flex justify-between"><span class="text-neutral-500 dark:text-neutral-400">{{ __('Company') }}</span><span class="text-neutral-400 dark:text-neutral-500">—</span></div>
                                        @endif
                                        @if ($person?->address ?? $this->address)
                                            <div class="flex justify-between gap-4"><span class="shrink-0 text-neutral-500 dark:text-neutral-400">{{ __('Address') }}</span><span class="text-right text-neutral-900 dark:text-white">{{ $person?->address ?? $this->address }}</span></div>
                                        @else
                                            <div class="flex justify-between"><span class="text-neutral-500 dark:text-neutral-400">{{ __('Address') }}</span><span class="text-neutral-400 dark:text-neutral-500">—</span></div>
                                        @endif
                                    </div>
                                </div>

                                {{-- Visit summary --}}
                                <div class="rounded-xl border border-neutral-100 bg-neutral-50 p-4 dark:border-neutral-700 dark:bg-neutral-800/50">
                                    <div class="space-y-1 text-sm">
                                        <p class="text-neutral-500 dark:text-neutral-400">{{ $this->confirmDateLabel }}</p>
                                        @if ($this->visitType)
                                            <p class="text-neutral-500 dark:text-neutral-400">{{ $this->visitType }}</p>
                                        @endif
                                        <p class="text-neutral-500 dark:text-neutral-400">{{ __('Visiting :host', ['host' => $this->host ?: '—']) }}</p>
                                        <p class="text-xs font-semibold uppercase tracking-widest text-neutral-400 dark:text-neutral-500">{{ __('Purpose') }}</p>
                                        <p class="-mt-0.5 text-neutral-900 dark:text-white">{{ $this->purpose ?: '—' }}</p>
                                    </div>
                                </div>

                                <div class="flex gap-3">
                                    <flux:button variant="ghost" icon="chevron-left" class="!py-3" wire:click="prevStep">
                                        {{ __('Back') }}
                                    </flux:button>
                                    <flux:button variant="primary" icon="check-circle" class="flex-1 !py-4 text-lg" x-on:click="$wire.checkIn().then(focusFirstError)" wire:loading.attr="data-flux-loading" wire:target="checkIn">
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
                            <flux:button variant="outline" wire:click="$set('showQrScanner', true)" icon="qr-code" class="shrink-0" title="{{ __('Scan QR') }}" aria-label="{{ __('Scan QR') }}" />
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
                        <div class="flex flex-col items-center justify-center gap-2 py-16 text-center">
                            <span class="flex h-12 w-12 items-center justify-center rounded-full bg-neutral-100 text-neutral-400 dark:bg-neutral-800 dark:text-neutral-500">
                                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            </span>
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
                                    @foreach ($this->filteredVisitors as $visit)
                                        @php $v = $visit->visitor; @endphp
                                        <tr class="group" wire:key="{{ $visit->id }}">
                                            <td class="py-5 pr-4">
                                                <span class="font-medium text-neutral-900 dark:text-white">{{ $v?->name ?? 'Deleted visitor' }}</span>
                                                @if ($v?->company)
                                                    <div class="text-xs text-neutral-400 dark:text-neutral-500">
                                                        {{ $v->company }}
                                                        @if ($visit->badge_number)
                                                            {{ ' · ' . __('Badge #') . Str::afterLast($visit->badge_number, '-') }}
                                                        @endif
                                                    </div>
                                                @endif
                                            </td>
                                            <td class="py-5 pr-4 text-neutral-600 dark:text-neutral-300 hidden md:table-cell">{{ $visit->host ?: '—' }}</td>
                                            <td class="py-5 pr-4 text-neutral-600 dark:text-neutral-300 hidden lg:table-cell">{{ Str::limit($visit->purpose ?: '—', 20) }}</td>
                                            <td class="py-5 pr-4 text-neutral-500 dark:text-neutral-400 hidden sm:table-cell whitespace-nowrap">
                                                {{ $visit->checked_in_at->format('g:i A') }}
                                            </td>
                                            <td class="py-5 text-right">
                                                <flux:button variant="outline" wire:click="confirmCheckOut('{{ $visit->id }}')" icon="arrow-right-end-on-rectangle" class="shrink-0">
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
    <flux:modal wire:model="showCheckoutModal" name="checkout-confirm" class="w-full max-w-2xl">
        @if ($this->pendingVisitor)
            <flux:heading size="lg">{{ __('Confirm Check Out') }}</flux:heading>
            <flux:text class="mt-2">
                {{ __('Confirm that :name is leaving the premises.', ['name' => $this->pendingVisitor?->visitor?->name ?? 'Visitor']) }}
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
                <flux:button variant="primary" wire:click="executeCheckOut" icon="check" class="!py-3">
                    {{ __('Confirm Check Out') }}
                </flux:button>
            </div>
        @endif
    </flux:modal>

    {{-- QR check-out confirmation modal --}}
    @if ($this->pendingQrCheckoutToken)
        <flux:modal wire:model="showQrCheckoutModal" name="qr-checkout-confirm" class="w-full max-w-2xl">
            @if ($this->pendingQrVisitor)
                <flux:heading size="lg">{{ __('Confirm Check Out') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('A QR code was scanned for :name. Confirm they are leaving the premises.', ['name' => $this->pendingQrVisitor?->visitor?->name ?? 'Visitor']) }}
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
                    <flux:button variant="primary" wire:click="confirmQrCheckOut" icon="check">
                        {{ __('Confirm Check Out') }}
                    </flux:button>
                </div>
            @endif
        </flux:modal>
    @endif

    {{-- ID Scan Modal --}}
    <flux:modal wire:model="showIdScanModal" name="id-scan" class="w-full max-w-2xl">
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
                            <p><span class="font-medium text-neutral-700 dark:text-neutral-300">{{ __('Name') }}:</span> <span class="text-neutral-900 dark:text-white">{{ \App\Models\Visitor::composeName($this->firstname, $this->middlename, $this->lastname) ?: '—' }}</span></p>
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