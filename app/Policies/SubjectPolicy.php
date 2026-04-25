<?php

namespace App\Policies;

use App\Models\Subject;
use Illuminate\Database\Eloquent\Model;

class SubjectPolicy extends CollegeScopedResourcePolicy
{
    protected static string $resource = 'Subject';

    protected function getCollegeId(Model $record): ?int
    {
        return $record instanceof Subject ? $record->college_id : null;
    }
}
