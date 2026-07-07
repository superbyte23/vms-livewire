<?php

namespace App\Notifications;

use App\Models\VisitorLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VisitorFlagged extends Notification implements ShouldQueue
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
            ->subject('⚠️ Flagged Visitor Alert: ' . $visitor->name)
            ->greeting('Alert: Watchlist Match')
            ->line('A flagged visitor has checked in.')
            ->line('**Name:** ' . $visitor->name)
            ->line('**Company:** ' . ($visitor->company ?: 'N/A'))
            ->line('**Badge:** ' . $this->visitorLog->badge_number)
            ->line('**Checked in at:** ' . ($this->visitorLog->checked_in_at ? $this->visitorLog->checked_in_at->format('M j, Y g:i A') : 'N/A'))
            ->line('**Notes:** ' . ($visitor->notes ?: 'None'))
            ->action('View Visitor Log', url('/visitors'))
            ->line('Please review and take appropriate action.');
    }

    public function toDatabase(object $notifiable): array
    {
        $visitor = $this->visitorLog->visitor;

        return [
            'visitor_id' => $visitor->id,
            'visitor_name' => $visitor->name,
            'badge_number' => $this->visitorLog->badge_number,
            'checked_in_at' => $this->visitorLog->checked_in_at?->toIso8601String(),
            'notes' => $visitor->notes,
            'message' => '⚠️ Flagged visitor: ' . $visitor->name . ' has checked in.',
        ];
    }

    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
