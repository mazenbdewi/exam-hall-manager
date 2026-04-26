<?php

namespace App\Models;

use App\Casts\ExamHallTypeCast;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvigilatorHallRequirement extends Model
{
    use HasFactory;

    protected $fillable = [
        'college_id',
        'hall_type',
        'hall_head_count',
        'secretary_count',
        'regular_count',
        'reserve_count',
    ];

    protected function casts(): array
    {
        return [
            'hall_type' => ExamHallTypeCast::class,
            'hall_head_count' => 'integer',
            'secretary_count' => 'integer',
            'regular_count' => 'integer',
            'reserve_count' => 'integer',
        ];
    }

    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }
}
