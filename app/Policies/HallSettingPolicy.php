<?php

namespace App\Policies;

use App\Support\ExamCollegeScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class HallSettingPolicy extends BaseResourcePolicy
{
    protected static string $resource = 'HallSetting';

    public function delete(User $user, Model $record): bool
    {
        if (ExamCollegeScope::isSuperAdmin($user)) {
            return true;
        }

        return ExamCollegeScope::userCanAccessCollegeId($user, $record->college_id)
            && ($this->hasPermission($user, 'delete') || $this->hasPermission($user, 'update'));
    }

    public function deleteAny(User $user): bool
    {
        return ExamCollegeScope::isSuperAdmin($user)
            || $this->hasPermission($user, 'deleteAny')
            || $this->hasPermission($user, 'delete');
    }

    public function restore(User $user, Model $record): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Model $record): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, Model $record): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return false;
    }
}
