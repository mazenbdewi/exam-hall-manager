<?php

namespace App\Policies;

use App\Models\User;
use App\Support\ShieldPermission;
use Illuminate\Database\Eloquent\Model;

abstract class BaseResourcePolicy
{
    protected static string $resource = '';

    protected function hasPermission(User $user, string $action): bool
    {
        return $user->can(ShieldPermission::resource($action, static::$resource));
    }

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'viewAny');
    }

    public function view(User $user, Model $record): bool
    {
        return $this->hasPermission($user, 'view');
    }

    public function create(User $user): bool
    {
        return $this->hasPermission($user, 'create');
    }

    public function update(User $user, Model $record): bool
    {
        return $this->hasPermission($user, 'update');
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->hasPermission($user, 'delete');
    }

    public function deleteAny(User $user): bool
    {
        return $this->hasPermission($user, 'deleteAny');
    }

    public function restore(User $user, Model $record): bool
    {
        return $this->hasPermission($user, 'restore');
    }

    public function restoreAny(User $user): bool
    {
        return $this->hasPermission($user, 'restoreAny');
    }

    public function forceDelete(User $user, Model $record): bool
    {
        return $this->hasPermission($user, 'forceDelete');
    }

    public function forceDeleteAny(User $user): bool
    {
        return $this->hasPermission($user, 'forceDeleteAny');
    }

    public function replicate(User $user, Model $record): bool
    {
        return $this->hasPermission($user, 'replicate');
    }

    public function reorder(User $user): bool
    {
        return $this->hasPermission($user, 'reorder');
    }
}
