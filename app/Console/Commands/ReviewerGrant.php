<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\DirectoryReviewer;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Makes an EXISTING account a directory reviewer (or stops it being one).
 * The account itself is created by staff the usual way; this only sets the
 * flag and the password the reviewer signs in with at /auth/reviewer/login.
 *
 *   php artisan reviewer:grant reviewer@example.com            # generates a password, printed once
 *   php artisan reviewer:grant reviewer@example.com --password # asks for it (hidden)
 *   php artisan reviewer:grant reviewer@example.com --revoke
 *
 * The password does not expire. Granting marks the address verified (staff
 * vouch for it). Revoking clears the flag and the password.
 */
class ReviewerGrant extends Command
{
    protected $signature = 'reviewer:grant
        {email : The existing account\'s email address}
        {--password : Ask for the password instead of generating one}
        {--revoke : Remove the reviewer flag and the password}';

    protected $description = 'Set (or revoke) the directory-reviewer flag and password on an existing account';

    public const MIN_LENGTH = DirectoryReviewer::MIN_PASSWORD;

    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("No account with the email {$email}. Create it first; this command only sets the flag.");

            return self::FAILURE;
        }

        if ($this->option('revoke')) {
            DirectoryReviewer::revoke($user, $email);
            $this->info("{$email} is no longer a directory reviewer; its password is cleared.");

            return self::SUCCESS;
        }

        $generated = ! $this->option('password');
        $password = $generated ? Str::password(24, symbols: false) : (string) $this->secret('Password (at least '.self::MIN_LENGTH.' characters)');

        if (mb_strlen($password) < self::MIN_LENGTH || mb_strlen($password) > DirectoryReviewer::MAX_PASSWORD) {
            $this->error('The password must be '.self::MIN_LENGTH.' to 72 characters.');

            return self::FAILURE;
        }

        DirectoryReviewer::grant($user, $email, $password);

        $this->info("{$email} is a directory reviewer. Sign in at POST /auth/reviewer/login.");
        if ($generated) {
            $this->line("Password (shown once): {$password}");
        }

        return self::SUCCESS;
    }
}
