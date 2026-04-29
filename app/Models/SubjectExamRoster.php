<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SubjectExamRoster extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'college_id',
        'department_id',
        'subject_id',
        'academic_year_id',
        'semester_id',
        'study_level_id',
        'name',
        'status',
        'source',
        'imported_by',
        'notes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
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

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    public function studyLevel(): BelongsTo
    {
        return $this->belongsTo(StudyLevel::class);
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function rosterStudents(): HasMany
    {
        return $this->hasMany(SubjectExamRosterStudent::class);
    }

    public function eligibleRosterStudents(): HasMany
    {
        return $this->rosterStudents()->where('is_eligible', true);
    }
}
