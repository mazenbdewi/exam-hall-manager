<?php

namespace App\Policies;

use App\Models\FixedExamProgram;
use App\Models\User;
use App\Support\ExamCollegeScope;
use App\Support\ShieldPermission;
use Illuminate\Database\Eloquent\Model;

class FixedExamProgramPolicy extends CollegeScopedResourcePolicy
{
    protected static string $resource = 'FixedExamProgram';

    public function viewAny(User $user): bool
    {
        return $this->canViewFixedPrograms($user);
    }

    public function view(User $user, Model $record): bool
    {
        return $record instanceof FixedExamProgram
            && $this->canViewFixedPrograms($user)
            && ExamCollegeScope::userCanAccessCollegeId($user, $record->college_id);
    }

    public function update(User $user, Model $record): bool
    {
        return $record instanceof FixedExamProgram
            && $this->canManageFixedPrograms($user)
            && ExamCollegeScope::userCanAccessCollegeId($user, $record->college_id);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->update($user, $record);
    }

    public function deleteAny(User $user): bool
    {
        return $this->canManageFixedPrograms($user);
    }

    public function restore(User $user, Model $record): bool
    {
        return $this->update($user, $record);
    }

    public function restoreAny(User $user): bool
    {
        return $this->canManageFixedPrograms($user);
    }

    protected function getCollegeId(Model $record): ?int
    {
        if (! $record instanceof FixedExamProgram) {
            return null;
        }

        return $record->college_id;
    }

    protected function canViewFixedPrograms(User $user): bool
    {
        return ExamCollegeScope::isSuperAdmin($user)
            || $user->can('view_exam_schedule_generator')
            || $user->can(ShieldPermission::resource('viewAny', 'SubjectExamOffering'))
            || $user->can(ShieldPermission::resource('viewAny', 'FixedExamProgram'));
    }

    protected function canManageFixedPrograms(User $user): bool
    {
        return ExamCollegeScope::isSuperAdmin($user)
            || $user->can('approve_exam_schedule_draft')
            || $user->can(ShieldPermission::resource('update', 'FixedExamProgram'))
            || $user->can(ShieldPermission::resource('delete', 'FixedExamProgram'));
    }
}
