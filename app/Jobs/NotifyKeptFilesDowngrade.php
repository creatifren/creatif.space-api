<?php

namespace App\Jobs;

use App\Models\File;
use App\Models\User;
use App\Notifications\KeptFilesGoingSoon;
use App\Support\Media\MediaRetention;
use App\Support\Media\RetentionVerdict;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Daily: owners who dropped to Free with kept files get a warning 7 days
 * and 1 day before the first of them is deleted (media.retention.
 * downgrade_notice_days) — how many files, how many GB, upgrade or
 * download. Dates come from MediaRetention (FREE_KEPT_GRACE), the same
 * calculator as auto_delete_at and the retention job.
 *
 * media.retention.downgrade_notices: "report" (default) only logs who
 * would be emailed; "send" emails, once per owner, notice and date.
 */
class NotifyKeptFilesDowngrade implements ShouldQueue
{
    use Queueable;

    /**
     * @return array{mode: string, notices: list<array{user: string, days_before: int, files: int, bytes: int, due_on: string}>}
     */
    public function handle(): array
    {
        $mode = config('media.retention.downgrade_notices') === 'send' ? 'send' : 'report';
        $days = array_map('intval', (array) config('media.retention.downgrade_notice_days'));
        $now = CarbonImmutable::now();
        $out = [];

        $owners = File::query()->whereNotNull('kept_at')->where('status', File::STATUS_READY)->distinct()->pluck('user_id');

        foreach (User::query()->whereIn('id', $owners)->get() as $owner) {
            $files = File::query()->where('user_id', $owner->id)->whereNotNull('kept_at')->where('status', File::STATUS_READY)->get();
            MediaRetention::flush();
            $verdicts = collect(MediaRetention::prime($files))
                ->filter(fn (RetentionVerdict $v) => $v->rule === RetentionVerdict::FREE_KEPT_GRACE && $v->dueAt !== null);
            if ($verdicts->isEmpty()) {
                continue;
            }

            /** @var CarbonImmutable $first */
            $first = $verdicts->map(fn (RetentionVerdict $v) => $v->dueAt)->sort()->first();
            $going = $verdicts->filter(fn (RetentionVerdict $v) => $v->dueAt->equalTo($first));
            $count = $going->count();
            $bytes = (int) $files->whereIn('id', $going->keys())->sum('size_bytes');

            // The notice whose window we are in: 7 days before up to the
            // next one, 1 day before up to the deletion. Only the nearest.
            $window = collect($days)->sort()->first(fn (int $d) => $now->greaterThanOrEqualTo($first->subDays($d)) && $now->lessThan($first));
            if ($window === null) {
                continue;
            }

            $row = ['user' => $owner->ulid, 'days_before' => $window, 'files' => $count, 'bytes' => $bytes, 'due_on' => $first->toDateString()];

            if ($mode === 'send') {
                $new = DB::table('media_retention_notices')->insertOrIgnore([
                    'user_id' => $owner->id, 'days_before' => $window, 'due_on' => $first->toDateString(),
                    'files' => $count, 'bytes' => $bytes, 'created_at' => now(),
                ]);
                if ($new === 0) {
                    continue; // sent already
                }
                $owner->notify(new KeptFilesGoingSoon($count, $bytes, $first, $window));
            }

            Log::info('media.downgrade_notice', ['mode' => $mode] + $row);
            $out[] = $row;
        }

        MediaRetention::flush();

        return ['mode' => $mode, 'notices' => $out];
    }
}
