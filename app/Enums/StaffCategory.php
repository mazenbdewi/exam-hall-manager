<?php

namespace App\Enums;

enum StaffCategory: string
{
    case Doctor = 'doctor';
    case AdminEmployee = 'admin_employee';
    case MasterStudent = 'master_student';
    case Other = 'other';

    public function label(): string
    {
        return __("exam.staff_categories.{$this->value}");
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])
            ->all();
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function fromImportValue(mixed $value): ?self
    {
        $normalized = trim((string) $value);

        return match ($normalized) {
            'doctor', 'دكتور' => self::Doctor,
            'admin_employee', 'موظف إداري', 'موظف اداري' => self::AdminEmployee,
            'master_student', 'طالب ماجستير' => self::MasterStudent,
            'other', 'غير ذلك' => self::Other,
            default => self::tryFrom($normalized),
        };
    }
}
