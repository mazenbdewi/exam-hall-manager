<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HallSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'large_hall_min_capacity',
        'amphitheater_min_capacity',
    ];

    protected function casts(): array
    {
        return [
            'large_hall_min_capacity' => 'integer',
            'amphitheater_min_capacity' => 'integer',
        ];
    }

    public static function defaults(): array
    {
        return [
            'large_hall_min_capacity' => 100,
            'amphitheater_min_capacity' => 200,
        ];
    }

    public static function current(): self
    {
        return static::query()->first() ?? static::query()->create(static::defaults());
    }
}
