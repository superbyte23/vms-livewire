<?php

namespace App\Services;

use App\Models\PreRegistration;
use App\Models\User;
use App\Models\Visitor;
use App\Models\VisitorLog;
use App\Notifications\VisitorCheckedIn;
use App\Notifications\VisitorFlagged;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class VisitorCheckInService
{
    /**
     * Perform a full visitor check-in.
     *
     * @param  array{
     *     visitor_id?: string|null,
     *     pre_registration_id?: string|null,
     *     name?: string|null,
     *     email?: string|null,
     *     phone?: string|null,
     *     company?: string|null,
     *     valid_id_number?: string|null,
     *     photo?: string|null,
     *     host?: string|null,
     *     host_user_id?: string|null,
     *     purpose?: string|null,
     *     visit_photo?: string|null,
     * }  $data
     * @return array{visitor: Visitor, log: VisitorLog, badge_number: string, is_flagged: bool, warnings: array<int, string>}
     */
    public function checkIn(array $data): array
    {
        $visitor = $this->resolveVisitor($data);

        $badgeNumber = $this->generateBadgeNumber();

        $visitorLog = VisitorLog::create([
            'visitor_id' => $visitor->id,
            'host' => $data['host'] ?? null,
            'host_user_id' => $data['host_user_id'] ?? null,
            'purpose' => $data['purpose'] ?? null,
            'photo' => $data['visit_photo'] ?? null,
            'badge_number' => $badgeNumber,
            'status' => 'checked_in',
            'checked_in_at' => now(),
        ])->load('visitor');

        if ($visitorLog->host_user_id && $hostUser = $visitorLog->hostUser) {
            $hostUser->notify(new VisitorCheckedIn($visitorLog));
        }

        $isFlagged = $this->flaggedMatch(
            ! empty($data['visitor_id']) ? $visitor : null,
            $data['name'] ?? '',
        ) !== null;

        if ($isFlagged) {
            $visitor->update(['is_flagged' => true]);

            User::chunk(100, fn ($users) => $users->each->notify(new VisitorFlagged($visitorLog)));
        }

        if (! empty($data['pre_registration_id'])) {
            PreRegistration::whereKey($data['pre_registration_id'])
                ->where('status', 'pending')
                ->update(['status' => 'used']);
        }

        $warnings = [];

        if ($isFlagged) {
            $warnings[] = __('This visitor matches a flagged record. Security has been notified.');
        }

        return [
            'visitor' => $visitor,
            'log' => $visitorLog,
            'badge_number' => $badgeNumber,
            'is_flagged' => $isFlagged,
            'warnings' => $warnings,
        ];
    }

    /**
     * Resolve the visitor to check in: an existing visitor, or a new one
     * created from the submitted identity (optionally backed by a pending
     * pre-registration).
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

        $pre = null;

        if (! empty($data['pre_registration_id'])) {
            $pre = PreRegistration::pending()->find($data['pre_registration_id']);
        }

        return Visitor::create([
            'name' => $data['name'] ?? '',
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'company' => $data['company'] ?? null,
            'valid_id_number' => $data['valid_id_number'] ?? null,
            'photo' => ! empty($data['photo']) ? $data['photo'] : ($pre?->valid_id_photo ?? null),
            'qr_code_token' => $pre?->qr_code_token ?? Str::random(32),
        ]);
    }

    /**
     * The currently checked-in log for a visitor QR token, if any.
     */
    public function activeLogForToken(?string $token): ?VisitorLog
    {
        if (! $token) {
            return null;
        }

        $visitor = Visitor::where('qr_code_token', $token)->first();

        if (! $visitor) {
            return null;
        }

        return VisitorLog::with('visitor')
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
        if (! Schema::hasTable('visitor_logs')) {
            return 'V-'.strtoupper(Str::random(6));
        }

        $count = VisitorLog::whereDate('created_at', today())->count() + 1;

        return 'V-'.now()->format('Ymd').'-'.str_pad($count, 3, '0', STR_PAD_LEFT);
    }
}
