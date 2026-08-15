<?php

namespace App\Notifications;

use App\Models\VisitorLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VisitorCheckedIn extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public VisitorLog $visitorLog,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $visitor = $this->visitorLog->visitor;

        return (new MailMessage)
            ->subject('Visitor Check-In: '.$visitor->name)
            ->greeting('Hello '.$notifiable->name.',')
            ->line('A visitor has checked in to see you.')
            ->line('**Name:** '.$visitor->name)
            ->line('**Company:** '.($visitor->company ?: 'N/A'))
            ->line('**Email:** '.($visitor->email ?: 'N/A'))
            ->line('**Phone:** '.($visitor->phone ?: 'N/A'))
            ->line('**Purpose:** '.($this->visitorLog->purpose ?: 'N/A'))
            ->line('**Badge:** '.$this->visitorLog->badge_number)
            ->line('**Checked in at:** '.($this->visitorLog->checked_in_at ? $this->visitorLog->checked_in_at->format('M j, Y g:i A') : 'N/A'))
            ->action('View Visitor Log', url('/visitors'))
            ->line('Thank you!');
    }

    public function toDatabase(object $notifiable): array
    {
        $visitor = $this->visitorLog->visitor;

        return [
            'visitor_id' => $visitor->id,
            'visitor_name' => $visitor->name,
            'visitor_company' => $visitor->company,
            'badge_number' => $this->visitorLog->badge_number,
            'checked_in_at' => $this->visitorLog->checked_in_at?->toIso8601String(),
            'message' => $visitor->name.' has checked in to see you.',
        ];
    }

    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
