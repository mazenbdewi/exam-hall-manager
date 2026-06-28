<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvigilatorDistributionDraft extends Model
{
    protected $fillable = [
        'college_id',
        'exam_date_from',
        'exam_date_to',
        'status',
        'created_by',
        'approved_by',
        'approved_at',
        'summary_json',
        'settings_json',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'exam_date_from' => 'date',
            'exam_date_to' => 'date',
            'approved_at' => 'datetime',
            'summary_json' => 'array',
            'settings_json' => 'array',
        ];
    }

    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(InvigilatorDistributionDraftAssignment::class, 'draft_id');
    }
}
