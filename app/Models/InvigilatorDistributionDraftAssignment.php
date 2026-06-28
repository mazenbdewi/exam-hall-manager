<?php

namespace App\Models;

use App\Enums\InvigilationRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvigilatorDistributionDraftAssignment extends Model
{
    protected $fillable = [
        'draft_id',
        'college_id',
        'invigilator_id',
        'exam_hall_id',
        'exam_date',
        'start_time',
        'invigilation_role',
        'current_duties_count',
        'proposed_duties_count',
        'difference',
        'relaxed_constraints_json',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'exam_date' => 'date',
            'invigilation_role' => InvigilationRole::class,
            'current_duties_count' => 'integer',
            'proposed_duties_count' => 'integer',
            'difference' => 'integer',
            'relaxed_constraints_json' => 'array',
        ];
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(InvigilatorDistributionDraft::class, 'draft_id');
    }

    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }

    public function invigilator(): BelongsTo
    {
        return $this->belongsTo(Invigilator::class);
    }

    public function examHall(): BelongsTo
    {
        return $this->belongsTo(ExamHall::class);
    }
}
