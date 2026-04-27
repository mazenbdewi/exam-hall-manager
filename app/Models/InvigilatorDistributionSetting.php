<?php

namespace App\Models;

use App\Enums\InvigilatorDayPreference;
use App\Enums\InvigilatorDistributionPattern;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvigilatorDistributionSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'college_id',
        'default_max_assignments_per_invigilator',
        'allow_multiple_assignments_per_day',
        'allow_role_fallback',
        'max_assignments_per_day',
        'distribution_pattern',
        'day_preference',
    ];

    protected function casts(): array
    {
        return [
            'default_max_assignments_per_invigilator' => 'integer',
            'allow_multiple_assignments_per_day' => 'boolean',
            'allow_role_fallback' => 'boolean',
            'max_assignments_per_day' => 'integer',
            'distribution_pattern' => InvigilatorDistributionPattern::class,
            'day_preference' => InvigilatorDayPreference::class,
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
            'default_max_assignments_per_invigilator' => 3,
            'allow_multiple_assignments_per_day' => false,
            'allow_role_fallback' => false,
            'max_assignments_per_day' => 1,
            'distribution_pattern' => InvigilatorDistributionPattern::Balanced->value,
            'day_preference' => InvigilatorDayPreference::Balanced->value,
        ]);
    }
}
