<?php

namespace App\Policies;

use App\Models\User;

class HallSettingPolicy extends BaseResourcePolicy
{
    protected static string $resource = 'HallSetting';

    public function delete(User $user, \Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, \Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, \Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, \Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return false;
    }
}
