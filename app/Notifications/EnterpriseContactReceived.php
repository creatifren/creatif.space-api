<?php

namespace App\Notifications;

use App\Models\EnterpriseLead;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To the sales inbox: someone asked about Enterprise. Reply goes to them. */
class EnterpriseContactReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public EnterpriseLead $lead) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $lead = $this->lead;

        return (new MailMessage)
            ->subject('Enterprise enquiry: '.$lead->company)
            ->replyTo($lead->email, $lead->name)
            ->greeting('New Enterprise enquiry')
            ->line('Name: '.$lead->name)
            ->line('Email: '.$lead->email)
            ->line('Company: '.$lead->company)
            ->line('Team size: '.$lead->team_size)
            ->line('Signed in: '.($lead->user_id !== null ? 'yes' : 'no'))
            ->line('Message:')
            ->line($lead->message);
    }
}
