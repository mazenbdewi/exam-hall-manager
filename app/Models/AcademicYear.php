<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AcademicYear extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'is_active',
        'is_current',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_current' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (AcademicYear $academicYear): void {
            if (! $academicYear->is_current) {
                return;
            }

            static::query()
                ->whereKeyNot($academicYear->getKey())
                ->update(['is_current' => false]);
        });
    }

    public function subjectExamOfferings(): HasMany
    {
        return $this->hasMany(SubjectExamOffering::class);
    }
}
