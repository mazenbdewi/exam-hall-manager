<?php

namespace App\Enums;

enum ExamStudentType: string
{
    case Regular = 'regular';
    case Carry = 'carry';

    public function label(): string
    {
        return __("exam.student_types.{$this->value}");
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
}
