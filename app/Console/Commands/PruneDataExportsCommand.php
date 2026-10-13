<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DataExport;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Delete queued export files, and their data_exports rows, once they are past
 * config('exports.retention_days').
 *
 * What counts as expired:
 *  - completed exports finished before the cutoff (the download link has
 *    already stopped working, see DataExport::isExpired());
 *  - failed exports last touched before the cutoff;
 *  - pending/processing exports last touched before the cutoff. The job
 *    times out after minutes, so a row that old was abandoned (lost worker,
 *    cleared queue). Anything newer is left alone.
 *
 * Each run handles at most --limit rows, in chunks, so a large backlog is
 * worked off over several nights instead of in one long run. A file that is
 * already gone is fine; a file the disk refuses to delete keeps its row so the
 * next run retries it, and the run carries on with the rest.
 */
class PruneDataExportsCommand extends Command
{
    protected $signature = 'exports:prune
        {--limit=1000 : Most export records to prune in one run}
        {--dry-run : Report how many would be pruned without deleting anything}';

    protected $description = 'Delete export files and records older than the export retention window';

    private const CHUNK_SIZE = 100;

    public function handle(): int
    {
        $days = DataExport::retentionDays();

        if ($days < 1) {
            $this->info('Export retention is disabled (exports.retention_days < 1); nothing pruned.');

            return Command::SUCCESS;
        }

        $limit = (int) $this->option('limit');
        if ($limit < 1) {
            $this->error('--limit must be at least 1');

            return Command::FAILURE;
        }

        $cutoff = now()->subDays($days);

        if ($this->option('dry-run')) {
            $count = min($limit, $this->expired($cutoff)->count());
            $this->info("Would prune {$count} exports older than {$cutoff->toDateTimeString()} ({$days} days).");

            return Command::SUCCESS;
        }

        $pruned = 0;
        $failed = 0;
        $seen = 0;

        $this->expired($cutoff)->chunkById(self::CHUNK_SIZE, function ($exports) use (&$pruned, &$failed, &$seen, $limit) {
            foreach ($exports as $export) {
                if ($seen >= $limit) {
                    return false;
                }
                $seen++;

                if ($this->deleteFile($export)) {
                    $export->delete();
                    $pruned++;
                } else {
                    $failed++;
                }
            }

            return $seen < $limit;
        });

        $message = "Pruned {$pruned} exports older than {$cutoff->toDateTimeString()} ({$days} days).";
        if ($failed > 0) {
            $message .= " {$failed} could not be deleted from storage and will be retried next run.";
        }
        $this->info($message);

        return Command::SUCCESS;
    }

    /**
     * Exports past the cutoff, across every organization.
     *
     * @return Builder<DataExport>
     */
    private function expired(Carbon $cutoff): Builder
    {
        return DataExport::withoutGlobalScope(OrganizationScope::class)
            ->where(function (Builder $query) use ($cutoff) {
                $query
                    ->where(function (Builder $q) use ($cutoff) {
                        $q->where('status', 'completed')->where('completed_at', '<', $cutoff);
                    })
                    ->orWhere(function (Builder $q) use ($cutoff) {
                        $q->where('status', 'completed')->whereNull('completed_at')->where('created_at', '<', $cutoff);
                    })
                    ->orWhere(function (Builder $q) use ($cutoff) {
                        $q->where('status', '!=', 'completed')->where('updated_at', '<', $cutoff);
                    });
            });
    }

    /**
     * Remove the export's file. True when it is gone (deleted now, or never
     * written); false when the disk failed, so the row is kept for a retry.
     */
    private function deleteFile(DataExport $export): bool
    {
        if ($export->path === null || $export->path === '') {
            return true;
        }

        try {
            $disk = Storage::disk($export->disk);

            if (! $disk->exists($export->path)) {
                return true;
            }

            if ($disk->delete($export->path)) {
                return true;
            }

            Log::warning('exports:prune could not delete an export file.', [
                'data_export_id' => $export->id,
                'disk' => $export->disk,
                'path' => $export->path,
            ]);
        } catch (Throwable $e) {
            Log::warning('exports:prune could not delete an export file.', [
                'data_export_id' => $export->id,
                'disk' => $export->disk,
                'path' => $export->path,
                'error' => $e->getMessage(),
            ]);
        }

        return false;
    }
}
