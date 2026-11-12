<?php

namespace App\Notifications;

use App\Models\AccountExport;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The account export is ready: a 24-hour signed download link. */
class AccountExportReady extends Notification
{
    public function __construct(public AccountExport $export) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your creatif.space data export is ready')
            ->line('The export you asked for is ready: your account, workspace, channels, posts, approvals with their audit, and a list of your files.')
            ->action('Download the export', $this->export->signedUrl())
            ->line('The link works for '.AccountExport::LINK_TTL_HOURS.' hours. File links inside the export work for '.AccountExport::FILE_URL_TTL_DAYS.' days.')
            ->line('If you did not ask for this, change your password and tell us at hello@creatif.space.');
    }
}
