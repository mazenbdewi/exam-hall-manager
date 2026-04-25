<?php

namespace Tests\Feature;

use App\Filament\Resources\ExamHalls\ExamHallResource;
use App\Filament\Resources\HallSettings\HallSettingResource;
use App\Models\College;
use App\Models\ExamHall;
use App\Models\User;
use App\Support\RoleNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class HallValidationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function hall_type_must_match_the_capacity_range_defined_by_settings(): void
    {
        $college = College::query()->create([
            'name' => 'كلية الهندسة',
            'is_active' => true,
        ]);

        Role::findOrCreate(RoleNames::ADMIN, 'web');

        $user = User::factory()->create([
            'college_id' => $college->id,
        ]);

        $user->assignRole(RoleNames::ADMIN);

        $this->actingAs($user);

        $this->expectException(ValidationException::class);

        ExamHallResource::validateAndNormalizeData([
            'college_id' => $college->id,
            'name' => 'القاعة 1',
            'location' => 'المبنى A',
            'capacity' => 220,
            'hall_type' => 'large',
            'priority' => 'medium',
            'is_active' => true,
        ]);
    }

    #[Test]
    public function hall_settings_require_the_amphitheater_threshold_to_exceed_the_large_threshold(): void
    {
        $this->expectException(ValidationException::class);

        HallSettingResource::validateAndNormalizeData([
            'large_hall_min_capacity' => 100,
            'amphitheater_min_capacity' => 100,
        ]);
    }

    #[Test]
    public function hall_settings_cannot_invalidate_existing_halls(): void
    {
        $college = College::query()->create([
            'name' => 'كلية الطب',
            'is_active' => true,
        ]);

        ExamHall::query()->create([
            'college_id' => $college->id,
            'name' => 'قاعة كبيرة',
            'location' => 'المبنى B',
            'capacity' => 150,
            'hall_type' => 'large',
            'priority' => 'medium',
            'is_active' => true,
        ]);

        $this->expectException(ValidationException::class);

        HallSettingResource::validateAndNormalizeData([
            'large_hall_min_capacity' => 160,
            'amphitheater_min_capacity' => 260,
        ]);
    }
}
