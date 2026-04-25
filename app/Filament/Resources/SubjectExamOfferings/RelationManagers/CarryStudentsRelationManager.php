<?php

namespace App\Filament\Resources\SubjectExamOfferings\RelationManagers;

use App\Enums\ExamStudentType;

class CarryStudentsRelationManager extends ExamStudentsRelationManager
{
    protected static function getStudentType(): ExamStudentType
    {
        return ExamStudentType::Carry;
    }
}
