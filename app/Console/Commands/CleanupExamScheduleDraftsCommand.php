<?php

namespace App\Console\Commands;

use App\Enums\ExamOfferingStatus;
use App\Models\ExamScheduleDraft;
use App\Models\SubjectExamOffering;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CleanupExamScheduleDraftsCommand extends Command
{
    protected $signature = 'exam-schedules:cleanup-drafts
        {--dry-run : Show drafts that would be cleaned without deleting them}
        {--stale-hours=2 : Mark generating drafts older than this many hours as stale}';

    protected $description = 'Delete incomplete or failed exam schedule drafts without touching completed or approved drafts.';

    public function handle(): int
    {
        $staleHours = max(1, (int) $this->option('stale-hours'));
        $dryRun = (bool) $this->option('dry-run');
        $drafts = $this->cleanupCandidates($staleHours)->get();

        if ($drafts->isEmpty()) {
            $this->info('No incomplete exam schedule drafts found.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'College', 'Academic year', 'Semester', 'Status', 'Items', 'Created at'],
            $drafts->map(fn (ExamScheduleDraft $draft): array => [
                $draft->id,
                $draft->faculty_id,
                $draft->academic_year_id,
                $draft->semester_id,
                $draft->status,
                $draft->items_count,
                $draft->created_at?->toDateTimeString(),
            ])->all(),
        );

        if ($dryRun) {
            $this->warn("Dry run only. {$drafts->count()} draft(s) would be deleted.");

            return self::SUCCESS;
        }

        $deletedCount = DB::transaction(function () use ($drafts): int {
            return $this->deleteDrafts($drafts);
        });

        Log::info('Incomplete exam schedule drafts cleaned.', [
            'deleted_count' => $deletedCount,
            'draft_ids' => $drafts->pluck('id')->all(),
        ]);

        $this->info("Deleted {$deletedCount} incomplete exam schedule draft(s).");

        return self::SUCCESS;
    }

    protected function cleanupCandidates(int $staleHours): Builder
    {
        return ExamScheduleDraft::query()
            ->withCount('items')
            ->whereNotIn('status', [ExamScheduleDraft::STATUS_COMPLETED, ExamScheduleDraft::STATUS_APPROVED])
            ->where(function (Builder $query) use ($staleHours): void {
                $query
                    ->where('status', ExamScheduleDraft::STATUS_FAILED)
                    ->orWhere('status', ExamScheduleDraft::STATUS_CANCELLED)
                    ->orWhere(function (Builder $query) use ($staleHours): void {
                        $query
                            ->where('status', ExamScheduleDraft::STATUS_GENERATING)
                            ->where('created_at', '<', now()->subHours($staleHours));
                    })
                    ->orWhereDoesntHave('items')
                    ->orWhereHas('items', function (Builder $query): void {
                        $query
                            ->where('status', 'unscheduled')
                            ->orWhereNull('exam_date')
                            ->orWhereNull('start_time');
                    });
            })
            ->orderBy('created_at')
            ->orderBy('id');
    }

    /**
     * @param  Collection<int, ExamScheduleDraft>  $drafts
     */
    protected function deleteDrafts(Collection $drafts): int
    {
        $draftIds = $drafts->pluck('id');

        SubjectExamOffering::query()
            ->whereIn('exam_schedule_draft_id', $draftIds)
            ->where('is_pinned', false)
            ->where('status', ExamOfferingStatus::Draft->value)
            ->delete();

        return ExamScheduleDraft::query()
            ->whereKey($draftIds)
            ->delete();
    }
}
