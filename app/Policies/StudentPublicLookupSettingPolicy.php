<?php

namespace App\Policies;

use App\Models\StudentPublicLookupSetting;
use Illuminate\Database\Eloquent\Model;

class StudentPublicLookupSettingPolicy extends CollegeScopedResourcePolicy
{
    protected static string $resource = 'StudentPublicLookupSetting';

    protected function getCollegeId(Model $record): ?int
    {
        return $record instanceof StudentPublicLookupSetting ? $record->college_id : null;
    }
}
