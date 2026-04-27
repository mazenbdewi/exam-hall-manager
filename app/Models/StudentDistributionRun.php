<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentDistributionRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'college_id',
        'from_date',
        'to_date',
        'status',
        'total_offerings',
        'total_slots',
        'total_students',
        'distributed_students',
        'unassigned_students',
        'total_capacity',
        'used_halls',
        'capacity_shortage',
        'executed_by',
        'executed_at',
        'summary_json',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'from_date' => 'date',
            'to_date' => 'date',
            'executed_at' => 'datetime',
            'summary_json' => 'array',
        ];
    }

    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }

    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by');
    }

    public function issues(): HasMany
    {
        return $this->hasMany(StudentDistributionRunIssue::class);
    }

    public function statusLabel(): string
    {
        return __("exam.student_distribution_run_statuses.{$this->status}");
    }
}
