<?php

namespace App\Support;

class RoleNames
{
    public const SUPER_ADMIN = 'super_admin';

    public const ADMIN = 'admin';

    public static function all(): array
    {
        return [
            self::SUPER_ADMIN,
            self::ADMIN,
        ];
    }

    public static function label(string $role): string
    {
        return __("exam.roles.{$role}");
    }
}
