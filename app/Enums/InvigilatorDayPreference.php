<?php

namespace App\Enums;

enum InvigilatorDayPreference: string
{
    case Early = 'early';
    case Late = 'late';
    case Balanced = 'balanced';

    public function label(): string
    {
        return __("exam.invigilator_day_preferences.{$this->value}");
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
