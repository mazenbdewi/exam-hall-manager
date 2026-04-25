<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'university_name',
        'university_logo',
    ];

    public static function defaults(): array
    {
        return [
            'university_name' => config('app.name'),
            'university_logo' => null,
        ];
    }

    public static function current(): self
    {
        return static::query()->first() ?? static::query()->create(static::defaults());
    }
}
