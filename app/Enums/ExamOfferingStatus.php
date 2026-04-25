<?php

namespace App\Enums;

enum ExamOfferingStatus: string
{
    case Draft = 'draft';
    case Ready = 'ready';
    case Distributed = 'distributed';

    public function label(): string
    {
        return __("exam.statuses.{$this->value}");
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
