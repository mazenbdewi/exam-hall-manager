<?php

namespace App\Models;

use App\Enums\InvigilationRole;
use App\Enums\InvigilatorDayPreference;
use App\Enums\StaffCategory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invigilator extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'college_id',
        'name',
        'phone',
        'staff_category',
        'invigilation_role',
        'eligible_roles',
        'max_assignments',
        'max_assignments_per_day',
        'allow_multiple_assignments_per_day',
        'day_preference',
        'workload_reduction_percentage',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'staff_category' => StaffCategory::class,
            'invigilation_role' => InvigilationRole::class,
            'eligible_roles' => 'array',
            'max_assignments' => 'integer',
            'max_assignments_per_day' => 'integer',
            'allow_multiple_assignments_per_day' => 'boolean',
            'day_preference' => InvigilatorDayPreference::class,
            'workload_reduction_percentage' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(InvigilatorAssignment::class);
    }

    public function effectiveMaxAssignments(int $defaultMax): int
    {
        $baseMax = $this->max_assignments ?? $defaultMax;
        $reduction = max(0, min(100, (int) $this->workload_reduction_percentage));

        if ($reduction >= 100) {
            return 0;
        }

        return (int) floor($baseMax * ((100 - $reduction) / 100));
    }

    /**
     * @return array<int, string>
     */
    public function eligibleRoleValues(): array
    {
        $roles = collect($this->eligible_roles ?? [])
            ->filter()
            ->map(fn (mixed $role): string => $role instanceof InvigilationRole ? $role->value : (string) $role)
            ->filter(fn (string $role): bool => in_array($role, InvigilationRole::values(), true));

        $primaryRole = $this->invigilation_role instanceof InvigilationRole
            ? $this->invigilation_role->value
            : (string) $this->invigilation_role;

        if (filled($primaryRole)) {
            $roles->prepend($primaryRole);
        }

        return $roles->unique()->values()->all();
    }

    public function canServeAs(InvigilationRole $role): bool
    {
        return in_array($role->value, $this->eligibleRoleValues(), true);
    }

    public function hasPrimaryRole(InvigilationRole $role): bool
    {
        $primaryRole = $this->invigilation_role instanceof InvigilationRole
            ? $this->invigilation_role->value
            : (string) $this->invigilation_role;

        return $primaryRole === $role->value;
    }
}
