<?php

namespace Tests\Feature;

use App\Enums\ExamHallPriority;
use App\Enums\ExamHallType;
use App\Enums\InvigilationRole;
use App\Enums\InvigilatorAssignmentStatus;
use App\Enums\InvigilatorDayPreference;
use App\Enums\InvigilatorDistributionPattern;
use App\Enums\StaffCategory;
use App\Livewire\InvigilatorLookup;
use App\Models\College;
use App\Models\ExamHall;
use App\Models\Invigilator;
use App\Models\InvigilatorAssignment;
use App\Models\InvigilatorDistributionSetting;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InvigilatorLookupTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_only_shows_assignments_inside_the_configured_visibility_window(): void
    {
        $this->createLookupContext();

        Carbon::setTestNow(Carbon::parse('2026-05-10 07:59:00', config('app.timezone')));

        Livewire::test(InvigilatorLookup::class)
            ->set('phone', '0912345678')
            ->call('search')
            ->assertSet('assignments', [])
            ->assertSee('لا توجد مراقبات متاحة للعرض حاليًا.');

        Carbon::setTestNow(Carbon::parse('2026-05-10 08:00:00', config('app.timezone')));

        Livewire::test(InvigilatorLookup::class)
            ->set('phone', '0912345678')
            ->call('search')
            ->assertSet('assignments.0.hall', 'مدرج A')
            ->assertSet('assignments.0.role', 'أمين سر');
    }

    #[Test]
    public function it_shows_future_assignments_when_public_settings_allow_all_assignments(): void
    {
        $context = $this->createLookupContext([
            'show_all_invigilator_assignments' => true,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-05-09 08:00:00', config('app.timezone')));

        Livewire::test(InvigilatorLookup::class)
            ->set('phone', $context['invigilator']->phone)
            ->call('search')
            ->assertSet('assignments.0.hall', 'مدرج A')
            ->assertSet('assignments.0.status_label', 'قادم');
    }

    protected function createLookupContext(array $settings = []): array
    {
        $college = College::query()->create([
            'name' => 'كلية الهندسة',
            'is_active' => true,
        ]);

        $hall = ExamHall::query()->create([
            'college_id' => $college->id,
            'name' => 'مدرج A',
            'location' => 'المبنى الرئيسي - الطابق الأرضي',
            'capacity' => 120,
            'hall_type' => ExamHallType::Amphitheater->value,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        $invigilator = Invigilator::query()->create([
            'college_id' => $college->id,
            'name' => 'أ. أحمد العلي',
            'phone' => '0912345678',
            'staff_category' => StaffCategory::AdminEmployee->value,
            'invigilation_role' => InvigilationRole::Secretary->value,
            'is_active' => true,
        ]);

        InvigilatorDistributionSetting::query()->create([
            'college_id' => $college->id,
            'default_max_assignments_per_invigilator' => 3,
            'allow_multiple_assignments_per_day' => false,
            'allow_role_fallback' => false,
            'max_assignments_per_day' => 1,
            'distribution_pattern' => InvigilatorDistributionPattern::Balanced->value,
            'day_preference' => InvigilatorDayPreference::Balanced->value,
            'show_all_invigilator_assignments' => false,
            'visibility_before_minutes' => 60,
            'visibility_after_minutes' => 180,
            ...$settings,
        ]);

        InvigilatorAssignment::query()->create([
            'college_id' => $college->id,
            'exam_date' => '2026-05-10',
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
            'exam_hall_id' => $hall->id,
            'invigilator_id' => $invigilator->id,
            'invigilation_role' => InvigilationRole::Secretary->value,
            'assignment_status' => InvigilatorAssignmentStatus::Assigned->value,
        ]);

        return [
            'college' => $college,
            'hall' => $hall,
            'invigilator' => $invigilator,
        ];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
