<?php

namespace App\Enums;

enum InvigilatorDistributionPattern: string
{
    case Consecutive = 'consecutive';
    case Distributed = 'distributed';
    case Random = 'random';
    case Balanced = 'balanced';

    public function label(): string
    {
        return __("exam.invigilator_distribution_patterns.{$this->value}");
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
