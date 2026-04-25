<?php

namespace App\Policies;

use App\Models\Department;
use Illuminate\Database\Eloquent\Model;

class DepartmentPolicy extends CollegeScopedResourcePolicy
{
    protected static string $resource = 'Department';

    protected function getCollegeId(Model $record): ?int
    {
        return $record instanceof Department ? $record->college_id : null;
    }
}
