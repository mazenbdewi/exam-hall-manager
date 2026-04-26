<?php

namespace App\Models;

use App\Casts\ExamHallTypeCast;
use App\Enums\ExamHallPriority;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ExamHall extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'college_id',
        'name',
        'location',
        'capacity',
        'hall_type',
        'priority',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'hall_type' => ExamHallTypeCast::class,
            'priority' => ExamHallPriority::class,
            'is_active' => 'boolean',
        ];
    }

    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }

    public function hallAssignments(): HasMany
    {
        return $this->hasMany(HallAssignment::class);
    }

    public function invigilatorAssignments(): HasMany
    {
        return $this->hasMany(InvigilatorAssignment::class);
    }
}
