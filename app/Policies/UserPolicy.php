<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class UserPolicy extends CollegeScopedResourcePolicy
{
    protected static string $resource = 'User';

    protected function getCollegeId(Model $record): ?int
    {
        return $record instanceof User ? $record->college_id : null;
    }
}
