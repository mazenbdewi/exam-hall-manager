<?php

namespace App\Services;

use App\Models\SubjectExamOffering;
use App\Models\SubjectExamRoster;
use App\Models\SubjectExamRosterStudent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class SubjectExamOfferingRosterSyncService
{
    protected const DEFAULT_CHUNK_SIZE = 100;
    protected const STUDENT_UPSERT_CHUNK_SIZE = 1000;

    /**
     * @param  Collection<int, SubjectExamOffering>  $offerings
     */
    public function syncMissingExamStudentsFromReadyRosters(Collection $offerings): void
    {
        foreach ($offerings as $offering) {
            $examStudentsCount = (int) ($offering->exam_students_count ?? $offering->examStudents()->count());

            if ($examStudentsCount > 0) {
                continue;
            }

            $this->syncOffering($offering);
        }
    }

    public function syncOffering(SubjectExamOffering $offering, bool $replaceExisting = false): int
    {
        if ($replaceExisting) {
            $offering->examStudents()->withTrashed()->forceDelete();
        }

        $summary = $this->syncOfferings([
            'offering_id' => $offering->getKey(),
        ]);

        $offering->unsetRelation('examStudents');
        unset($offering->exam_students_count);

        return $summary['students_synced'];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{offerings_scanned:int, rosters_matched:int, students_synced:int, offerings_without_ready_roster:int, errors_count:int, errors:array<int, string>}
     */
    public function syncOfferings(array $filters = [], int $chunkSize = self::DEFAULT_CHUNK_SIZE): array
    {
        $summary = [
            'offerings_scanned' => 0,
            'rosters_matched' => 0,
            'students_synced' => 0,
            'offerings_without_ready_roster' => 0,
            'errors_count' => 0,
            'errors' => [],
        ];
        $matchedRosterIds = [];

        $this->offeringsQuery($filters)
            ->with(['subject:id,college_id,department_id'])
            ->orderBy('id')
            ->chunkById(max(1, $chunkSize), function (Collection $offerings) use (&$summary, &$matchedRosterIds): void {
                $summary['offerings_scanned'] += $offerings->count();

                try {
                    $chunkSummary = $this->syncOfferingsChunk($offerings);

                    $summary['students_synced'] += $chunkSummary['students_synced'];
                    $summary['offerings_without_ready_roster'] += $chunkSummary['offerings_without_ready_roster'];

                    foreach ($chunkSummary['matched_roster_ids'] as $rosterId) {
                        $matchedRosterIds[$rosterId] = true;
                    }
                } catch (Throwable $exception) {
                    $summary['errors_count']++;
                    $summary['errors'][] = $exception->getMessage();
                }
            });

        $summary['rosters_matched'] = count($matchedRosterIds);

        return $summary;
    }

    /**
     * @return Builder<SubjectExamOffering>
     */
    public function offeringsQuery(array $filters = []): Builder
    {
        return SubjectExamOffering::query()
            ->when($filters['offering_id'] ?? null, fn (Builder $query, int|string $offeringId): Builder => $query->whereKey($offeringId))
            ->when($filters['subject_id'] ?? null, fn (Builder $query, int|string $subjectId): Builder => $query->where('subject_id', $subjectId))
            ->when($filters['academic_year_id'] ?? null, fn (Builder $query, int|string $academicYearId): Builder => $query->where('academic_year_id', $academicYearId))
            ->when($filters['semester_id'] ?? null, fn (Builder $query, int|string $semesterId): Builder => $query->where('semester_id', $semesterId))
            ->when($filters['college_id'] ?? null, fn (Builder $query, int|string $collegeId): Builder => $query
                ->whereHas('subject', fn (Builder $subjectQuery): Builder => $subjectQuery->where('college_id', $collegeId)));
    }

    /**
     * @param  Collection<int, SubjectExamOffering>  $offerings
     * @return array{students_synced:int, offerings_without_ready_roster:int, matched_roster_ids:array<int, int>}
     */
    protected function syncOfferingsChunk(Collection $offerings): array
    {
        $summary = [
            'students_synced' => 0,
            'offerings_without_ready_roster' => 0,
            'matched_roster_ids' => [],
        ];

        if ($offerings->isEmpty()) {
            return $summary;
        }

        $rosters = $this->readyRostersForOfferings($offerings);
        $rostersByKey = $rosters->groupBy(fn (SubjectExamRoster $roster): string => $this->rosterMatchKey(
            subjectId: $roster->subject_id,
            collegeId: $roster->college_id,
            departmentId: $roster->department_id,
            academicYearId: $roster->academic_year_id,
            semesterId: $roster->semester_id,
        ));
        $offeringIdsByRosterId = [];

        foreach ($offerings as $offering) {
            $matchingRosters = $rostersByKey->get($this->offeringMatchKey($offering), collect());

            if ($matchingRosters->isEmpty()) {
                $summary['offerings_without_ready_roster']++;

                continue;
            }

            foreach ($matchingRosters as $roster) {
                $summary['matched_roster_ids'][(int) $roster->id] = (int) $roster->id;
                $offeringIdsByRosterId[(int) $roster->id][] = (int) $offering->id;
            }
        }

        if ($offeringIdsByRosterId === []) {
            return $summary;
        }

        $summary['students_synced'] = $this->syncRosterStudentsToOfferings($offeringIdsByRosterId);

        return $summary;
    }

    public function matchingReadyRosterForOffering(SubjectExamOffering $offering): ?SubjectExamRoster
    {
        return $this->readyRostersForOffering($offering)->first();
    }

    /**
     * @return Collection<int, SubjectExamRoster>
     */
    public function readyRostersForOffering(SubjectExamOffering $offering): Collection
    {
        $offering->loadMissing('subject');

        return SubjectExamRoster::query()
            ->with(['college', 'department', 'subject'])
            ->withCount([
                'rosterStudents as roster_students_count_raw',
                'eligibleRosterStudents as eligible_students_count',
            ])
            ->where('college_id', $offering->subject?->college_id)
            ->where('subject_id', $offering->subject_id)
            ->where('academic_year_id', $offering->academic_year_id)
            ->where('semester_id', $offering->semester_id)
            ->where('status', 'ready')
            ->when($offering->subject?->department_id, fn ($query, int $departmentId) => $query->where('department_id', $departmentId))
            ->latest('id')
            ->get();
    }

    /**
     * @param  Collection<int, SubjectExamOffering>  $offerings
     * @return Collection<int, SubjectExamRoster>
     */
    protected function readyRostersForOfferings(Collection $offerings): Collection
    {
        $subjects = $offerings
            ->pluck('subject')
            ->filter()
            ->keyBy('id');

        return SubjectExamRoster::query()
            ->where('status', 'ready')
            ->whereIn('subject_id', $offerings->pluck('subject_id')->filter()->unique()->values())
            ->whereIn('academic_year_id', $offerings->pluck('academic_year_id')->filter()->unique()->values())
            ->whereIn('semester_id', $offerings->pluck('semester_id')->filter()->unique()->values())
            ->whereIn('college_id', $subjects->pluck('college_id')->filter()->unique()->values())
            ->whereIn('department_id', $subjects->pluck('department_id')->filter()->unique()->values())
            ->get(['id', 'college_id', 'department_id', 'subject_id', 'academic_year_id', 'semester_id']);
    }

    /**
     * @param  array<int, array<int, int>>  $offeringIdsByRosterId
     */
    protected function syncRosterStudentsToOfferings(array $offeringIdsByRosterId): int
    {
        $synced = 0;
        $rosterIds = array_keys($offeringIdsByRosterId);

        SubjectExamRosterStudent::query()
            ->whereIn('subject_exam_roster_id', $rosterIds)
            ->where('is_eligible', true)
            ->orderBy('id')
            ->chunkById(self::STUDENT_UPSERT_CHUNK_SIZE, function (Collection $students) use ($offeringIdsByRosterId, &$synced): void {
                $rowsByKey = [];
                $now = now();

                foreach ($students as $student) {
                    foreach ($offeringIdsByRosterId[(int) $student->subject_exam_roster_id] ?? [] as $offeringId) {
                        $rowsByKey[$offeringId.'|'.$student->student_number] = [
                            'subject_exam_offering_id' => $offeringId,
                            'student_number' => $student->student_number,
                            'full_name' => $student->full_name,
                            'student_type' => $student->student_type,
                            'notes' => $student->notes,
                            'deleted_at' => null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }

                if ($rowsByKey === []) {
                    return;
                }

                DB::table('exam_students')->upsert(
                    array_values($rowsByKey),
                    ['subject_exam_offering_id', 'student_number'],
                    ['full_name', 'student_type', 'notes', 'deleted_at', 'updated_at'],
                );

                $synced += count($rowsByKey);
            });

        return $synced;
    }

    protected function offeringMatchKey(SubjectExamOffering $offering): string
    {
        $offering->loadMissing('subject');

        return $this->rosterMatchKey(
            subjectId: $offering->subject_id,
            collegeId: $offering->subject?->college_id,
            departmentId: $offering->subject?->department_id,
            academicYearId: $offering->academic_year_id,
            semesterId: $offering->semester_id,
        );
    }

    protected function rosterMatchKey(mixed $subjectId, mixed $collegeId, mixed $departmentId, mixed $academicYearId, mixed $semesterId): string
    {
        return implode('|', [
            (string) $subjectId,
            (string) $collegeId,
            (string) $departmentId,
            (string) $academicYearId,
            (string) $semesterId,
        ]);
    }
}
