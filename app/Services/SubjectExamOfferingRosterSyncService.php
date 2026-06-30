<?php

namespace App\Services;

use App\Models\ExamStudent;
use App\Models\SubjectExamOffering;
use App\Models\SubjectExamRoster;
use Illuminate\Support\Collection;

class SubjectExamOfferingRosterSyncService
{
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

        $synced = 0;

        foreach ($this->readyRostersForOffering($offering) as $roster) {
            $students = $roster
                ->eligibleRosterStudents()
                ->orderBy('student_number')
                ->orderBy('full_name')
                ->get();

            foreach ($students as $student) {
                $examStudent = ExamStudent::withTrashed()->firstOrNew(
                    [
                        'subject_exam_offering_id' => $offering->id,
                        'student_number' => $student->student_number,
                    ]);

                $examStudent->fill([
                    'full_name' => $student->full_name,
                    'student_type' => $student->student_type,
                    'notes' => $student->notes,
                ]);

                if ($examStudent->exists && $examStudent->trashed()) {
                    $examStudent->restore();
                } else {
                    $examStudent->save();
                }

                $synced++;
            }
        }

        $offering->unsetRelation('examStudents');
        unset($offering->exam_students_count);

        return $synced;
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
}
