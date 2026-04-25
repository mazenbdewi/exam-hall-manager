<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HallAssignmentSubject extends Model
{
    use HasFactory;

    protected $fillable = [
        'hall_assignment_id',
        'subject_exam_offering_id',
        'assigned_students_count',
    ];

    protected function casts(): array
    {
        return [
            'assigned_students_count' => 'integer',
        ];
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
