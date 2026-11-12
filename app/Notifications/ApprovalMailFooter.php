<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * The footer on every approval email (the "approved" mail and the other
 * approval-flow mails, client-facing ones included): line 1 the slogan at
 * 30px as live text, line 2 a link to the site with the evergreen-footer
 * UTM — the same two lines as the client page's footer (/a in the web
 * app). The header (brand + small slogan) is untouched.
 *
 * Rendered by resources/views/vendor/mail/{html,text}/message.blade.php,
 * which resources/views/vendor/notifications/email.blade.php hands the
 * `approvalFooter` flag to.
 */
final class ApprovalMailFooter
{
    public const URL = 'https://creatif.space/?utm_source=approval-footer&utm_medium=referral&utm_campaign=evergreen-footer';

    public const LINK = 'Approved with Creatif Space →';

    public static function on(MailMessage $mail): MailMessage
    {
        // MailMessage::data() merges this public array into the view data.
        $mail->viewData['approvalFooter'] = true;

        return $mail;
    }

    /**
     * The slogan line alone, without the "Approved with Creatif Space →"
     * link: for the owner's own onboarding emails (welcome, connect nudge),
     * where that link reads wrong and its approval-footer UTM would count a
     * click as a client's.
     */
    public static function sloganOnly(MailMessage $mail): MailMessage
    {
        $mail->viewData['approvalFooter'] = true;
        $mail->viewData['approvalFooterLink'] = false;

        return $mail;
    }
}
