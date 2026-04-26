<?php

namespace App\Policies;

use App\Models\InvigilatorHallRequirement;
use Illuminate\Database\Eloquent\Model;

class InvigilatorHallRequirementPolicy extends CollegeScopedResourcePolicy
{
    protected static string $resource = 'InvigilatorHallRequirement';

    protected function getCollegeId(Model $record): ?int
    {
        return $record instanceof InvigilatorHallRequirement ? $record->college_id : null;
    }
}
