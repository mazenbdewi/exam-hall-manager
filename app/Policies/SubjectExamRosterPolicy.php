<?php

namespace App\Policies;

use App\Models\SubjectExamRoster;
use Illuminate\Database\Eloquent\Model;

class SubjectExamRosterPolicy extends CollegeScopedResourcePolicy
{
    protected static string $resource = 'SubjectExamRoster';

    protected function getCollegeId(Model $record): ?int
    {
        return $record instanceof SubjectExamRoster ? $record->college_id : null;
    }
}
