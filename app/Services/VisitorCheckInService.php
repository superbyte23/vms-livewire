<?php

namespace App\Services;

use App\Models\User;
use App\Models\Visit;
use App\Models\Visitor;
use App\Notifications\VisitorCheckedIn;
use App\Notifications\VisitorFlagged;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class VisitorCheckInService
{
    /**
     * Perform a full visitor check-in.
     *
     * With a booking_id, the pre-booked visits row (status scheduled)
     * is flipped to checked_in — no duplicate log is created.
     *
     * @param  array{
     *     visitor_id?: string|null,
     *     booking_id?: string|null,
     *     name?: string|null,
     *     firstname?: string|null,
     *     middlename?: string|null,
     *     lastname?: string|null,
     *     email?: string|null,
     *     phone?: string|null,
     *     company?: string|null,
     *     address?: string|null,
     *     government_id?: string|null,
     *     government_id_photo?: string|null,
     *     photo?: string|null,
     *     host?: string|null,
     *     host_user_id?: string|null,
     *     purpose?: string|null,
     *     visit_type?: string|null,
     *     visit_photo?: string|null,
     * }  $data
     * @return array{visitor: Visitor, visit: Visit, badge_number: string, is_flagged: bool, warnings: array<int, string>}
     */
    public function checkIn(array $data): array
    {
        $visitor = $this->resolveVisitor($data);

        $badgeNumber = $this->generateBadgeNumber();

        if (! empty($data['booking_id'])) {
            $booking = Visit::scheduled()->findOrFail($data['booking_id']);

            $booking->update([
                'host' => $data['host'] ?? $booking->host,
                'host_user_id' => $data['host_user_id'] ?? $booking->host_user_id,
                'purpose' => $data['purpose'] ?? $booking->purpose,
                'visit_type' => $data['visit_type'] ?? $booking->visit_type,
                'photo' => $data['visit_photo'] ?? $booking->photo,
                'badge_number' => $badgeNumber,
                'status' => 'checked_in',
                'checked_in_at' => now(),
            ]);

            $visit = $booking->load('visitor');
        } else {
            $visit = Visit::create([
                'visitor_id' => $visitor->id,
                'host' => $data['host'] ?? null,
                'host_user_id' => $data['host_user_id'] ?? null,
                'purpose' => $data['purpose'] ?? null,
                'visit_type' => $data['visit_type'] ?? null,
                'photo' => $data['visit_photo'] ?? null,
                'badge_number' => $badgeNumber,
                'status' => 'checked_in',
                'checked_in_at' => now(),
            ])->load('visitor');
        }

        if ($visit->host_user_id && $hostUser = $visit->hostUser) {
            $hostUser->notify(new VisitorCheckedIn($visit));
        }

        $isFlagged = $this->flaggedMatch(
            ! empty($data['visitor_id']) || ! empty($data['booking_id']) ? $visitor : null,
            $data['name'] ?? '',
        ) !== null;

        if ($isFlagged) {
            $visitor->update(['is_flagged' => true]);

            User::chunk(100, fn ($users) => $users->each->notify(new VisitorFlagged($visit)));
        }

        $warnings = [];

        if ($isFlagged) {
            $warnings[] = __('This visitor matches a flagged record. Security has been notified.');
        }

        return [
            'visitor' => $visitor,
            'visit' => $visit,
            'badge_number' => $badgeNumber,
            'is_flagged' => $isFlagged,
            'warnings' => $warnings,
        ];
    }

    /**
     * Resolve the visitor to check in: an existing visitor, the visitor
     * linked to a booking, or a new one created from the submitted identity.
     *
     * @param  array<string, mixed>  $data
     */
    public function resolveVisitor(array $data): Visitor
    {
        if (! empty($data['visitor_id'])) {
            $visitor = Visitor::findOrFail($data['visitor_id']);

            if (! $visitor->qr_code_token) {
                $visitor->update(['qr_code_token' => Str::random(32)]);
            }

            return $visitor;
        }

        if (! empty($data['booking_id'])) {
            $booking = Visit::scheduled()->with('visitor')->find($data['booking_id']);

            if ($booking?->visitor) {
                return $booking->visitor;
            }
        }

        $composed = Visitor::composeName(
            $data['firstname'] ?? null,
            $data['middlename'] ?? null,
            $data['lastname'] ?? null,
        );

        return Visitor::create([
            'firstname' => $data['firstname'] ?? null,
            'middlename' => $data['middlename'] ?? null,
            'lastname' => $data['lastname'] ?? null,
            'name' => $composed !== '' ? $composed : ($data['name'] ?? ''),
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'company' => $data['company'] ?? null,
            'address' => $data['address'] ?? null,
            'government_id' => $data['government_id'] ?? null,
            'government_id_photo' => $data['government_id_photo'] ?? null,
            'photo' => $data['photo'] ?? null,
            'qr_code_token' => Str::random(32),
        ]);
    }

    /**
     * The currently checked-in log for a visitor QR token, if any.
     */
    public function activeVisitForToken(?string $token): ?Visit
    {
        if (! $token) {
            return null;
        }

        $visitor = Visitor::where('qr_code_token', $token)->first();

        if (! $visitor) {
            return null;
        }

        return Visit::with('visitor')
            ->where('visitor_id', $visitor->id)
            ->where('status', 'checked_in')
            ->latest('checked_in_at')
            ->first();
    }

    /**
     * Find a flagged visitor sharing the resolved name. Mirrors the kiosk
     * wizard's warning, using the selected visitor's name when present.
     */
    public function flaggedMatch(?Visitor $selectedVisitor, string $name): ?Visitor
    {
        $name = $selectedVisitor?->name ?? $name;

        if ($name === '' || ! Schema::hasTable('visitors')) {
            return null;
        }

        return Visitor::flagged()
            ->where('name', $name)
            ->first();
    }

    public function generateBadgeNumber(): string
    {
        if (! Schema::hasTable('visits')) {
            return 'V-'.strtoupper(Str::random(6));
        }

        $count = Visit::whereDate('created_at', today())->count() + 1;

        return 'V-'.now()->format('Ymd').'-'.str_pad($count, 3, '0', STR_PAD_LEFT);
    }
}
