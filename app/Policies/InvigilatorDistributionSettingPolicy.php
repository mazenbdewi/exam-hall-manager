<?php

namespace App\Policies;

use App\Models\InvigilatorDistributionSetting;
use Illuminate\Database\Eloquent\Model;

class InvigilatorDistributionSettingPolicy extends CollegeScopedResourcePolicy
{
    protected static string $resource = 'InvigilatorDistributionSetting';

    protected function getCollegeId(Model $record): ?int
    {
        return $record instanceof InvigilatorDistributionSetting ? $record->college_id : null;
    }
}
