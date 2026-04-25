<?php

namespace App\Imports;

use App\Enums\ExamStudentType;
use App\Models\ExamStudent;
use App\Models\SubjectExamOffering;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ExamStudentsImport implements SkipsEmptyRows, ToCollection, WithHeadingRow
{
    protected int $importedCount = 0;

    public function __construct(
        protected SubjectExamOffering $offering,
        protected ExamStudentType $studentType,
    ) {}

    public function collection(Collection $rows): void
    {
        $preparedRows = $rows
            ->map(fn (Collection|array $row): array => collect($row)->map(fn ($value) => is_string($value) ? trim($value) : $value)->all())
            ->values();

        Validator::make(
            ['rows' => $preparedRows->all()],
            [
                'rows' => ['required', 'array', 'min:1'],
                'rows.*.student_number' => ['required', 'string', 'max:255'],
                'rows.*.full_name' => ['required', 'string', 'max:255'],
                'rows.*.notes' => ['nullable', 'string'],
            ],
            [
                'rows.min' => __('exam.validation.rows_min'),
            ],
        )->after(function ($validator) use ($preparedRows): void {
            $duplicatesInFile = $preparedRows
                ->pluck('student_number')
                ->filter()
                ->duplicates()
                ->unique()
                ->values();

            foreach ($duplicatesInFile as $studentNumber) {
                $validator->errors()->add('rows', __('exam.validation.duplicate_student_number_in_file', [
                    'student_number' => $studentNumber,
                ]));
            }

            $existingStudentNumbers = ExamStudent::query()
                ->where('subject_exam_offering_id', $this->offering->getKey())
                ->whereIn('student_number', $preparedRows->pluck('student_number')->filter())
                ->pluck('student_number');

            foreach ($existingStudentNumbers as $studentNumber) {
                $validator->errors()->add('rows', __('exam.validation.student_number_exists_in_offering', [
                    'student_number' => $studentNumber,
                ]));
            }
        })->validate();

        DB::transaction(function () use ($preparedRows): void {
            foreach ($preparedRows as $index => $row) {
                ExamStudent::query()->create([
                    'subject_exam_offering_id' => $this->offering->getKey(),
                    'student_number' => $row['student_number'],
                    'full_name' => $row['full_name'],
                    'student_type' => $this->studentType->value,
                    'notes' => $row['notes'] ?? null,
                ]);

                $this->importedCount = $index + 1;
            }
        });
    }

    public function getImportedCount(): int
    {
        return $this->importedCount;
    }
}
