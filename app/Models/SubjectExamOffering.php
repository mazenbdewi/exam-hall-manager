<?php

namespace App\Models;

use App\Enums\ExamOfferingStatus;
use App\Enums\ExamStudentType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

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

    public function scopeWithSameSlotOfferingsCount(Builder $query): Builder
    {
        return $query
            ->select('subject_exam_offerings.*')
            ->selectSub(
                static::sameSlotOfferingsBaseQuery()->selectRaw('count(*)'),
                'same_slot_offerings_count',
            );
    }

    public function scopeWhereHasSameSlotOfferings(Builder $query): Builder
    {
        return $query->whereExists(
            static::sameSlotOfferingsBaseQuery()->selectRaw('1'),
        );
    }

    protected static function sameSlotOfferingsBaseQuery(): QueryBuilder
    {
        return DB::query()
            ->from('subject_exam_offerings as same_slot_offerings')
            ->join('subjects as same_slot_subjects', 'same_slot_subjects.id', '=', 'same_slot_offerings.subject_id')
            ->join('subjects as current_slot_subjects', 'current_slot_subjects.id', '=', 'subject_exam_offerings.subject_id')
            ->whereColumn('same_slot_offerings.exam_date', 'subject_exam_offerings.exam_date')
            ->whereColumn('same_slot_offerings.exam_start_time', 'subject_exam_offerings.exam_start_time')
            ->whereColumn('same_slot_subjects.college_id', 'current_slot_subjects.college_id')
            ->whereColumn('same_slot_offerings.id', '<>', 'subject_exam_offerings.id')
            ->whereNull('same_slot_offerings.deleted_at')
            ->whereNull('same_slot_subjects.deleted_at')
            ->whereNull('current_slot_subjects.deleted_at');
    }
}
