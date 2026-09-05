<?php

namespace App\Http\Controllers\Api\Kiosk;

use App\Http\Controllers\Controller;
use App\Models\PreRegistration;
use App\Models\User;
use App\Models\Visitor;
use App\Models\VisitorLog;
use App\Services\VisitorCheckInService;
use App\Services\VisitorCheckOutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class KioskController extends Controller
{
    public function health(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    public function searchHosts(Request $request): JsonResponse
    {
        $term = $request->string('q')->trim()->toString();

        $query = User::orderBy('name');

        if ($term !== '') {
            $query->where('name', 'like', '%'.$term.'%');
        }

        return response()->json([
            'data' => $query->limit(10)->get(['id', 'name', 'email']),
        ]);
    }

    public function searchVisitors(Request $request): JsonResponse
    {
        $term = $request->string('q')->trim()->toString();

        $visitors = $term === ''
            ? collect()
            : Visitor::search($term)->withCount('logs')->orderBy('name')->limit(8)->get();

        return response()->json([
            'data' => $visitors->map(fn (Visitor $visitor) => $this->visitorArray($visitor))->values(),
        ]);
    }

    public function storeVisitor(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|string|email|max:255',
            'phone' => 'nullable|string|max:255',
            'company' => 'nullable|string|max:255',
            'valid_id_number' => 'nullable|string|max:255',
            'photo' => 'nullable|string',
        ]);

        $visitor = Visitor::create([
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'company' => $data['company'] ?? null,
            'valid_id_number' => $data['valid_id_number'] ?? null,
            'photo' => $data['photo'] ?? null,
            'qr_code_token' => Str::random(32),
        ]);

        return response()->json(['visitor' => $this->visitorArray($visitor)], 201);
    }

    public function showVisitorByToken(string $token): JsonResponse
    {
        $visitor = Visitor::withCount('logs')->where('qr_code_token', $token)->first();

        abort_unless($visitor, 404, 'Invalid QR token.');

        $log = app(VisitorCheckInService::class)->activeLogForToken($token);

        return response()->json([
            'visitor' => $this->visitorArray($visitor),
            'log' => $log ? $this->logArray($log) : null,
        ]);
    }

    public function showPreRegistrationByToken(string $token): JsonResponse
    {
        $pre = PreRegistration::pending()->where('qr_code_token', $token)->first();

        abort_unless($pre, 404, 'Invalid or already used pre-registration QR code.');

        return response()->json(['pre_registration' => $this->preRegistrationArray($pre)]);
    }

    public function pendingPreRegistrations(Request $request): JsonResponse
    {
        $term = $request->string('q')->trim()->toString();

        $query = PreRegistration::pending();

        if ($term !== '') {
            $query->where(function ($builder) use ($term) {
                $builder->where('name', 'like', '%'.$term.'%')
                    ->orWhere('email', 'like', '%'.$term.'%')
                    ->orWhere('phone', 'like', '%'.$term.'%');
            });
        }

        return response()->json([
            'data' => $query->orderByDesc('created_at')->limit(5)->get()
                ->map(fn (PreRegistration $pre) => $this->preRegistrationArray($pre))
                ->values(),
        ]);
    }

    public function onSite(): JsonResponse
    {
        $logs = VisitorLog::with('visitor')
            ->where('status', 'checked_in')
            ->orderByDesc('checked_in_at')
            ->get();

        return response()->json([
            'data' => $logs->map(fn (VisitorLog $log) => $this->logArray($log))->values(),
        ]);
    }

    public function checkIn(Request $request): JsonResponse
    {
        $data = $request->validate([
            'visitor_id' => 'nullable|string|exists:visitors,id',
            'pre_registration_id' => 'nullable|string|exists:pre_registrations,id',
            'name' => 'required_without:visitor_id|string|max:255',
            'email' => 'nullable|string|email|max:255',
            'phone' => 'nullable|string|max:255',
            'company' => 'nullable|string|max:255',
            'valid_id_number' => 'nullable|string|max:255',
            'photo' => 'nullable|string',
            'host' => 'nullable|string|max:255',
            'host_user_id' => 'nullable|integer|exists:users,id',
            'purpose' => 'nullable|string|max:255',
            'visit_photo' => 'nullable|string',
        ]);

        $result = app(VisitorCheckInService::class)->checkIn($data);

        return response()->json([
            'visitor' => $this->visitorArray($result['visitor']),
            'log' => $this->logArray($result['log']),
            'badge_number' => $result['badge_number'],
            'qr_url' => route('qr.code', $result['visitor']->qr_code_token),
            'qr_content' => route('home').'?checkout='.$result['visitor']->qr_code_token,
            'is_flagged' => $result['is_flagged'],
            'warnings' => $result['warnings'],
        ], 201);
    }

    public function checkOut(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => 'required|string',
            'checkout_photo' => 'nullable|string',
        ]);

        $log = app(VisitorCheckOutService::class)->checkOutByToken($data['token'], $data['checkout_photo'] ?? null);

        return response()->json(['log' => $this->logArray($log)]);
    }

    private function visitorArray(Visitor $visitor): array
    {
        $visitsCount = $visitor->relationLoaded('logs')
            ? $visitor->logs->count()
            : ($visitor->getAttribute('logs_count') ?? $visitor->logs()->count());

        return [
            'id' => $visitor->id,
            'name' => $visitor->name,
            'email' => $visitor->email,
            'phone' => $visitor->phone,
            'company' => $visitor->company,
            'valid_id_number' => $visitor->valid_id_number,
            'photo' => $visitor->photo,
            'is_flagged' => $visitor->is_flagged,
            'qr_code_token' => $visitor->qr_code_token,
            'created_at' => $visitor->created_at?->toIso8601String(),
            'visits_count' => $visitsCount,
        ];
    }

    private function preRegistrationArray(PreRegistration $pre): array
    {
        return [
            'id' => $pre->id,
            'name' => $pre->name,
            'email' => $pre->email,
            'phone' => $pre->phone,
            'company' => $pre->company,
            'host' => $pre->host,
            'host_user_id' => $pre->host_user_id,
            'purpose' => $pre->purpose,
            'valid_id_photo' => $pre->valid_id_photo,
            'status' => $pre->status,
        ];
    }

    private function logArray(VisitorLog $log): array
    {
        return [
            'id' => $log->id,
            'visitor_id' => $log->visitor_id,
            'host' => $log->host,
            'host_user_id' => $log->host_user_id,
            'purpose' => $log->purpose,
            'photo' => $log->photo,
            'checkout_photo' => $log->checkout_photo,
            'badge_number' => $log->badge_number,
            'status' => $log->status,
            'checked_in_at' => $log->checked_in_at?->toIso8601String(),
            'checked_out_at' => $log->checked_out_at?->toIso8601String(),
            'visitor' => $log->relationLoaded('visitor') ? $this->visitorArray($log->visitor) : null,
        ];
    }
}
