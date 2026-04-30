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
        'database_backup_time',
    ];

    public static function defaults(): array
    {
        return [
            'university_name' => 'الجامعة الافتراضية السورية',
            'university_logo' => null,
            'database_backup_time' => '02:00',
        ];
    }

    public static function current(): self
    {
        return static::query()->first() ?? static::query()->create(static::defaults());
    }
}
