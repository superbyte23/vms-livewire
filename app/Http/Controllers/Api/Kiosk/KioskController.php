<?php

namespace App\Http\Controllers\Api\Kiosk;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Visit;
use App\Models\Visitor;
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
            : Visitor::search($term)->withCount('visits')->orderBy('name')->limit(8)->get();

        return response()->json([
            'data' => $visitors->map(fn (Visitor $visitor) => $this->visitorArray($visitor))->values(),
        ]);
    }

    public function storeVisitor(Request $request): JsonResponse
    {
        $data = $request->validate([
            'firstname' => 'required|string|max:255',
            'middlename' => 'nullable|string|max:255',
            'lastname' => 'required|string|max:255',
            'email' => 'nullable|string|email|max:255',
            'phone' => 'nullable|string|max:255',
            'company' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'government_id' => 'nullable|string|max:255',
            'government_id_photo' => 'nullable|string',
            'photo' => 'nullable|string',
        ]);

        $visitor = Visitor::create([
            'firstname' => $data['firstname'],
            'middlename' => $data['middlename'] ?? null,
            'lastname' => $data['lastname'],
            'name' => Visitor::composeName($data['firstname'], $data['middlename'] ?? null, $data['lastname']),
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'company' => $data['company'] ?? null,
            'address' => $data['address'] ?? null,
            'government_id' => $data['government_id'] ?? null,
            'government_id_photo' => $data['government_id_photo'] ?? null,
            'photo' => $data['photo'] ?? null,
            'qr_code_token' => Str::random(32),
        ]);

        return response()->json(['visitor' => $this->visitorArray($visitor)], 201);
    }

    public function showVisitorByToken(string $token): JsonResponse
    {
        $visitor = Visitor::withCount('visits')->where('qr_code_token', $token)->first();

        abort_unless($visitor, 404, 'Invalid QR token.');

        $visit = app(VisitorCheckInService::class)->activeVisitForToken($token);

        return response()->json([
            'visitor' => $this->visitorArray($visitor),
            'visit' => $visit ? $this->visitArray($visit) : null,
        ]);
    }

    public function showBookingByToken(string $token): JsonResponse
    {
        $booking = Visit::scheduled()->with('visitor')->where('qr_code_token', $token)->first();

        abort_unless($booking, 404, 'Invalid or already used booking QR code.');

        return response()->json(['booking' => $this->bookingArray($booking)]);
    }

    public function pendingBookings(Request $request): JsonResponse
    {
        $term = $request->string('q')->trim()->toString();

        $query = Visit::scheduled()->with('visitor');

        if ($term !== '') {
            $query->whereHas('visitor', function ($builder) use ($term) {
                $builder->where('name', 'like', '%'.$term.'%')
                    ->orWhere('email', 'like', '%'.$term.'%')
                    ->orWhere('phone', 'like', '%'.$term.'%');
            });
        }

        return response()->json([
            'data' => $query->orderByDesc('created_at')->limit(5)->get()
                ->map(fn (Visit $booking) => $this->bookingArray($booking))
                ->values(),
        ]);
    }

    public function onSite(): JsonResponse
    {
        $visits = Visit::with('visitor')
            ->where('status', 'checked_in')
            ->orderByDesc('checked_in_at')
            ->get();

        return response()->json([
            'data' => $visits->map(fn (Visit $visit) => $this->visitArray($visit))->values(),
        ]);
    }

    public function checkIn(Request $request): JsonResponse
    {
        $data = $request->validate([
            'visitor_id' => 'nullable|string|exists:visitors,id',
            'booking_id' => 'nullable|string|exists:visits,id',
            'name' => 'required_without_all:visitor_id,firstname|string|max:255',
            'firstname' => 'required_without_all:visitor_id,name|string|max:255',
            'middlename' => 'nullable|string|max:255',
            'lastname' => 'required_without_all:visitor_id,name|string|max:255',
            'email' => 'nullable|string|email|max:255',
            'phone' => 'nullable|string|max:255',
            'company' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'government_id' => 'nullable|string|max:255',
            'government_id_photo' => 'nullable|string',
            'photo' => 'nullable|string',
            'host' => 'nullable|string|max:255',
            'host_user_id' => 'nullable|integer|exists:users,id',
            'purpose' => 'nullable|string|max:255',
            'visit_photo' => 'nullable|string',
        ]);

        $result = app(VisitorCheckInService::class)->checkIn($data);

        return response()->json([
            'visitor' => $this->visitorArray($result['visitor']),
            'visit' => $this->visitArray($result['visit']),
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

        $visit = app(VisitorCheckOutService::class)->checkOutByToken($data['token'], $data['checkout_photo'] ?? null);

        return response()->json(['visit' => $this->visitArray($visit)]);
    }

    private function visitorArray(Visitor $visitor): array
    {
        $visitsCount = $visitor->relationLoaded('visits')
            ? $visitor->visits->count()
            : ($visitor->getAttribute('visits_count') ?? $visitor->visits()->count());

        return [
            'id' => $visitor->id,
            'name' => $visitor->name,
            'firstname' => $visitor->firstname,
            'middlename' => $visitor->middlename,
            'lastname' => $visitor->lastname,
            'email' => $visitor->email,
            'phone' => $visitor->phone,
            'company' => $visitor->company,
            'address' => $visitor->address,
            'government_id' => $visitor->government_id,
            'government_id_photo' => $visitor->government_id_photo,
            'photo' => $visitor->photo,
            'is_flagged' => $visitor->is_flagged,
            'qr_code_token' => $visitor->qr_code_token,
            'created_at' => $visitor->created_at?->toIso8601String(),
            'visits_count' => $visitsCount,
        ];
    }

    private function bookingArray(Visit $booking): array
    {
        $booking->loadMissing('visitor');

        return [
            'id' => $booking->id,
            'visitor_id' => $booking->visitor_id,
            'visitor' => $booking->visitor ? $this->visitorArray($booking->visitor) : null,
            'host' => $booking->host,
            'host_user_id' => $booking->host_user_id,
            'purpose' => $booking->purpose,
            'visit_type' => $booking->visit_type,
            'expected_date' => $booking->expected_date?->toDateString(),
            'status' => $booking->status,
        ];
    }

    private function visitArray(Visit $visit): array
    {
        return [
            'id' => $visit->id,
            'visitor_id' => $visit->visitor_id,
            'host' => $visit->host,
            'host_user_id' => $visit->host_user_id,
            'purpose' => $visit->purpose,
            'visit_type' => $visit->visit_type,
            'expected_date' => $visit->expected_date?->toDateString(),
            'photo' => $visit->photo,
            'checkout_photo' => $visit->checkout_photo,
            'badge_number' => $visit->badge_number,
            'status' => $visit->status,
            'checked_in_at' => $visit->checked_in_at?->toIso8601String(),
            'checked_out_at' => $visit->checked_out_at?->toIso8601String(),
            'visitor' => $visit->relationLoaded('visitor') ? $this->visitorArray($visit->visitor) : null,
        ];
    }
}
