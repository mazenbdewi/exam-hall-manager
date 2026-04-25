<?php

namespace App\Filament\Resources\SubjectExamOfferings\RelationManagers;

use App\Enums\ExamStudentType;

class RegularStudentsRelationManager extends ExamStudentsRelationManager
{
    protected static function getStudentType(): ExamStudentType
    {
        return ExamStudentType::Regular;
    }
}
