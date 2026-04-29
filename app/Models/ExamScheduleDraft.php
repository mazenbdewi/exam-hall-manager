<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamScheduleDraft extends Model
{
    use HasFactory;

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
}
