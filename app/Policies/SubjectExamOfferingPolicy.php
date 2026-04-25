<?php

namespace App\Policies;

use App\Models\SubjectExamOffering;
use Illuminate\Database\Eloquent\Model;

class SubjectExamOfferingPolicy extends CollegeScopedResourcePolicy
{
    protected static string $resource = 'SubjectExamOffering';

    protected function getCollegeId(Model $record): ?int
    {
        if (! $record instanceof SubjectExamOffering) {
            return null;
        }

        $record->loadMissing('subject');

        return $record->subject?->college_id;
    }
}
