<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** v10.8: the owner's bell when a client's monthly report was emailed (no email, no push). */
class ClientReportSent extends Notification
{
    use Queueable;

    public function __construct(public string $reportId, public string $client, public string $period, public string $to) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => NotificationType::ClientReportSent->value,
            'report_id' => $this->reportId,
            'client' => $this->client,
            'period' => $this->period,
            'to' => $this->to,
        ];
    }
}
