<?php

namespace App\Notifications;

use App\Models\Visit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VisitorCheckedOut extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Visit $visit,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $visitor = $this->visit->visitor;

        return (new MailMessage)
            ->subject('Visitor Check-Out: '.$visitor->name)
            ->greeting('Hello '.$notifiable->name.',')
            ->line('A visitor has checked out.')
            ->line('**Name:** '.$visitor->name)
            ->line('**Company:** '.($visitor->company ?: 'N/A'))
            ->line('**Badge:** '.$this->visit->badge_number)
            ->line('**Checked in at:** '.($this->visit->checked_in_at ? $this->visit->checked_in_at->format('M j, Y g:i A') : 'N/A'))
            ->line('**Checked out at:** '.($this->visit->checked_out_at ? $this->visit->checked_out_at->format('M j, Y g:i A') : 'N/A'))
            ->action('View Visits', url('/visits'))
            ->line('Thank you!');
    }

    public function toDatabase(object $notifiable): array
    {
        $visitor = $this->visit->visitor;

        return [
            'visitor_id' => $visitor->id,
            'visitor_name' => $visitor->name,
            'visitor_company' => $visitor->company,
            'badge_number' => $this->visit->badge_number,
            'checked_in_at' => $this->visit->checked_in_at?->toIso8601String(),
            'checked_out_at' => $this->visit->checked_out_at?->toIso8601String(),
            'message' => $visitor->name.' has checked out.',
        ];
    }

    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
