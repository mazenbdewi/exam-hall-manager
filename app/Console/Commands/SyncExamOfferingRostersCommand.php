<?php

namespace App\Console\Commands;

use App\Models\AcademicYear;
use App\Models\Semester;
use App\Services\SubjectExamOfferingRosterSyncService;
use Illuminate\Console\Command;

class SyncExamOfferingRostersCommand extends Command
{
    protected $signature = 'exam-offerings:sync-rosters
        {--academic-year= : Academic year ID or name}
        {--semester= : Semester ID or name}
        {--offering-id= : Sync one subject exam offering}
        {--subject-id= : Sync offerings for one subject}
        {--chunk=100 : Number of offerings to process per chunk}';

    protected $description = 'Sync ready subject exam rosters to matching subject exam offerings.';

    public function handle(SubjectExamOfferingRosterSyncService $syncService): int
    {
        $academicYearId = $this->resolveAcademicYearId($this->option('academic-year'));
        $semesterId = $this->resolveSemesterId($this->option('semester'));

        if (filled($this->option('academic-year')) && ! $academicYearId) {
            $this->error('Academic year was not found.');

            return self::FAILURE;
        }

        if (filled($this->option('semester')) && ! $semesterId) {
            $this->error('Semester was not found.');

            return self::FAILURE;
        }

        $filters = [
            'offering_id' => $this->option('offering-id') ?: null,
            'subject_id' => $this->option('subject-id') ?: null,
            'academic_year_id' => $academicYearId,
            'semester_id' => $semesterId,
        ];
        $filters = array_filter($filters, fn (mixed $value): bool => filled($value));

        $summary = $syncService->syncOfferings(
            filters: $filters,
            chunkSize: max(1, (int) $this->option('chunk')),
        );

        $this->table(
            ['Offerings scanned', 'Rosters matched', 'Students synced', 'No ready roster', 'Errors'],
            [[
                $summary['offerings_scanned'],
                $summary['rosters_matched'],
                $summary['students_synced'],
                $summary['offerings_without_ready_roster'],
                $summary['errors_count'],
            ]],
        );

        foreach (array_slice($summary['errors'], 0, 5) as $error) {
            $this->error($error);
        }

        return $summary['errors_count'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    protected function resolveAcademicYearId(mixed $value): ?int
    {
        if (blank($value)) {
            return null;
        }

        return AcademicYear::query()
            ->whereKey($value)
            ->orWhere('name', (string) $value)
            ->value('id');
    }

    protected function resolveSemesterId(mixed $value): ?int
    {
        if (blank($value)) {
            return null;
        }

        return Semester::query()
            ->whereKey($value)
            ->orWhere('name', (string) $value)
            ->value('id');
    }
}
