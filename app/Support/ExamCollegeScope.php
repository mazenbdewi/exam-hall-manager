<?php

namespace App\Support;

use App\Models\Department;
use App\Models\Subject;
use App\Models\SubjectExamOffering;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class ExamCollegeScope
{
    public static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    public static function isSuperAdmin(?User $user = null): bool
    {
        $user ??= static::user();

        return $user?->hasRole(RoleNames::SUPER_ADMIN) ?? false;
    }

    public static function currentCollegeId(?User $user = null): ?int
    {
        $user ??= static::user();

        return $user?->college_id;
    }

    public static function applyCollegeScope(Builder $query, string $column = 'college_id'): Builder
    {
        if (static::isSuperAdmin()) {
            return $query;
        }

        $collegeId = static::currentCollegeId();

        if ($collegeId) {
            $query->where($column, $collegeId);
        }

        return $query;
    }

    public static function applyRelatedCollegeScope(Builder $query, string $relation, string $column = 'college_id'): Builder
    {
        if (static::isSuperAdmin()) {
            return $query;
        }

        $collegeId = static::currentCollegeId();

        if ($collegeId) {
            $query->whereHas($relation, fn (Builder $relatedQuery) => $relatedQuery->where($column, $collegeId));
        }

        return $query;
    }

    public static function applyUserScope(Builder $query): Builder
    {
        if (static::isSuperAdmin()) {
            return $query;
        }

        $collegeId = static::currentCollegeId();

        if ($collegeId) {
            $query->where('college_id', $collegeId);
        }

        return $query->whereDoesntHave('roles', fn (Builder $roleQuery) => $roleQuery->where('name', RoleNames::SUPER_ADMIN));
    }

    public static function visibleCollegeIds(?User $user = null): ?array
    {
        if (static::isSuperAdmin($user)) {
            return null;
        }

        $collegeId = static::currentCollegeId($user);

        return $collegeId ? [$collegeId] : [];
    }

    public static function userCanAccessCollegeId(?User $user, ?int $collegeId): bool
    {
        if (! $user) {
            return false;
        }

        if (static::isSuperAdmin($user)) {
            return true;
        }

        return filled($collegeId) && ((int) $user->college_id === (int) $collegeId);
    }

    public static function enforceCollegeId(?int $collegeId, string $attribute = 'college_id'): int
    {
        if (static::isSuperAdmin()) {
            if (! filled($collegeId)) {
                throw ValidationException::withMessages([
                    $attribute => __('exam.validation.college_required'),
                ]);
            }

            return (int) $collegeId;
        }

        $currentCollegeId = static::currentCollegeId();

        if (! filled($currentCollegeId)) {
            throw ValidationException::withMessages([
                $attribute => __('exam.validation.account_not_linked_to_college'),
            ]);
        }

        if (filled($collegeId) && ((int) $collegeId !== (int) $currentCollegeId)) {
            throw ValidationException::withMessages([
                $attribute => __('exam.validation.different_college_not_allowed'),
            ]);
        }

        return (int) $currentCollegeId;
    }

    public static function ensureDepartmentBelongsToCollege(?int $departmentId, int $collegeId): void
    {
        if (! filled($departmentId)) {
            return;
        }

        $exists = Department::query()
            ->whereKey($departmentId)
            ->where('college_id', $collegeId)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'department_id' => __('exam.validation.department_not_in_college'),
            ]);
        }
    }

    public static function ensureSubjectBelongsToAccessibleCollege(?int $subjectId): Subject
    {
        $query = Subject::query()->with('college');

        if (! static::isSuperAdmin()) {
            $query->where('college_id', static::currentCollegeId());
        }

        $subject = $query->find($subjectId);

        if (! $subject) {
            throw ValidationException::withMessages([
                'subject_id' => __('exam.validation.subject_outside_scope'),
            ]);
        }

        return $subject;
    }

    public static function ensureOfferingBelongsToAccessibleCollege(SubjectExamOffering $offering): void
    {
        $offering->loadMissing('subject');

        if (! static::userCanAccessCollegeId(static::user(), $offering->subject?->college_id)) {
            throw ValidationException::withMessages([
                'subject_exam_offering_id' => __('exam.validation.offering_outside_scope'),
            ]);
        }
    }

    public static function assignableRoles(?User $user = null): array
    {
        return static::isSuperAdmin($user)
            ? RoleNames::all()
            : [RoleNames::ADMIN];
    }

    /**
     * @return array{0:string,1:?int}
     */
    public static function enforceUserRoleAndCollege(string $role, ?int $collegeId): array
    {
        if (! in_array($role, static::assignableRoles(), true)) {
            throw ValidationException::withMessages([
                'role_name' => __('exam.validation.role_not_allowed'),
            ]);
        }

        if ($role === RoleNames::SUPER_ADMIN) {
            if (! static::isSuperAdmin()) {
                throw ValidationException::withMessages([
                    'role_name' => __('exam.validation.only_super_admin_can_assign_super_admin'),
                ]);
            }

            return [$role, null];
        }

        return [$role, static::enforceCollegeId($collegeId)];
    }
}
