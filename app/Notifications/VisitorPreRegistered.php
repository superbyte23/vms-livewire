<?php

namespace App\Notifications;

use App\Models\PreRegistration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VisitorPreRegistered extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public PreRegistration $preRegistration,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New Pre-Registered Visit: '.$this->preRegistration->name)
            ->greeting('Hello '.$notifiable->name.',')
            ->line('A visitor has pre-registered for a visit.')
            ->line('**Name:** '.$this->preRegistration->name)
            ->line('**Company:** '.($this->preRegistration->company ?: 'N/A'))
            ->line('**Email:** '.($this->preRegistration->email ?: 'N/A'))
            ->line('**Phone:** '.($this->preRegistration->phone ?: 'N/A'))
            ->line('**Purpose:** '.($this->preRegistration->purpose ?: 'N/A'))
            ->line('**Expected date:** '.($this->preRegistration->expected_date?->format('M j, Y') ?: 'Not specified'))
            ->action('View Pre-Registrations', url('/pre-registrations'))
            ->line('Thank you!');
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'pre_registration_id' => $this->preRegistration->id,
            'visitor_name' => $this->preRegistration->name,
            'visitor_company' => $this->preRegistration->company,
            'purpose' => $this->preRegistration->purpose,
            'expected_date' => $this->preRegistration->expected_date?->toDateString(),
            'message' => $this->preRegistration->name.' has pre-registered for a visit.',
        ];
    }

    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
