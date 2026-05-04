<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FixedExamProgram extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'exam_schedule_draft_id',
        'college_id',
        'department_id',
        'academic_year_id',
        'semester_id',
        'college_name',
        'department_name',
        'academic_year',
        'semester',
        'title',
        'status',
        'fixed_at',
        'fixed_by',
        'snapshot_data',
    ];

    protected function casts(): array
    {
        return [
            'fixed_at' => 'datetime',
            'snapshot_data' => 'array',
        ];
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(ExamScheduleDraft::class, 'exam_schedule_draft_id');
    }

    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function semesterModel(): BelongsTo
    {
        return $this->belongsTo(Semester::class, 'semester_id');
    }

    public function fixer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fixed_by');
    }

    public function isArchived(): bool
    {
        return $this->status === 'archived';
    }
}
