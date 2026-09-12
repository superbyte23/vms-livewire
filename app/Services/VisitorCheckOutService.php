<?php

namespace App\Services;

use App\Models\Visit;
use App\Notifications\VisitorCheckedOut;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class VisitorCheckOutService
{
    /**
     * Mark a visitor log as checked out and notify the host.
     */
    public function checkOut(Visit $visit, ?string $checkoutPhoto = null): Visit
    {
        $visit->update([
            'status' => 'checked_out',
            'checked_out_at' => now(),
            'checkout_photo' => $checkoutPhoto ?: null,
        ]);

        if ($visit->host_user_id && $hostUser = $visit->hostUser) {
            $hostUser->notify(new VisitorCheckedOut($visit));
        }

        return $visit->load('visitor');
    }

    /**
     * Check out the visitor associated with a QR token.
     *
     * @throws ModelNotFoundException when the
     *                                token does not resolve to a visitor currently on-site.
     */
    public function checkOutByToken(string $token, ?string $checkoutPhoto = null): Visit
    {
        $visit = (new VisitorCheckInService)->activeVisitForToken($token);

        abort_unless($visit, 422, __('Invalid QR code or visitor is not on-site.'));

        return $this->checkOut($visit, $checkoutPhoto);
    }
}
