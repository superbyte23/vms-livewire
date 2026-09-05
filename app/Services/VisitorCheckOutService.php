<?php

namespace App\Services;

use App\Models\VisitorLog;
use App\Notifications\VisitorCheckedOut;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class VisitorCheckOutService
{
    /**
     * Mark a visitor log as checked out and notify the host.
     */
    public function checkOut(VisitorLog $visitorLog, ?string $checkoutPhoto = null): VisitorLog
    {
        $visitorLog->update([
            'status' => 'checked_out',
            'checked_out_at' => now(),
            'checkout_photo' => $checkoutPhoto ?: null,
        ]);

        if ($visitorLog->host_user_id && $hostUser = $visitorLog->hostUser) {
            $hostUser->notify(new VisitorCheckedOut($visitorLog));
        }

        return $visitorLog->load('visitor');
    }

    /**
     * Check out the visitor associated with a QR token.
     *
     * @throws ModelNotFoundException when the
     *                                token does not resolve to a visitor currently on-site.
     */
    public function checkOutByToken(string $token, ?string $checkoutPhoto = null): VisitorLog
    {
        $log = (new VisitorCheckInService)->activeLogForToken($token);

        abort_unless($log, 422, __('Invalid QR code or visitor is not on-site.'));

        return $this->checkOut($log, $checkoutPhoto);
    }
}
