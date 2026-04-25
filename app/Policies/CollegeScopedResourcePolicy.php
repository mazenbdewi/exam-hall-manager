<?php

namespace App\Policies;

use App\Models\User;
use App\Support\ExamCollegeScope;
use Illuminate\Database\Eloquent\Model;

abstract class CollegeScopedResourcePolicy extends BaseResourcePolicy
{
    abstract protected function getCollegeId(Model $record): ?int;

    protected function canAccessRecord(User $user, Model $record, string $action): bool
    {
        return $this->hasPermission($user, $action)
            && ExamCollegeScope::userCanAccessCollegeId($user, $this->getCollegeId($record));
    }

    public function view(User $user, Model $record): bool
    {
        return $this->canAccessRecord($user, $record, 'view');
    }

    public function update(User $user, Model $record): bool
    {
        return $this->canAccessRecord($user, $record, 'update');
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->canAccessRecord($user, $record, 'delete');
    }

    public function restore(User $user, Model $record): bool
    {
        return $this->canAccessRecord($user, $record, 'restore');
    }

    public function forceDelete(User $user, Model $record): bool
    {
        return $this->canAccessRecord($user, $record, 'forceDelete');
    }

    public function replicate(User $user, Model $record): bool
    {
        return $this->canAccessRecord($user, $record, 'replicate');
    }
}
