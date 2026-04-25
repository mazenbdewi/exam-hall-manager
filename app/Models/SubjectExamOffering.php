<?php

namespace App\Models;

use App\Enums\ExamOfferingStatus;
use App\Enums\ExamStudentType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SubjectExamOffering extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'subject_id',
        'academic_year_id',
        'semester_id',
        'exam_date',
        'exam_start_time',
        'notes',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'exam_date' => 'date',
            'status' => ExamOfferingStatus::class,
        ];
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

    public function examStudents(): HasMany
    {
        return $this->hasMany(ExamStudent::class);
    }

    public function regularStudents(): HasMany
    {
        return $this->examStudents()->where('student_type', ExamStudentType::Regular->value);
    }

    public function carryStudents(): HasMany
    {
        return $this->examStudents()->where('student_type', ExamStudentType::Carry->value);
    }

    public function hallAssignmentSubjects(): HasMany
    {
        return $this->hasMany(HallAssignmentSubject::class);
    }

    public function studentHallAssignments(): HasMany
    {
        return $this->hasMany(ExamStudentHallAssignment::class);
    }
}
