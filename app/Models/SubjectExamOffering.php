<?php

namespace App\Models;

use App\Enums\ExamOfferingStatus;
use App\Enums\ExamStudentType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SubjectExamOffering extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected static ?bool $hasExamEndTimeColumn = null;

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

    public function invigilatorAssignments(): HasMany
    {
        return $this->hasMany(InvigilatorAssignment::class);
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

    public function scopeWhereTodayExam(Builder $query): Builder
    {
        return $query->whereDate('exam_date', static::nowInApplicationTimezone()->toDateString());
    }

    public function scopeWhereUpcomingExam(Builder $query): Builder
    {
        $now = static::nowInApplicationTimezone();

        return $query->where(function (Builder $query) use ($now): void {
            $query
                ->whereDate('exam_date', '>', $now->toDateString())
                ->orWhere(function (Builder $query) use ($now): void {
                    $query
                        ->whereDate('exam_date', $now->toDateString())
                        ->whereTime('exam_start_time', '>', $now->format('H:i:s'));
                });
        });
    }

    public function scopeWhereFinishedExam(Builder $query): Builder
    {
        $now = static::nowInApplicationTimezone();

        return $query->where(function (Builder $query) use ($now): void {
            $query->whereDate('exam_date', '<', $now->toDateString());

            if (static::hasExamEndTimeColumn()) {
                $query->orWhere(function (Builder $query) use ($now): void {
                    $query
                        ->whereDate('exam_date', $now->toDateString())
                        ->whereTime('exam_end_time', '<', $now->format('H:i:s'));
                });
            }
        });
    }

    public function getExamStatusKeyAttribute(): string
    {
        if (! $this->exam_date) {
            return 'unspecified';
        }

        $now = static::nowInApplicationTimezone();
        $examDate = $this->exam_date->timezone($now->timezone)->startOfDay();
        $today = $now->copy()->startOfDay();

        if ($examDate->lt($today)) {
            return 'finished';
        }

        if ($examDate->gt($today)) {
            return 'upcoming';
        }

        $startAt = $this->examDateTime($this->exam_start_time);
        $endAt = $this->examDateTime($this->getAttribute('exam_end_time'));

        if ($endAt && $endAt->lt($now)) {
            return 'finished';
        }

        if ($startAt && $startAt->gt($now)) {
            return 'upcoming';
        }

        if ($startAt && $endAt && $now->betweenIncluded($startAt, $endAt)) {
            return 'running';
        }

        return 'today';
    }

    public function getExamStatusLabelAttribute(): string
    {
        return match ($this->exam_status_key) {
            'finished' => 'منتهي',
            'running' => 'جاري الآن',
            'upcoming' => 'قادم',
            'today' => 'اليوم',
            default => 'غير محدد',
        };
    }

    public function getExamStatusColorAttribute(): string
    {
        return match ($this->exam_status_key) {
            'finished' => 'danger',
            'running', 'today' => 'success',
            'upcoming' => 'warning',
            default => 'gray',
        };
    }

    public function isTodayExam(): bool
    {
        return $this->exam_date?->isSameDay(static::nowInApplicationTimezone()) ?? false;
    }

    public function isFinishedExam(): bool
    {
        return $this->exam_status_key === 'finished';
    }

    public function isUpcomingExam(): bool
    {
        return $this->exam_status_key === 'upcoming';
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

    protected static function nowInApplicationTimezone(): CarbonInterface
    {
        return now(config('app.timezone'));
    }

    protected static function hasExamEndTimeColumn(): bool
    {
        return static::$hasExamEndTimeColumn ??= Schema::hasColumn('subject_exam_offerings', 'exam_end_time');
    }

    protected function examDateTime(mixed $time): ?CarbonInterface
    {
        if (! $this->exam_date || blank($time)) {
            return null;
        }

        return $this->exam_date
            ->copy()
            ->timezone(config('app.timezone'))
            ->setTimeFromTimeString((string) $time);
    }
}
