<?php

namespace App\Notifications;

use App\Models\SocialApprovalLink;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A new approval link, emailed to its approver when the maker asks for it
 * (POST /social-approval-links with notify_approver: true — MCP
 * create_content_plan sends it only after the user confirmed). Otherwise
 * the maker shares the URL by hand, as before. Client-facing, so it
 * carries the approval footer.
 */
class ApprovalLinkShared extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public SocialApprovalLink $link) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $editor = $this->link->user->name;
        $title = $this->link->title ? " “{$this->link->title}”" : '';
        $what = $this->link->isScoped()
            ? 'the posts planned from '.$this->link->scope_from?->format('j M Y').' to '.$this->link->scope_to?->format('j M Y')
            : 'some posts';

        $mail = (new MailMessage)
            ->subject("{$editor} asks you to approve posts")
            ->line("{$editor} has shared{$title} with you: please review {$what}.")
            ->action('Review the posts', $this->link->url())
            ->line('Sign in with this email address to approve, or to ask for a change.');

        return ApprovalMailFooter::on($mail);
    }
}
