<?php

namespace App\Notifications;

use App\Models\Visit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VisitBooked extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Visit $booking,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $booking = $this->booking->loadMissing('visitor');

        return (new MailMessage)
            ->subject('Booked Visit: '.($booking->visitor?->name ?? 'Visitor'))
            ->greeting('Hello '.$notifiable->name.',')
            ->line('A visit has been booked.')
            ->line('**Name:** '.($booking->visitor?->name ?? 'N/A'))
            ->line('**Company:** '.($booking->visitor?->company ?: 'N/A'))
            ->line('**Visit type:** '.($booking->visit_type ?: 'N/A'))
            ->line('**Purpose:** '.($booking->purpose ?: 'N/A'))
            ->line('**Expected date:** '.($booking->expected_date?->format('M j, Y') ?: 'Not specified'))
            ->action('View Visits', url('/visits'))
            ->line('Thank you!');
    }

    public function toDatabase(object $notifiable): array
    {
        $booking = $this->booking->loadMissing('visitor');

        return [
            'booking_id' => $booking->id,
            'visitor_id' => $booking->visitor_id,
            'visitor_name' => $booking->visitor?->name,
            'visit_type' => $booking->visit_type,
            'purpose' => $booking->purpose,
            'expected_date' => $booking->expected_date?->toDateString(),
            'message' => ($booking->visitor?->name ?? 'A visitor').' has a booked visit.',
        ];
    }

    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
