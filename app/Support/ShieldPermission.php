<?php

namespace App\Support;

use Illuminate\Support\Str;

class ShieldPermission
{
    public static function resource(string $action, string $resource): string
    {
        return Str::studly($action) . ':' . Str::studly(class_basename($resource));
    }
}
