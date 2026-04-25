<?php

namespace App\Policies;

use App\Models\ExamStudent;
use App\Models\User;
use App\Support\ExamCollegeScope;
use App\Support\ShieldPermission;

class ExamStudentPolicy
{
    protected function canViewOffering(User $user): bool
    {
        return $user->can(ShieldPermission::resource('viewAny', 'SubjectExamOffering'));
    }

    protected function canManageOffering(User $user): bool
    {
        return $user->can(ShieldPermission::resource('update', 'SubjectExamOffering'));
    }

    protected function collegeMatches(User $user, ExamStudent $student): bool
    {
        $student->loadMissing('subjectExamOffering.subject');

        return ExamCollegeScope::userCanAccessCollegeId(
            $user,
            $student->subjectExamOffering?->subject?->college_id,
        );
    }

    public function viewAny(User $user): bool
    {
        return $this->canViewOffering($user);
    }

    public function view(User $user, ExamStudent $student): bool
    {
        return $this->canViewOffering($user) && $this->collegeMatches($user, $student);
    }

    public function create(User $user): bool
    {
        return $this->canManageOffering($user);
    }

    public function update(User $user, ExamStudent $student): bool
    {
        return $this->canManageOffering($user) && $this->collegeMatches($user, $student);
    }

    public function delete(User $user, ExamStudent $student): bool
    {
        return $this->canManageOffering($user) && $this->collegeMatches($user, $student);
    }

    public function deleteAny(User $user): bool
    {
        return $this->canManageOffering($user);
    }

    public function restore(User $user, ExamStudent $student): bool
    {
        return $this->canManageOffering($user) && $this->collegeMatches($user, $student);
    }

    public function restoreAny(User $user): bool
    {
        return $this->canManageOffering($user);
    }

    public function forceDelete(User $user, ExamStudent $student): bool
    {
        return $this->canManageOffering($user) && $this->collegeMatches($user, $student);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $this->canManageOffering($user);
    }

    public function replicate(User $user, ExamStudent $student): bool
    {
        return $this->canManageOffering($user) && $this->collegeMatches($user, $student);
    }

    public function reorder(User $user): bool
    {
        return $this->canManageOffering($user);
    }
}
