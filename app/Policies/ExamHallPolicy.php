<?php

namespace App\Policies;

use App\Models\ExamHall;
use Illuminate\Database\Eloquent\Model;

class ExamHallPolicy extends CollegeScopedResourcePolicy
{
    protected static string $resource = 'ExamHall';

    protected function getCollegeId(Model $record): ?int
    {
        return $record instanceof ExamHall ? $record->college_id : null;
    }
}
