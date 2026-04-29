<?php

namespace App\Imports;

use App\Models\SubjectExamRoster;
use App\Models\SubjectExamRosterStudent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class SubjectExamRosterStudentsImport implements SkipsEmptyRows, ToCollection, WithHeadingRow
{
    protected int $totalRows = 0;

    protected int $importedCount = 0;

    protected int $updatedCount = 0;

    protected int $rejectedCount = 0;

    public function __construct(
        protected SubjectExamRoster $roster,
        protected ?string $defaultStudentType = null,
        protected bool $markReadyAfterImport = true,
    ) {}

    public function collection(Collection $rows): void
    {
        $preparedRows = $rows
            ->map(fn (Collection|array $row): array => $this->normalizeRow($row))
            ->filter(fn (array $row): bool => collect($row)->filter(fn ($value): bool => filled($value))->isNotEmpty())
            ->values();

        $this->totalRows = $preparedRows->count();

        Validator::make(
            ['rows' => $preparedRows->all()],
            [
                'rows' => ['required', 'array', 'min:1'],
                'rows.*.student_number' => ['required', 'string', 'max:255'],
                'rows.*.full_name' => ['required', 'string', 'max:255'],
                'rows.*.student_type' => ['required', 'in:regular,carry'],
                'rows.*.is_eligible' => ['boolean'],
                'rows.*.notes' => ['nullable', 'string'],
            ],
            [
                'rows.min' => 'يجب أن يحتوي ملف الطلاب على صف واحد على الأقل.',
                'rows.*.student_type.in' => 'نوع الطالب يجب أن يكون مستجد أو حملة.',
            ],
            [
                'rows.*.student_number' => 'الرقم الامتحاني',
                'rows.*.full_name' => 'اسم الطالب',
                'rows.*.student_type' => 'نوع الطالب',
                'rows.*.is_eligible' => 'نشط',
                'rows.*.notes' => 'ملاحظات',
            ],
        )->validate();

        DB::transaction(function () use ($preparedRows): void {
            foreach ($preparedRows as $row) {
                $student = SubjectExamRosterStudent::query()->firstOrNew([
                    'subject_exam_roster_id' => $this->roster->getKey(),
                    'student_number' => $row['student_number'],
                ]);

                $exists = $student->exists;

                $student->fill([
                    'full_name' => $row['full_name'],
                    'student_type' => $row['student_type'],
                    'is_eligible' => $row['is_eligible'],
                    'notes' => $row['notes'] ?? null,
                ]);
                $student->save();

                $exists ? $this->updatedCount++ : $this->importedCount++;
            }

            $eligibleCount = $this->roster->eligibleRosterStudents()->count();

            $this->roster->update([
                'source' => 'excel',
                'imported_by' => auth()->id(),
                'status' => $this->markReadyAfterImport && $eligibleCount > 0 ? 'ready' : $this->roster->status,
                'metadata' => array_merge($this->roster->metadata ?? [], [
                    'last_import_summary' => $this->summary(),
                    'last_imported_at' => now()->toDateTimeString(),
                ]),
            ]);
        });
    }

    public function summary(): array
    {
        return [
            'total_rows' => $this->totalRows,
            'imported' => $this->importedCount,
            'updated' => $this->updatedCount,
            'rejected' => $this->rejectedCount,
        ];
    }

    protected function normalizeRow(Collection|array $row): array
    {
        $row = collect($row)
            ->mapWithKeys(fn ($value, $key): array => [(string) $key => is_string($value) ? trim($value) : $value]);

        $studentType = $this->normalizeStudentType($this->firstFilled($row, [
            'student_type',
            'نوع_الطالب',
            'نوع الطالب',
            'noaa_altalb',
        ]) ?: $this->defaultStudentType);

        return [
            'student_number' => (string) $this->firstFilled($row, [
                'student_number',
                'الرقم_الامتحاني',
                'الرقم الامتحاني',
                'alrkm_alamthany',
            ]),
            'full_name' => (string) $this->firstFilled($row, [
                'full_name',
                'اسم_الطالب',
                'اسم الطالب',
                'asm_altalb',
            ]),
            'student_type' => $studentType,
            'is_eligible' => $this->normalizeEligibility($this->firstFilled($row, [
                'is_eligible',
                'نشط',
                'مؤهل',
                'nsht',
                'mohl',
            ])),
            'notes' => $this->firstFilled($row, [
                'notes',
                'ملاحظات',
                'mlahthat',
            ]),
        ];
    }

    protected function firstFilled(Collection $row, array $keys): mixed
    {
        foreach ($keys as $key) {
            if ($row->has($key) && filled($row->get($key))) {
                return $row->get($key);
            }
        }

        return null;
    }

    protected function normalizeStudentType(mixed $value): string
    {
        if (blank($value)) {
            return '';
        }

        return match (strtolower(trim((string) $value))) {
            'carry', 'حملة', 'حمله' => 'carry',
            'regular', 'مستجد' => 'regular',
            default => (string) $value,
        };
    }

    protected function normalizeEligibility(mixed $value): bool
    {
        if (blank($value)) {
            return true;
        }

        return match (strtolower(trim((string) $value))) {
            '0', 'no', 'لا', 'false' => false,
            default => true,
        };
    }
}
