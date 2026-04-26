<?php

namespace App\Policies;

use App\Models\InvigilatorAssignment;
use Illuminate\Database\Eloquent\Model;

class InvigilatorAssignmentPolicy extends CollegeScopedResourcePolicy
{
    protected static string $resource = 'InvigilatorAssignment';

    protected function getCollegeId(Model $record): ?int
    {
        return $record instanceof InvigilatorAssignment ? $record->college_id : null;
    }
}
