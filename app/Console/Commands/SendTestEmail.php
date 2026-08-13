<?php

namespace App\Console\Commands;

use App\Models\Space;
use App\Models\User;
use App\Notifications\SpaceOpened;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Send one real email, on purpose.
 *
 * Mail is the one part of this app whose failure is silent: a wrong key or
 * an unverified domain looks exactly like a quiet Tuesday. This makes the
 * answer loud, and it uses a genuine notification rather than a "hello
 * world" so the from-address, the template and the frontend links are all
 * exercised at once.
 *
 * The first artisan command in the project — everything scheduled is a
 * queued job. This one exists because it is for a person to run by hand.
 */
class SendTestEmail extends Command
{
    protected $signature = 'mail:test {email : Where to send it}';

    protected $description = 'Send one real notification to prove mail is configured';

    public function handle(): int
    {
        $email = $this->argument('email');

        $this->line('Mailer:    '.config('mail.default'));
        $this->line('From:      '.config('mail.from.address'));
        $this->line('To:        '.$email);
        $this->newLine();

        if (config('mail.default') === 'log') {
            $this->warn('MAIL_MAILER is "log" — this will be written to storage/logs, not sent.');
            $this->warn('Set MAIL_MAILER=resend and RESEND_API_KEY to send for real.');
            $this->newLine();
        }

        // A real Space if there is one, otherwise an unsaved stand-in: the
        // point is the delivery, and this must work on an empty database.
        $space = Space::query()->where('status', 'published')->first()
            ?? new Space(['title' => 'Winter Noel — Product Shoot']);

        try {
            // An on-demand route rather than a User: this notification also
            // writes a bell row, and a stand-in user has no id to hang one
            // on. Mail is the only channel under test here anyway.
            //
            // Sent now rather than queued: a test that lands in a queue
            // nobody is running answers the wrong question.
            Notification::route('mail', [$email => 'there'])
                ->notifyNow(new SpaceOpened($space));
        } catch (Throwable $e) {
            $this->error('Failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(config('mail.default') === 'log'
            ? 'Written to storage/logs/laravel.log.'
            : 'Sent. If it does not arrive, check the domain’s SPF and DKIM records.');

        return self::SUCCESS;
    }
}
