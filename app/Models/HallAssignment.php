<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HallAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'exam_hall_id',
        'exam_date',
        'exam_start_time',
        'college_id',
        'total_capacity',
        'assigned_students_count',
        'remaining_capacity',
    ];

    protected function casts(): array
    {
        return [
            'exam_date' => 'date',
            'total_capacity' => 'integer',
            'assigned_students_count' => 'integer',
            'remaining_capacity' => 'integer',
        ];
    }

    public function examHall(): BelongsTo
    {
        return $this->belongsTo(ExamHall::class);
    }

    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }

    public function assignmentSubjects(): HasMany
    {
        return $this->hasMany(HallAssignmentSubject::class);
    }

    public function studentAssignments(): HasMany
    {
        return $this->hasMany(ExamStudentHallAssignment::class);
    }
}
