<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubjectExamRosterStudent extends Model
{
    use HasFactory;

    protected $fillable = [
        'subject_exam_roster_id',
        'student_number',
        'full_name',
        'student_type',
        'is_eligible',
        'notes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'is_eligible' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function roster(): BelongsTo
    {
        return $this->belongsTo(SubjectExamRoster::class, 'subject_exam_roster_id');
    }
}
