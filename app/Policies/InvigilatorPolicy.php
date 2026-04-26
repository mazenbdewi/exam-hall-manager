<?php

namespace App\Policies;

use App\Models\Invigilator;
use Illuminate\Database\Eloquent\Model;

class InvigilatorPolicy extends CollegeScopedResourcePolicy
{
    protected static string $resource = 'Invigilator';

    protected function getCollegeId(Model $record): ?int
    {
        return $record instanceof Invigilator ? $record->college_id : null;
    }
}
