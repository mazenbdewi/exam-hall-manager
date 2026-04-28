<?php

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\RoleNames;
use App\Support\ShieldPermission;

class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, AuditLog $auditLog): bool
    {
        return $this->canView($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AuditLog $auditLog): bool
    {
        return false;
    }

    public function delete(User $user, AuditLog $auditLog): bool
    {
        return $user->hasRole(RoleNames::SUPER_ADMIN)
            || $user->can('delete_audit_log')
            || $user->can(ShieldPermission::resource('delete', 'AuditLog'));
    }

    public function deleteAny(User $user): bool
    {
        return $this->delete($user, new AuditLog);
    }

    public function restore(User $user, AuditLog $auditLog): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, AuditLog $auditLog): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    protected function canView(User $user): bool
    {
        return $user->hasRole(RoleNames::SUPER_ADMIN)
            || $user->can('view_audit_log')
            || $user->can('view_any_audit_log')
            || $user->can(ShieldPermission::resource('view', 'AuditLog'))
            || $user->can(ShieldPermission::resource('viewAny', 'AuditLog'));
    }
}
