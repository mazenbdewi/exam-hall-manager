<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamScheduleDraft extends Model
{
    use HasFactory;

    public const STATUS_GENERATING = 'generating';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_APPROVED = 'approved';

    protected $fillable = [
        'faculty_id',
        'academic_year_id',
        'semester_id',
        'start_date',
        'end_date',
        'status',
        'generated_by',
        'approved_by',
        'approved_at',
        'settings_json',
        'summary_json',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'approved_at' => 'datetime',
            'settings_json' => 'array',
            'summary_json' => 'array',
        ];
    }

    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class, 'faculty_id');
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ExamScheduleDraftItem::class);
    }

    public function fixedExamPrograms(): HasMany
    {
        return $this->hasMany(FixedExamProgram::class);
    }

    public function scopePrintable(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [self::STATUS_COMPLETED, self::STATUS_APPROVED])
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('summary_json')
                    ->orWhere('summary_json->status', '<>', 'failed');
            })
            ->whereHas('items', fn (Builder $query): Builder => $query
                ->whereNotNull('exam_date')
                ->whereNotNull('start_time')
                ->whereIn('status', ['scheduled', 'manually_adjusted', 'conflict']))
            ->whereDoesntHave('items', fn (Builder $query): Builder => $query
                ->where('status', 'unscheduled')
                ->orWhereNull('exam_date')
                ->orWhereNull('start_time'));
    }

    public function hasPrintableSchedule(): bool
    {
        if (! in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_APPROVED], true)) {
            return false;
        }

        if (($this->summary_json['status'] ?? null) === 'failed') {
            return false;
        }

        $items = $this->relationLoaded('items') ? $this->items : $this->items()->get();

        if ($items->isEmpty()) {
            return false;
        }

        return $items->every(fn (ExamScheduleDraftItem $item): bool => $item->status !== 'unscheduled'
            && filled($item->exam_date)
            && filled($item->start_time));
    }
}
