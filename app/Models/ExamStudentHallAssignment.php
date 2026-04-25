<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamStudentHallAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'exam_student_id',
        'hall_assignment_id',
        'subject_exam_offering_id',
        'seat_number',
    ];

    protected function casts(): array
    {
        return [
            'seat_number' => 'integer',
        ];
    }

    public function examStudent(): BelongsTo
    {
        return $this->belongsTo(ExamStudent::class);
    }

    public function hallAssignment(): BelongsTo
    {
        return $this->belongsTo(HallAssignment::class);
    }

    public function subjectExamOffering(): BelongsTo
    {
        return $this->belongsTo(SubjectExamOffering::class);
    }
}
