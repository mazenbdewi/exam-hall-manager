<?php

namespace App\Enums;

enum InvigilatorAssignmentStatus: string
{
    case Assigned = 'assigned';
    case Manual = 'manual';
    case Conflict = 'conflict';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __("exam.invigilator_assignment_statuses.{$this->value}");
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
