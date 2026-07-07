<?php

namespace App\Notifications;

use App\Models\VisitorLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VisitorCheckedOut extends Notification implements ShouldQueue
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
            ->subject('Visitor Check-Out: ' . $visitor->name)
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line('A visitor has checked out.')
            ->line('**Name:** ' . $visitor->name)
            ->line('**Company:** ' . ($visitor->company ?: 'N/A'))
            ->line('**Badge:** ' . $this->visitorLog->badge_number)
            ->line('**Checked in at:** ' . ($this->visitorLog->checked_in_at ? $this->visitorLog->checked_in_at->format('M j, Y g:i A') : 'N/A'))
            ->line('**Checked out at:** ' . ($this->visitorLog->checked_out_at ? $this->visitorLog->checked_out_at->format('M j, Y g:i A') : 'N/A'))
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
            'checked_out_at' => $this->visitorLog->checked_out_at?->toIso8601String(),
            'message' => $visitor->name . ' has checked out.',
        ];
    }

    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
