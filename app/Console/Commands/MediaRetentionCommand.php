<?php

namespace App\Console\Commands;

use App\Jobs\ApplyMediaRetention;
use Illuminate\Console\Command;

/**
 * Run Media v2 retention now and print what it did — or, in report mode
 * (the default), what it WOULD delete, per user.
 */
class MediaRetentionCommand extends Command
{
    protected $signature = 'media:retention {--mode= : report|delete (default: MEDIA_RETENTION_MODE, report)}';

    protected $description = 'Media v2 retention: report (default) or delete post media past its rule';

    public function handle(): int
    {
        $mode = $this->option('mode');
        if ($mode !== null && ! in_array($mode, ['report', 'delete'], true)) {
            $this->error('--mode must be report or delete.');

            return self::INVALID;
        }

        $summary = (new ApplyMediaRetention(is_string($mode) ? $mode : null))->handle();
        $verb = $summary['mode'] === 'delete' ? 'Deleted' : 'Would delete';

        $this->info("Mode: {$summary['mode']}");
        $this->table(
            ['User', 'Tier', 'Files', 'MB', 'Rules'],
            collect($summary['users'])->map(fn ($row, $ulid) => [
                $ulid, $row['tier'], $row['files'], round($row['bytes'] / 1024 / 1024, 1),
                collect($row['rules'])->map(fn ($n, $rule) => "{$rule}: {$n}")->implode(', '),
            ])->values()->all(),
        );
        $this->line("{$verb}: {$summary['files']} files, ".round($summary['bytes'] / 1024 / 1024, 1).' MB, across '.count($summary['users']).' users'
            .($summary['failed'] > 0 ? " ({$summary['failed']} skipped or failed)" : '').'.');

        return self::SUCCESS;
    }
}
