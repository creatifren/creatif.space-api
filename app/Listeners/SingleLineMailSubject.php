<?php

namespace App\Listeners;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Str;

/**
 * Every outgoing subject on one line. Subjects are built from user text —
 * captions, space titles, client and team names — and a line break in one
 * makes Resend refuse the whole email ("\n is not allowed in the subject"),
 * so the notification is lost. Collapsed and capped here, for every mail.
 */
class SingleLineMailSubject
{
    /** Long enough for any subject we write; short enough for an inbox. */
    public const MAX = 150;

    public function handle(MessageSending $event): void
    {
        $subject = $event->message->getSubject();

        if ($subject === null) {
            return;
        }

        $clean = self::clean($subject);

        if ($clean !== $subject) {
            $event->message->subject($clean);
        }
    }

    public static function clean(string $subject): string
    {
        return Str::of($subject)->squish()->limit(self::MAX)->toString();
    }
}
