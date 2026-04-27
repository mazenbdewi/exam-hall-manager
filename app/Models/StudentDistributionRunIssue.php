<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentDistributionRunIssue extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_distribution_run_id',
        'exam_date',
        'start_time',
        'subject_exam_offering_id',
        'issue_type',
        'message',
        'affected_students_count',
        'payload_json',
    ];

    protected function casts(): array
    {
        return [
            'exam_date' => 'date',
            'payload_json' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(StudentDistributionRun::class, 'student_distribution_run_id');
    }

    public function subjectExamOffering(): BelongsTo
    {
        return $this->belongsTo(SubjectExamOffering::class);
    }
}
