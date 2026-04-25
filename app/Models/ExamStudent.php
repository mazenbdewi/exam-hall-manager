<?php

namespace App\Models;

use App\Enums\ExamStudentType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class ExamStudent extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'subject_exam_offering_id',
        'student_number',
        'full_name',
        'student_type',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'student_type' => ExamStudentType::class,
        ];
    }

    public function subjectExamOffering(): BelongsTo
    {
        return $this->belongsTo(SubjectExamOffering::class);
    }

    public function hallAssignment(): HasOne
    {
        return $this->hasOne(ExamStudentHallAssignment::class);
    }
}
