<?php

namespace App\Imports;

use App\Enums\InvigilationRole;
use App\Enums\StaffCategory;
use App\Models\College;
use App\Models\Invigilator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class InvigilatorsImport implements SkipsEmptyRows, ToCollection, WithHeadingRow
{
    protected int $importedCount = 0;

    public function __construct(
        protected College $college,
    ) {}

    public function collection(Collection $rows): void
    {
        $preparedRows = $rows
            ->map(fn (Collection|array $row, int $index): array => [
                ...$this->normalizeRow(collect($row)->all()),
                '_row_number' => $index + 2,
            ])
            ->values();

        if ($preparedRows->isEmpty()) {
            throw ValidationException::withMessages([
                'rows' => __('exam.validation.rows_min'),
            ]);
        }

        DB::transaction(function () use ($preparedRows): void {
            foreach ($preparedRows as $row) {
                $this->validateRow($row);

                $attributes = [
                    'college_id' => $this->college->getKey(),
                    'name' => $row['name'],
                    'phone' => trim((string) $row['phone']),
                    'staff_category' => StaffCategory::fromImportValue($row['staff_category'])->value,
                    'invigilation_role' => InvigilationRole::fromImportValue($row['invigilation_role'])->value,
                    'max_assignments' => filled($row['max_assignments'] ?? null) ? (int) $row['max_assignments'] : null,
                    'allow_multiple_assignments_per_day' => $this->normalizeNullableBoolean($row['allow_multiple_assignments_per_day'] ?? null),
                    'max_assignments_per_day' => filled($row['max_assignments_per_day'] ?? null)
                        ? (int) $row['max_assignments_per_day']
                        : ($this->normalizeNullableBoolean($row['allow_multiple_assignments_per_day'] ?? null) === true ? 2 : null),
                    'day_preference' => $this->normalizeDayPreference($row['day_preference'] ?? null),
                    'workload_reduction_percentage' => $this->normalizePercentage($row['workload_reduction_percentage'] ?? 0) ?? 0,
                    'is_active' => $this->normalizeBoolean($row['is_active'] ?? true),
                    'notes' => $row['notes'] ?? null,
                ];

                $query = Invigilator::withTrashed()
                    ->where('college_id', $this->college->getKey());

                $query->where('phone', $attributes['phone']);

                $invigilator = $query->first();

                if ($invigilator) {
                    $invigilator->restore();
                    $invigilator->update($attributes);
                } else {
                    Invigilator::query()->create($attributes);
                }

                $this->importedCount++;
            }
        });
    }

    public function getImportedCount(): int
    {
        return $this->importedCount;
    }

    protected function normalizeRow(array $row): array
    {
        $aliases = [
            'اسم المراقب' => 'name',
            'asm_almrakb' => 'name',
            'name' => 'name',
            'نوع الكادر' => 'staff_category',
            'noaa_alkadr' => 'staff_category',
            'staff_category' => 'staff_category',
            'رقم الهاتف' => 'phone',
            'rkm_alhatf' => 'phone',
            'phone' => 'phone',
            'نوع المراقبة' => 'invigilation_role',
            'noaa_almrakb' => 'invigilation_role',
            'invigilation_role' => 'invigilation_role',
            'الحد الأقصى للمراقبات' => 'max_assignments',
            'الحد الاقصى للمراقبات' => 'max_assignments',
            'alhd_alaks_llmrakbat' => 'max_assignments',
            'max_assignments' => 'max_assignments',
            'الحد الأقصى في اليوم' => 'max_assignments_per_day',
            'الحد الاقصى في اليوم' => 'max_assignments_per_day',
            'alhd_alaks_fy_alyom' => 'max_assignments_per_day',
            'max_assignments_per_day' => 'max_assignments_per_day',
            'السماح بأكثر من مراقبة في اليوم' => 'allow_multiple_assignments_per_day',
            'alsmah_bakthr_mn_mrakb_fy_alyom' => 'allow_multiple_assignments_per_day',
            'allow_multiple_assignments_per_day' => 'allow_multiple_assignments_per_day',
            'تفضيل الأيام' => 'day_preference',
            'tfdyl_alayam' => 'day_preference',
            'day_preference' => 'day_preference',
            'نسبة تخفيض المراقبات' => 'workload_reduction_percentage',
            'nsb_tkhfyd_almrakbat' => 'workload_reduction_percentage',
            'workload_reduction_percentage' => 'workload_reduction_percentage',
            'فعال' => 'is_active',
            'faaal' => 'is_active',
            'is_active' => 'is_active',
            'ملاحظات' => 'notes',
            'mlahthat' => 'notes',
            'notes' => 'notes',
        ];

        $normalized = [];

        foreach ($row as $key => $value) {
            $key = trim((string) $key);
            $target = $aliases[$key] ?? $aliases[str_replace(' ', '_', $key)] ?? null;

            if (! $target) {
                continue;
            }

            if ($target === 'workload_reduction_percentage') {
                $percentage = $this->normalizePercentage($value);
                $normalized[$target] = $percentage ?? (is_string($value) ? trim($value) : $value);

                continue;
            }

            $normalized[$target] = is_string($value) ? trim($value) : $value;
        }

        return $normalized;
    }

    protected function validateRow(array $row): void
    {
        $rowNumber = $row['_row_number'];

        $validator = Validator::make(
            $row,
            [
                'name' => ['required', 'string', 'max:255'],
                'staff_category' => ['required'],
                'invigilation_role' => ['required'],
                'phone' => ['required', 'string', 'max:30'],
                'max_assignments' => ['nullable', 'integer', 'min:0'],
                'max_assignments_per_day' => ['nullable', 'integer', 'min:1'],
                'workload_reduction_percentage' => ['nullable', 'integer', 'min:0', 'max:100'],
                'allow_multiple_assignments_per_day' => ['nullable'],
                'day_preference' => ['nullable'],
                'notes' => ['nullable', 'string'],
            ],
            [
                'phone.required' => __('exam.validation.invigilator_phone_required_in_import'),
            ],
            attributes: [
                'name' => __('exam.fields.invigilator_name'),
                'staff_category' => __('exam.fields.staff_category'),
                'invigilation_role' => __('exam.fields.invigilation_role'),
                'phone' => __('exam.fields.phone'),
                'max_assignments' => __('exam.fields.max_assignments'),
                'max_assignments_per_day' => __('exam.fields.max_assignments_per_day'),
                'allow_multiple_assignments_per_day' => __('exam.fields.allow_multiple_assignments_per_day'),
                'day_preference' => __('exam.fields.day_preference'),
                'workload_reduction_percentage' => __('exam.fields.workload_reduction_percentage'),
                'is_active' => __('exam.fields.is_active'),
                'notes' => __('exam.fields.notes'),
            ],
        );

        $validator->after(function ($validator) use ($row): void {
            if (filled($row['staff_category'] ?? null) && ! StaffCategory::fromImportValue($row['staff_category'])) {
                $validator->errors()->add('staff_category', __('exam.validation.invalid_staff_category'));
            }

            if (filled($row['invigilation_role'] ?? null) && ! InvigilationRole::fromImportValue($row['invigilation_role'])) {
                $validator->errors()->add('invigilation_role', __('exam.validation.invalid_invigilation_role'));
            }

            if (array_key_exists('is_active', $row) && $this->normalizeBoolean($row['is_active']) === null) {
                $validator->errors()->add('is_active', __('exam.validation.invalid_boolean'));
            }

            if (array_key_exists('workload_reduction_percentage', $row) && $this->normalizePercentage($row['workload_reduction_percentage']) === null) {
                $validator->errors()->add('workload_reduction_percentage', __('exam.validation.invalid_workload_reduction_percentage'));
            }

            if (array_key_exists('allow_multiple_assignments_per_day', $row) && filled($row['allow_multiple_assignments_per_day']) && $this->normalizeNullableBoolean($row['allow_multiple_assignments_per_day']) === null) {
                $validator->errors()->add('allow_multiple_assignments_per_day', __('exam.validation.invalid_boolean'));
            }

            if (array_key_exists('day_preference', $row) && filled($row['day_preference']) && ! $this->isValidDayPreferenceImportValue($row['day_preference'])) {
                $validator->errors()->add('day_preference', __('exam.validation.invalid_invigilator_day_preference'));
            }
        });

        try {
            $validator->validate();
        } catch (ValidationException $exception) {
            $messages = collect($exception->errors())->flatten()->implode(' | ');

            throw ValidationException::withMessages([
                'rows' => __('exam.validation.invigilator_import_row_failed', [
                    'row' => $rowNumber,
                    'message' => $messages,
                ]),
            ]);
        }
    }

    protected function normalizeBoolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = mb_strtolower(trim((string) $value));

        return match ($normalized) {
            '', '1', 'yes', 'نعم', 'true' => true,
            '0', 'no', 'لا', 'false' => false,
            default => null,
        };
    }

    protected function normalizeNullableBoolean(mixed $value): ?bool
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return $this->normalizeBoolean($value);
    }

    protected function normalizeDayPreference(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $normalized = mb_strtolower(trim((string) $value));

        return match ($normalized) {
            'early', 'الأيام الأولى', 'الايام الاولى', 'بداية', 'اولى' => 'early',
            'late', 'الأيام الأخيرة', 'الايام الاخيرة', 'نهاية', 'اخيرة' => 'late',
            'balanced', 'متوازن' => 'balanced',
            'null', 'استخدام الإعداد العام', 'استخدام الاعداد العام', 'عام' => null,
            default => null,
        };
    }

    protected function isValidDayPreferenceImportValue(mixed $value): bool
    {
        if ($value === null || trim((string) $value) === '') {
            return true;
        }

        $normalized = mb_strtolower(trim((string) $value));

        return in_array($normalized, [
            'early',
            'الأيام الأولى',
            'الايام الاولى',
            'بداية',
            'اولى',
            'late',
            'الأيام الأخيرة',
            'الايام الاخيرة',
            'نهاية',
            'اخيرة',
            'balanced',
            'متوازن',
            'null',
            'استخدام الإعداد العام',
            'استخدام الاعداد العام',
            'عام',
        ], true);
    }

    protected function normalizePercentage(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        $normalized = trim(str_replace('%', '', (string) $value));

        if ($normalized === '' || ! is_numeric($normalized)) {
            return null;
        }

        $percentage = (int) $normalized;

        return $percentage >= 0 && $percentage <= 100 ? $percentage : null;
    }
}
