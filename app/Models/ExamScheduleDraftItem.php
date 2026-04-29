<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamScheduleDraftItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'exam_schedule_draft_id',
        'source_roster_id',
        'subject_id',
        'department_id',
        'subject_exam_offering_id',
        'exam_date',
        'start_time',
        'end_time',
        'period_type',
        'student_count',
        'regular_count',
        'carry_count',
        'is_shared_subject',
        'is_core_subject',
        'shared_group_key',
        'status',
        'conflict_notes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'exam_date' => 'date',
            'student_count' => 'integer',
            'regular_count' => 'integer',
            'carry_count' => 'integer',
            'is_shared_subject' => 'boolean',
            'is_core_subject' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(ExamScheduleDraft::class, 'exam_schedule_draft_id');
    }

    public function sourceRoster(): BelongsTo
    {
        return $this->belongsTo(SubjectExamRoster::class, 'source_roster_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function subjectExamOffering(): BelongsTo
    {
        return $this->belongsTo(SubjectExamOffering::class);
    }
}
