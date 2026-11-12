<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Last month's one-page Insight PDF, attached — the monthly email the owner
 * switched on in Settings → Insight. Mail only, sent by
 * SendMonthlyInsightReport (never queued with the PDF in it: the job builds
 * and sends in one go). Same mailer and layout as every other mail.
 */
class MonthlyInsightReport extends Notification
{
    private const COPY = [
        'en' => [
            'subject' => 'Your Insight report — :period',
            'greeting' => 'Hi :name,',
            'line' => 'Your one-page report for :period is attached: posts, reach, engagement, followers and the posts that did best.',
            'action' => 'Open Insight',
            'off' => 'You get this because the monthly report is on in Settings → Insight. Switch it off there any time.',
        ],
        'id' => [
            'subject' => 'Laporan Insight kamu — :period',
            'greeting' => 'Hai :name,',
            'line' => 'Laporan satu halaman untuk :period ada di lampiran: post, jangkauan, interaksi, pengikut, dan post terbaik.',
            'action' => 'Buka Insight',
            'off' => 'Kamu menerima ini karena laporan bulanan aktif di Pengaturan → Insight. Matikan kapan saja di sana.',
        ],
    ];

    public function __construct(
        public string $pdf,
        public string $fileName,
        public string $periodLabel,
        public string $language = 'en',
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $t = fn (string $key) => str_replace(
            [':period', ':name'],
            [$this->periodLabel, (string) ($notifiable->name ?? '')],
            self::COPY[$this->language][$key] ?? self::COPY['en'][$key],
        );

        return (new MailMessage)
            ->subject($t('subject'))
            ->greeting($t('greeting'))
            ->line($t('line'))
            ->action($t('action'), rtrim((string) config('app.frontend_url'), '/').'/post/analytics')
            ->line($t('off'))
            ->attachData($this->pdf, $this->fileName, ['mime' => 'application/pdf']);
    }
}
