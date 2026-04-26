<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class College extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function subjects(): HasMany
    {
        return $this->hasMany(Subject::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function examHalls(): HasMany
    {
        return $this->hasMany(ExamHall::class);
    }

    public function hallAssignments(): HasMany
    {
        return $this->hasMany(HallAssignment::class);
    }

    public function invigilators(): HasMany
    {
        return $this->hasMany(Invigilator::class);
    }

    public function invigilatorDistributionSetting(): HasOne
    {
        return $this->hasOne(InvigilatorDistributionSetting::class);
    }

    public function invigilatorHallRequirements(): HasMany
    {
        return $this->hasMany(InvigilatorHallRequirement::class);
    }

    public function invigilatorAssignments(): HasMany
    {
        return $this->hasMany(InvigilatorAssignment::class);
    }
}
