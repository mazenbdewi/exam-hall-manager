<?php

namespace Tests\Feature;

use App\Filament\Resources\ExamHalls\ExamHallResource;
use App\Filament\Resources\HallSettings\Pages\EditHallSetting;
use App\Filament\Resources\HallSettings\HallSettingResource;
use App\Models\College;
use App\Models\ExamHall;
use App\Models\HallSetting;
use App\Models\User;
use App\Support\RoleNames;
use App\Support\ShieldPermission;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
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
        $college = College::query()->create([
            'name' => 'كلية الاقتصاد',
            'is_active' => true,
        ]);

        $this->actingAsCollegeAdmin($college);

        $this->expectException(ValidationException::class);

        HallSettingResource::validateAndNormalizeData([
            'college_id' => $college->id,
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

        $this->actingAsCollegeAdmin($college);

        $this->expectException(ValidationException::class);

        HallSettingResource::validateAndNormalizeData([
            'college_id' => $college->id,
            'large_hall_min_capacity' => 160,
            'amphitheater_min_capacity' => 260,
        ]);
    }

    #[Test]
    public function hall_settings_conflict_check_only_uses_halls_from_the_same_college(): void
    {
        $medicine = College::query()->create([
            'name' => 'كلية الطب',
            'is_active' => true,
        ]);

        $science = College::query()->create([
            'name' => 'كلية العلوم',
            'is_active' => true,
        ]);

        ExamHall::query()->create([
            'college_id' => $medicine->id,
            'name' => 'قاعة كبيرة',
            'location' => 'المبنى B',
            'capacity' => 150,
            'hall_type' => 'large',
            'priority' => 'medium',
            'is_active' => true,
        ]);

        $this->actingAsSuperAdmin();

        $validated = HallSettingResource::validateAndNormalizeData([
            'college_id' => $science->id,
            'large_hall_min_capacity' => 160,
            'amphitheater_min_capacity' => 260,
        ]);

        $this->assertSame($science->id, $validated['college_id']);
    }

    #[Test]
    public function hall_settings_edit_page_notifies_when_late_validation_fails(): void
    {
        $college = College::query()->create([
            'name' => 'كلية العلوم',
            'is_active' => true,
        ]);

        $setting = HallSetting::forCollege($college);

        ExamHall::query()->create([
            'college_id' => $college->id,
            'name' => 'قاعة كبيرة',
            'location' => 'المبنى C',
            'capacity' => 150,
            'hall_type' => 'large',
            'priority' => 'medium',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'college_id' => $college->id,
        ]);

        $user->givePermissionTo([
            Permission::findOrCreate(ShieldPermission::resource('viewAny', 'HallSetting'), 'web'),
            Permission::findOrCreate(ShieldPermission::resource('view', 'HallSetting'), 'web'),
            Permission::findOrCreate(ShieldPermission::resource('update', 'HallSetting'), 'web'),
        ]);

        Filament::setCurrentPanel(Filament::getPanel('adminpanel'));

        Livewire::actingAs($user)
            ->test(EditHallSetting::class, ['record' => $setting->getKey()])
            ->set('data.large_hall_min_capacity', 160)
            ->set('data.amphitheater_min_capacity', 260)
            ->call('save')
            ->assertNotified(__('exam.notifications.save_failed'));

        $this->assertSame(100, $setting->refresh()->large_hall_min_capacity);
        $this->assertSame(200, $setting->amphitheater_min_capacity);
    }

    #[Test]
    public function super_admin_can_see_and_delete_unlinked_hall_settings(): void
    {
        $college = College::query()->create([
            'name' => 'كلية الهندسة',
            'is_active' => true,
        ]);

        $linkedSetting = HallSetting::forCollege($college);

        $unlinkedSetting = HallSetting::query()->create([
            'large_hall_min_capacity' => 110,
            'amphitheater_min_capacity' => 210,
        ]);

        $collegeAdmin = $this->actingAsCollegeAdmin($college);

        $this->assertTrue(HallSettingResource::getEloquentQuery()->whereKey($linkedSetting)->exists());
        $this->assertFalse(HallSettingResource::getEloquentQuery()->whereKey($unlinkedSetting)->exists());
        $this->assertFalse($collegeAdmin->can('delete', $unlinkedSetting));

        $superAdmin = $this->actingAsSuperAdmin();

        $this->assertTrue(HallSettingResource::getEloquentQuery()->whereKey($linkedSetting)->exists());
        $this->assertTrue(HallSettingResource::getEloquentQuery()->whereKey($unlinkedSetting)->exists());
        $this->assertTrue($superAdmin->can('delete', $unlinkedSetting));
    }

    protected function actingAsCollegeAdmin(College $college): User
    {
        Role::findOrCreate(RoleNames::ADMIN, 'web');

        $user = User::factory()->create([
            'college_id' => $college->id,
        ]);

        $user->assignRole(RoleNames::ADMIN);

        $this->actingAs($user);

        return $user;
    }

    protected function actingAsSuperAdmin(): User
    {
        Role::findOrCreate(RoleNames::SUPER_ADMIN, 'web');

        $user = User::factory()->create([
            'college_id' => null,
        ]);

        $user->assignRole(RoleNames::SUPER_ADMIN);

        $this->actingAs($user);

        return $user;
    }
}
