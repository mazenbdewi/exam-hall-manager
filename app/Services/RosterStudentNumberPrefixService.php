<?php

namespace App\Services;

use App\Models\Department;
use App\Models\SubjectExamRoster;
use App\Models\SubjectExamRosterStudent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RosterStudentNumberPrefixService
{
    /**
     * @return array<string, mixed>
     */
    public function previewPrefixing(SubjectExamRoster $roster, int $limit = 10): array
    {
        $prefix = $this->prefixForRoster($roster);
        $students = $this->rosterStudents($roster);
        $previewRows = $students
            ->take($limit)
            ->map(fn (SubjectExamRosterStudent $student): array => [
                'student_id' => $student->id,
                'name' => $student->full_name,
                'old_number' => $student->student_number,
                'new_number' => $this->targetPrefixedNumber($student, $prefix),
            ])
            ->values()
            ->all();

        return [
            'prefix' => $prefix,
            'students_count' => $students->count(),
            'updatable_students_count' => $this->countPrefixUpdates($students, $prefix),
            'preview_rows' => $previewRows,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function applyPrefixing(SubjectExamRoster $roster): array
    {
        $prefix = $this->prefixForRoster($roster);

        return DB::transaction(function () use ($roster, $prefix): array {
            $students = $this->rosterStudents($roster, lockForUpdate: true);
            $this->ensureUniqueTargetNumbers($students, fn (SubjectExamRosterStudent $student): string => $this->targetPrefixedNumber($student, $prefix));

            $updated = 0;

            foreach ($students as $student) {
                $originalStudentNumber = filled($student->original_student_number)
                    ? (string) $student->original_student_number
                    : (string) $student->student_number;
                $newStudentNumber = $this->targetPrefixedNumber($student, $prefix);

                if ($student->student_number === $newStudentNumber && filled($student->original_student_number)) {
                    continue;
                }

                $student->forceFill([
                    'original_student_number' => $originalStudentNumber,
                    'student_number' => $newStudentNumber,
                ])->save();
                $updated++;
            }

            $this->audit(
                action: 'subject_exam_roster_students.prefix_student_numbers',
                roster: $roster,
                prefix: $prefix,
                updatedStudentsCount: $updated,
            );

            return [
                'prefix' => $prefix,
                'updated_students_count' => $updated,
                'students_count' => $students->count(),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function restoreOriginalNumbers(SubjectExamRoster $roster): array
    {
        return DB::transaction(function () use ($roster): array {
            $allStudents = $this->rosterStudents($roster, lockForUpdate: true);
            $students = $allStudents
                ->filter(fn (SubjectExamRosterStudent $student): bool => filled($student->original_student_number)
                    && $student->student_number !== $student->original_student_number)
                ->values();

            $this->ensureUniqueTargetNumbers($allStudents, fn (SubjectExamRosterStudent $student): string => filled($student->original_student_number)
                ? (string) $student->original_student_number
                : (string) $student->student_number);

            $updated = 0;

            foreach ($students as $student) {
                $student->forceFill([
                    'student_number' => $student->original_student_number,
                ])->save();
                $updated++;
            }

            $this->audit(
                action: 'subject_exam_roster_students.restore_original_student_numbers',
                roster: $roster,
                prefix: $this->departmentForRoster($roster)?->student_number_prefix,
                updatedStudentsCount: $updated,
            );

            return [
                'updated_students_count' => $updated,
            ];
        });
    }

    public function featureIsEnabled(SubjectExamRoster $roster): bool
    {
        $roster->loadMissing('college');

        return (bool) $roster->college?->enable_department_student_number_prefix;
    }

    public function hasRestorableNumbers(SubjectExamRoster $roster): bool
    {
        return $roster
            ->rosterStudents()
            ->whereNotNull('original_student_number')
            ->whereColumn('student_number', '!=', 'original_student_number')
            ->exists();
    }

    protected function prefixForRoster(SubjectExamRoster $roster): string
    {
        if (! $this->featureIsEnabled($roster)) {
            throw ValidationException::withMessages([
                'roster' => 'ميزة ترميز الأرقام الجامعية حسب القسم غير مفعلة لهذه الكلية.',
            ]);
        }

        $department = $this->departmentForRoster($roster);

        if (! $department || blank($department->student_number_prefix)) {
            throw ValidationException::withMessages([
                'department' => 'لا يمكن تعديل الأرقام الجامعية لأن القسم لا يحتوي على ترميز.',
            ]);
        }

        return trim((string) $department->student_number_prefix);
    }

    protected function departmentForRoster(SubjectExamRoster $roster): ?Department
    {
        $roster->loadMissing(['department', 'subject.department']);

        return $roster->department ?: $roster->subject?->department;
    }

    /**
     * @return Collection<int, SubjectExamRosterStudent>
     */
    protected function rosterStudents(SubjectExamRoster $roster, bool $lockForUpdate = false): Collection
    {
        $query = $roster
            ->rosterStudents()
            ->orderBy('student_number');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    protected function targetPrefixedNumber(SubjectExamRosterStudent $student, string $prefix): string
    {
        $current = trim((string) $student->student_number);

        if (filled($student->original_student_number)) {
            return $prefix.trim((string) $student->original_student_number);
        }

        if (str_starts_with($current, $prefix)) {
            return $current;
        }

        return $prefix.$current;
    }

    /**
     * @param  Collection<int, SubjectExamRosterStudent>  $students
     */
    protected function countPrefixUpdates(Collection $students, string $prefix): int
    {
        return $students
            ->filter(fn (SubjectExamRosterStudent $student): bool => $student->student_number !== $this->targetPrefixedNumber($student, $prefix)
                || blank($student->original_student_number))
            ->count();
    }

    /**
     * @param  Collection<int, SubjectExamRosterStudent>  $students
     */
    protected function ensureUniqueTargetNumbers(Collection $students, callable $targetNumber): void
    {
        $duplicates = $students
            ->map(fn (SubjectExamRosterStudent $student): string => $targetNumber($student))
            ->countBy()
            ->filter(fn (int $count): bool => $count > 1)
            ->keys()
            ->values();

        if ($duplicates->isEmpty()) {
            return;
        }

        throw ValidationException::withMessages([
            'student_numbers' => 'لا يمكن تنفيذ العملية لأن بعض الأرقام ستصبح مكررة داخل القائمة: '.$duplicates->take(5)->implode(', '),
        ]);
    }

    protected function audit(string $action, SubjectExamRoster $roster, ?string $prefix, int $updatedStudentsCount): void
    {
        $department = $this->departmentForRoster($roster);

        app(AuditLogService::class)->log(
            action: $action,
            module: 'subject_exam_rosters',
            auditable: $roster,
            description: $action === 'subject_exam_roster_students.prefix_student_numbers'
                ? 'تعديل الأرقام الجامعية بإضافة ترميز القسم'
                : 'استعادة الأرقام الجامعية الأصلية',
            metadata: [
                'user_id' => auth()->id(),
                'college_id' => $roster->college_id,
                'department_id' => $department?->id,
                'roster_id' => $roster->id,
                'prefix' => $prefix,
                'updated_students_count' => $updatedStudentsCount,
            ],
        );
    }
}
