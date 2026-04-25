<?php

namespace App\Enums;

enum ExamHallType: string
{
    case Small = 'small';
    case Large = 'large';
    case Amphitheater = 'amphitheater';

    public function label(): string
    {
        return __("exam.hall_types.{$this->value}");
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
