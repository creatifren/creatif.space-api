<?php

namespace App\Notifications;

use App\Models\Feedback;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To the team inbox: a tester sent feedback. Reply goes to them. */
class FeedbackReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Feedback $feedback, public User $user) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $f = $this->feedback;
        $tag = $f->internal ? '[internal] ' : '';

        $mail = (new MailMessage)
            ->subject($tag.'Feedback ('.$f->kind.'): '.str($f->message)->squish()->limit(60))
            ->replyTo($this->user->email, $this->user->name)
            ->greeting('New '.$f->kind.' feedback')
            ->line('From: '.$this->user->name.' <'.$this->user->email.'>'.($f->internal ? ' (internal account)' : ''))
            ->line('Sent from: '.($f->source === 'mcp' ? 'an AI chat (MCP)' : 'the app'));
        if ($f->page !== null && $f->page !== '') {
            $mail->line('Page: '.$f->page);
        }

        return $mail->line('Message:')->line($f->message)->line('Feedback id: '.$f->ulid);
    }
}
