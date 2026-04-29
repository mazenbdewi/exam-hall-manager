<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Subject extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'college_id',
        'department_id',
        'study_level_id',
        'name',
        'code',
        'is_active',
        'is_shared_subject',
        'shared_subject_scheduling_mode',
        'is_core_subject',
        'preferred_exam_period',
        'core_subject_priority',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_shared_subject' => 'boolean',
            'is_core_subject' => 'boolean',
        ];
    }

    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function studyLevel(): BelongsTo
    {
        return $this->belongsTo(StudyLevel::class);
    }

    public function subjectExamOfferings(): HasMany
    {
        return $this->hasMany(SubjectExamOffering::class);
    }

    public function subjectExamRosters(): HasMany
    {
        return $this->hasMany(SubjectExamRoster::class);
    }
}
