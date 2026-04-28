<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentPublicLookupSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'college_id',
        'show_all_student_assignments',
        'visibility_before_minutes',
        'visibility_after_minutes',
    ];

    protected function casts(): array
    {
        return [
            'show_all_student_assignments' => 'boolean',
            'visibility_before_minutes' => 'integer',
            'visibility_after_minutes' => 'integer',
        ];
    }

    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }

    public static function defaultsForCollege(College|int $college): self
    {
        $collegeId = $college instanceof College ? $college->getKey() : $college;

        return new self([
            'college_id' => $collegeId,
            'show_all_student_assignments' => false,
            'visibility_before_minutes' => 60,
            'visibility_after_minutes' => 180,
        ]);
    }
}
