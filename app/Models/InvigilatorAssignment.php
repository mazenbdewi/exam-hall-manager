<?php

namespace App\Models;

use App\Enums\InvigilationRole;
use App\Enums\InvigilatorAssignmentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class InvigilatorAssignment extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'college_id',
        'subject_exam_offering_id',
        'exam_date',
        'start_time',
        'end_time',
        'exam_hall_id',
        'invigilator_id',
        'invigilation_role',
        'assignment_status',
        'assigned_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'exam_date' => 'date',
            'invigilation_role' => InvigilationRole::class,
            'assignment_status' => InvigilatorAssignmentStatus::class,
        ];
    }

    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }

    public function subjectExamOffering(): BelongsTo
    {
        return $this->belongsTo(SubjectExamOffering::class);
    }

    public function examHall(): BelongsTo
    {
        return $this->belongsTo(ExamHall::class);
    }

    public function invigilator(): BelongsTo
    {
        return $this->belongsTo(Invigilator::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
