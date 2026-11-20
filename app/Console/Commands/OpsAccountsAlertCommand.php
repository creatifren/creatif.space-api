<?php

namespace App\Console\Commands;

use App\Models\SocialAccount;
use App\Notifications\OpsAccountsAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * Owner alert (backlog #2, Rizal 20 Nov): mail services.ops.alert_email when
 * the connected social accounts on the publishing relay reach 80 (the
 * Outstand $19 plan holds 100) and again at 900. Each step mails once; when
 * the count falls back under a step, that step can mail again later.
 * Sample and demo channels are not counted.
 */
class OpsAccountsAlertCommand extends Command
{
    /** @var list<int> */
    public const STEPS = [80, 900];

    public const CACHE_KEY = 'ops:accounts-alert:step';

    protected $signature = 'ops:accounts-alert';

    protected $description = 'Mail the owner when connected social accounts reach 80 or 900';

    public function handle(): int
    {
        $count = SocialAccount::query()->usable()->where('is_demo', false)->count();
        $reached = collect(self::STEPS)->filter(fn (int $s) => $count >= $s)->max() ?? 0;
        $sent = (int) Cache::get(self::CACHE_KEY, 0);

        if ($reached > $sent) {
            Notification::route('mail', (string) config('services.ops.alert_email'))
                ->notify(new OpsAccountsAlert($count, $reached));
            $this->info("Sent: {$count} connected accounts (step {$reached}).");
        } else {
            $this->info("{$count} connected accounts; no mail.");
        }

        if ($reached !== $sent) {
            Cache::forever(self::CACHE_KEY, $reached);
        }

        return self::SUCCESS;
    }
}
