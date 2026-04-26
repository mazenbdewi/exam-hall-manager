<?php

namespace App\Models;

use App\Enums\InvigilationRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvigilatorUnassignedRequirement extends Model
{
    use HasFactory;

    protected $fillable = [
        'college_id',
        'exam_date',
        'start_time',
        'exam_hall_id',
        'invigilation_role',
        'required_count',
        'assigned_count',
        'shortage_count',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'exam_date' => 'date',
            'invigilation_role' => InvigilationRole::class,
            'required_count' => 'integer',
            'assigned_count' => 'integer',
            'shortage_count' => 'integer',
        ];
    }

    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }

    public function examHall(): BelongsTo
    {
        return $this->belongsTo(ExamHall::class);
    }
}
