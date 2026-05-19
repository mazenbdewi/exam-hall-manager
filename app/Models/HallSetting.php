<?php

namespace App\Models;

use App\Support\ExamCollegeScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HallSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'college_id',
        'large_hall_min_capacity',
        'amphitheater_min_capacity',
    ];

    protected function casts(): array
    {
        return [
            'college_id' => 'integer',
            'large_hall_min_capacity' => 'integer',
            'amphitheater_min_capacity' => 'integer',
        ];
    }

    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }

    public static function defaults(): array
    {
        return [
            'large_hall_min_capacity' => 100,
            'amphitheater_min_capacity' => 200,
        ];
    }

    public static function defaultsForCollege(College|int $college): array
    {
        $collegeId = $college instanceof College ? $college->getKey() : $college;

        return [
            'college_id' => $collegeId,
            ...static::defaults(),
        ];
    }

    public static function forCollege(College|int $college): self
    {
        $collegeId = $college instanceof College ? $college->getKey() : $college;

        return static::query()->firstOrCreate(
            ['college_id' => $collegeId],
            static::defaults(),
        );
    }

    public static function current(?int $collegeId = null): self
    {
        $collegeId ??= ExamCollegeScope::currentCollegeId();

        if (filled($collegeId)) {
            return static::forCollege((int) $collegeId);
        }

        return static::query()
            ->whereNotNull('college_id')
            ->first()
            ?? new static(static::defaults());
    }
}
