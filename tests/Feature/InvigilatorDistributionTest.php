<?php

namespace Tests\Feature;

use App\Enums\ExamHallPriority;
use App\Enums\ExamHallType;
use App\Enums\ExamOfferingStatus;
use App\Enums\ExamStudentType;
use App\Enums\InvigilationRole;
use App\Enums\InvigilatorAssignmentStatus;
use App\Enums\StaffCategory;
use App\Exports\InvigilatorsTemplateExport;
use App\Filament\Resources\SubjectExamOfferings\Pages\GlobalDistributionResults;
use App\Imports\InvigilatorsImport;
use App\Models\AcademicYear;
use App\Models\College;
use App\Models\Department;
use App\Models\ExamHall;
use App\Models\ExamStudent;
use App\Models\HallAssignment;
use App\Models\Invigilator;
use App\Models\InvigilatorAssignment;
use App\Models\InvigilatorDistributionSetting;
use App\Models\InvigilatorHallRequirement;
use App\Models\InvigilatorUnassignedRequirement;
use App\Models\Semester;
use App\Models\StudentDistributionRun;
use App\Models\StudentDistributionRunIssue;
use App\Models\StudyLevel;
use App\Models\Subject;
use App\Models\SubjectExamOffering;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\ExamHallDistributionService;
use App\Services\InvigilatorDistributionService;
use App\Support\RoleNames;
use App\Support\ShieldPermission;
use Carbon\Carbon;
use Database\Seeders\InvigilatorSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Facades\Excel;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InvigilatorDistributionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_imports_invigilators_and_updates_duplicates_by_phone(): void
    {
        $college = College::query()->create(['name' => 'كلية الهندسة', 'is_active' => true]);
        Invigilator::query()->create([
            'college_id' => $college->id,
            'name' => 'اسم قديم',
            'phone' => '0999',
            'staff_category' => StaffCategory::Other->value,
            'invigilation_role' => InvigilationRole::Regular->value,
            'is_active' => false,
        ]);

        $path = 'testing/invigilators.xlsx';
        Excel::store(new class implements FromArray, WithHeadings
        {
            public function headings(): array
            {
                return [
                    'اسم المراقب',
                    'نوع الكادر',
                    'رقم الهاتف',
                    'نوع المراقبة',
                    'الحد الأقصى للمراقبات',
                    'نسبة تخفيض المراقبات',
                    'السماح بأكثر من مراقبة في اليوم',
                    'الحد الأقصى في اليوم',
                    'تفضيل الأيام',
                    'فعال',
                    'ملاحظات',
                ];
            }

            public function array(): array
            {
                return [
                    ['د. أحمد', 'دكتور', '0999', 'رئيس قاعة', 4, '25%', 'نعم', null, 'الأيام الأولى', 'نعم', 'محدث'],
                    ['سارة', 'موظف إداري', '0998', 'مراقب عادي', null, null, null, null, 'استخدام الإعداد العام', 'yes', null],
                ];
            }
        }, $path, 'local');

        $import = new InvigilatorsImport($college);
        Excel::import($import, Storage::disk('local')->path($path));

        $this->assertSame(2, $import->getImportedCount());
        $this->assertSame(2, Invigilator::query()->count());

        $updated = Invigilator::query()->where('phone', '0999')->first();
        $this->assertSame('د. أحمد', $updated->name);
        $this->assertSame(StaffCategory::Doctor, $updated->staff_category);
        $this->assertSame(InvigilationRole::HallHead, $updated->invigilation_role);
        $this->assertSame(25, $updated->workload_reduction_percentage);
        $this->assertTrue($updated->allow_multiple_assignments_per_day);
        $this->assertSame(2, $updated->max_assignments_per_day);
        $this->assertSame('early', $updated->day_preference->value);
        $this->assertTrue($updated->is_active);
    }

    #[Test]
    public function it_rejects_imported_invigilators_without_phone(): void
    {
        $college = College::query()->create(['name' => 'كلية الهندسة', 'is_active' => true]);
        $path = 'testing/invigilators-missing-phone.xlsx';
        Excel::store(new class implements FromArray, WithHeadings
        {
            public function headings(): array
            {
                return ['اسم المراقب', 'نوع الكادر', 'رقم الهاتف', 'نوع المراقبة'];
            }

            public function array(): array
            {
                return [
                    ['د. أحمد', 'دكتور', null, 'رئيس قاعة'],
                ];
            }
        }, $path, 'local');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(__('exam.validation.invigilator_phone_required_in_import'));

        Excel::import(new InvigilatorsImport($college), Storage::disk('local')->path($path));
    }

    #[Test]
    public function invigilator_template_includes_personal_distribution_columns(): void
    {
        $export = new InvigilatorsTemplateExport;

        $this->assertSame([
            'اسم المراقب',
            'نوع الكادر',
            'رقم الهاتف',
            'نوع المراقبة',
            'الحد الأقصى للمراقبات',
            'نسبة تخفيض المراقبات',
            'السماح بأكثر من مراقبة في اليوم',
            'الحد الأقصى في اليوم',
            'تفضيل الأيام',
            'فعال',
            'ملاحظات',
        ], $export->headings());

        $this->assertSame('لا', $export->collection()->first()[6]);
        $this->assertSame('متوازن', $export->collection()->first()[8]);
    }

    #[Test]
    public function it_assigns_required_invigilators_per_hall_type_and_prevents_same_time_conflicts(): void
    {
        $context = $this->createSlotContext();
        $largeHall = $this->createUsedHall($context['college'], 'القاعة الكبيرة', ExamHallType::Large);
        $smallHall = $this->createUsedHall($context['college'], 'القاعة الصغيرة', ExamHallType::Small);

        InvigilatorDistributionSetting::query()->create([
            'college_id' => $context['college']->id,
            'default_max_assignments_per_invigilator' => 10,
            'allow_multiple_assignments_per_day' => true,
            'max_assignments_per_day' => 3,
            'distribution_pattern' => 'balanced',
            'day_preference' => 'balanced',
        ]);
        $this->createRequirement($context['college'], ExamHallType::Large, 1, 1, 2, 0);
        $this->createRequirement($context['college'], ExamHallType::Small, 1, 0, 1, 0);

        $this->createInvigilators($context['college'], InvigilationRole::HallHead, 2);
        $this->createInvigilators($context['college'], InvigilationRole::Secretary, 1);
        $this->createInvigilators($context['college'], InvigilationRole::Regular, 3);

        $result = app(InvigilatorDistributionService::class)->distributeForSlot($context['college'], '2026-06-01', '09:00:00');

        $this->assertSame('success', $result['status']);
        $this->assertSame(6, InvigilatorAssignment::query()->count());
        $this->assertSame(4, $largeHall->invigilatorAssignments()->count());
        $this->assertSame(2, $smallHall->invigilatorAssignments()->count());

        $duplicateAtSameTime = InvigilatorAssignment::query()
            ->select('invigilator_id')
            ->whereDate('exam_date', '2026-06-01')
            ->whereTime('start_time', '09:00:00')
            ->groupBy('invigilator_id')
            ->havingRaw('count(*) > 1')
            ->exists();

        $this->assertFalse($duplicateAtSameTime);
    }

    #[Test]
    public function it_assigns_available_secretary_to_secretary_slot_and_reports_it_by_role(): void
    {
        $context = $this->createSlotContext();
        $hall = $this->createUsedHall($context['college'], 'قاعة تحتاج أمين سر', ExamHallType::Large);
        $this->createRequirement($context['college'], ExamHallType::Large, 0, 1, 0, 0);
        $this->createInvigilators($context['college'], InvigilationRole::Secretary, 1);

        $result = app(InvigilatorDistributionService::class)->distributeForSlot($context['college'], '2026-06-01', '09:00:00');
        $summary = app(InvigilatorDistributionService::class)->slotSummary($context['college'], '2026-06-01', '09:00:00');
        $hallSummary = collect($summary['halls'])->firstWhere('id', $hall->id);

        $this->assertSame('success', $result['status']);
        $this->assertDatabaseHas('invigilator_assignments', [
            'exam_hall_id' => $hall->id,
            'invigilation_role' => InvigilationRole::Secretary->value,
        ]);
        $this->assertCount(1, $hallSummary['assignments_by_role']['secretary']);
        $this->assertSame(0, $summary['shortage_count']);
    }

    #[Test]
    public function it_does_not_replace_secretary_with_regular_when_role_fallback_is_disabled(): void
    {
        $context = $this->createSlotContext();
        $hall = $this->createUsedHall($context['college'], 'قاعة بلا أمين سر', ExamHallType::Large);
        $this->createRequirement($context['college'], ExamHallType::Large, 0, 1, 0, 0);
        $this->createInvigilators($context['college'], InvigilationRole::Regular, 1);

        $result = app(InvigilatorDistributionService::class)->distributeForSlot($context['college'], '2026-06-01', '09:00:00');
        $summary = app(InvigilatorDistributionService::class)->slotSummary($context['college'], '2026-06-01', '09:00:00');
        $hallSummary = collect($summary['halls'])->firstWhere('id', $hall->id);

        $this->assertSame('partial', $result['status']);
        $this->assertSame(0, InvigilatorAssignment::query()->count());
        $this->assertCount(0, $hallSummary['assignments_by_role']['secretary']);
        $this->assertSame(1, $hallSummary['shortages_by_role']['secretary']['shortage_count']);
        $this->assertSame('لا يوجد أمين سر فعال لهذه الكلية.', $hallSummary['shortages_by_role']['secretary']['reason']);
    }

    #[Test]
    public function it_reports_missing_regular_invigilator_shortage(): void
    {
        $context = $this->createSlotContext();
        $hall = $this->createUsedHall($context['college'], 'قاعة نقص مراقب عادي', ExamHallType::Large);
        $this->createRequirement($context['college'], ExamHallType::Large, 0, 0, 2, 0);
        $this->createInvigilators($context['college'], InvigilationRole::Regular, 1);

        $result = app(InvigilatorDistributionService::class)->distributeForSlot($context['college'], '2026-06-01', '09:00:00');
        $summary = app(InvigilatorDistributionService::class)->slotSummary($context['college'], '2026-06-01', '09:00:00');
        $hallSummary = collect($summary['halls'])->firstWhere('id', $hall->id);

        $this->assertSame('partial', $result['status']);
        $this->assertCount(1, $hallSummary['assignments_by_role']['regular']);
        $this->assertSame(1, $hallSummary['shortages_by_role']['regular']['shortage_count']);
        $this->assertSame('جميع المراقبين العاديين لديهم مراقبة في نفس الموعد.', $hallSummary['shortages_by_role']['regular']['reason']);
    }

    #[Test]
    public function it_reports_missing_hall_head_shortage(): void
    {
        $context = $this->createSlotContext();
        $hall = $this->createUsedHall($context['college'], 'قاعة بلا رئيس', ExamHallType::Large);
        $this->createRequirement($context['college'], ExamHallType::Large, 1, 0, 0, 0);

        $result = app(InvigilatorDistributionService::class)->distributeForSlot($context['college'], '2026-06-01', '09:00:00');
        $summary = app(InvigilatorDistributionService::class)->slotSummary($context['college'], '2026-06-01', '09:00:00');
        $hallSummary = collect($summary['halls'])->firstWhere('id', $hall->id);

        $this->assertSame('partial', $result['status']);
        $this->assertSame(1, $hallSummary['shortages_by_role']['hall_head']['shortage_count']);
        $this->assertSame('لا يوجد رئيس قاعة فعال لهذه الكلية.', $hallSummary['shortages_by_role']['hall_head']['reason']);
    }

    #[Test]
    public function it_reports_multiple_shortages_in_the_same_hall(): void
    {
        $context = $this->createSlotContext();
        $hall = $this->createUsedHall($context['college'], 'قاعة نقص متعدد', ExamHallType::Large);
        $this->createRequirement($context['college'], ExamHallType::Large, 1, 1, 2, 0);
        $this->createInvigilators($context['college'], InvigilationRole::HallHead, 1);
        $this->createInvigilators($context['college'], InvigilationRole::Regular, 1);

        $result = app(InvigilatorDistributionService::class)->distributeForSlot($context['college'], '2026-06-01', '09:00:00');
        $summary = app(InvigilatorDistributionService::class)->slotSummary($context['college'], '2026-06-01', '09:00:00');
        $hallSummary = collect($summary['halls'])->firstWhere('id', $hall->id);

        $this->assertSame('partial', $result['status']);
        $this->assertArrayHasKey('secretary', $hallSummary['shortages_by_role']);
        $this->assertArrayHasKey('regular', $hallSummary['shortages_by_role']);
        $this->assertArrayNotHasKey('hall_head', $hallSummary['shortages_by_role']);
        $this->assertSame(2, $summary['shortage_count']);
    }

    #[Test]
    public function shortage_pdf_view_includes_all_shortage_roles(): void
    {
        $context = $this->createSlotContext();
        $this->createUsedHall($context['college'], 'قاعة تقرير النقص', ExamHallType::Large);
        $this->createRequirement($context['college'], ExamHallType::Large, 1, 1, 2, 0);
        $this->createInvigilators($context['college'], InvigilationRole::HallHead, 1);
        $this->createInvigilators($context['college'], InvigilationRole::Regular, 1);

        app(InvigilatorDistributionService::class)->distributeForSlot($context['college'], '2026-06-01', '09:00:00');

        $summary = app(InvigilatorDistributionService::class)->getSummary($context['college'], null, null, '2026-06-01', '2026-06-01');
        $html = view('pdf.invigilator-distribution-shortage', [
            'summary' => $summary,
            'systemSetting' => SystemSetting::current(),
            'logoDataUri' => null,
        ])->render();

        $this->assertCount(2, $summary['shortages']);
        $this->assertStringContainsString('أمين سر', $html);
        $this->assertStringContainsString('مراقب عادي', $html);
    }

    #[Test]
    public function invigilator_pdf_views_do_not_render_phone_numbers(): void
    {
        $context = $this->createSlotContext();
        $this->createUsedHall($context['college'], 'قاعة بلا هواتف', ExamHallType::Large);
        $this->createRequirement($context['college'], ExamHallType::Large, 1, 1, 1, 0);
        $this->createInvigilators($context['college'], InvigilationRole::HallHead, 1);
        $this->createInvigilators($context['college'], InvigilationRole::Secretary, 1);
        $this->createInvigilators($context['college'], InvigilationRole::Regular, 1);

        app(InvigilatorDistributionService::class)->distributeForSlot($context['college'], '2026-06-01', '09:00:00');

        $summary = app(InvigilatorDistributionService::class)->getSummary($context['college'], null, null, '2026-06-01', '2026-06-01');
        $viewData = [
            'summary' => $summary,
            'systemSetting' => SystemSetting::current(),
            'logoDataUri' => null,
            'reportDateRange' => __('exam.fields.period').': 2026-06-01 - 2026-06-01',
        ];

        $html = view('pdf.invigilator-distribution-by-invigilator', $viewData)->render()
            .view('pdf.invigilator-distribution-by-hall', $viewData)->render();

        $phones = Invigilator::query()
            ->where('college_id', $context['college']->id)
            ->pluck('phone')
            ->filter()
            ->all();

        $this->assertStringNotContainsString(__('exam.fields.phone'), $html);
        $this->assertStringNotContainsString(__('exam.fields.phone_numbers'), $html);

        foreach ($phones as $phone) {
            $this->assertStringNotContainsString($phone, $html);
        }
    }

    #[Test]
    public function required_empty_role_cell_is_rendered_as_shortage_even_without_shortage_row(): void
    {
        $html = view('filament.pages.partials.invigilator-hall-card', [
            'hall' => [
                'name' => 'قاعة اختبار',
                'hall_type_label' => 'كبيرة',
                'location' => 'المبنى الأول',
                'assigned_count' => 0,
                'required_count' => 1,
                'required_roles' => [
                    InvigilationRole::Secretary->value => 1,
                ],
                'assignments_by_role' => [
                    InvigilationRole::HallHead->value => [],
                    InvigilationRole::Secretary->value => [],
                    InvigilationRole::Regular->value => [],
                    InvigilationRole::Reserve->value => [],
                ],
                'shortages_by_role' => [],
            ],
        ])->render();

        $this->assertStringContainsString('أمين سر', $html);
        $this->assertStringContainsString('يوجد نقص', $html);
        $this->assertStringContainsString('تعذر توفير العدد المطلوب', $html);
    }

    #[Test]
    public function it_uses_allowed_role_fallback_for_secretary_and_records_note(): void
    {
        $context = $this->createSlotContext();
        $hall = $this->createUsedHall($context['college'], 'قاعة بتعويض', ExamHallType::Large);

        InvigilatorDistributionSetting::query()->create([
            'college_id' => $context['college']->id,
            'default_max_assignments_per_invigilator' => 10,
            'allow_multiple_assignments_per_day' => true,
            'allow_role_fallback' => true,
            'max_assignments_per_day' => 3,
            'distribution_pattern' => 'balanced',
            'day_preference' => 'balanced',
        ]);

        $this->createRequirement($context['college'], ExamHallType::Large, 0, 1, 0, 0);
        $this->createInvigilators($context['college'], InvigilationRole::HallHead, 1);

        $result = app(InvigilatorDistributionService::class)->distributeForSlot($context['college'], '2026-06-01', '09:00:00');
        $assignment = InvigilatorAssignment::query()->first();

        $this->assertSame('success', $result['status']);
        $this->assertSame(0, InvigilatorUnassignedRequirement::query()->count());
        $this->assertSame($hall->id, $assignment?->exam_hall_id);
        $this->assertSame(InvigilationRole::Secretary, $assignment?->invigilation_role);
        $this->assertStringContainsString('تم استخدام مراقب بديل', (string) $assignment?->notes);
    }

    #[Test]
    public function it_respects_assignment_limits_and_reports_shortage(): void
    {
        $context = $this->createSlotContext();
        $this->createUsedHall($context['college'], 'قاعة 1', ExamHallType::Large);
        $this->createUsedHall($context['college'], 'قاعة 2', ExamHallType::Large);
        $this->createRequirement($context['college'], ExamHallType::Large, 0, 0, 1, 0);

        InvigilatorDistributionSetting::query()->create([
            'college_id' => $context['college']->id,
            'default_max_assignments_per_invigilator' => 1,
            'allow_multiple_assignments_per_day' => false,
            'max_assignments_per_day' => 1,
            'distribution_pattern' => 'balanced',
            'day_preference' => 'balanced',
        ]);

        $this->createInvigilators($context['college'], InvigilationRole::Regular, 1);

        $result = app(InvigilatorDistributionService::class)->distributeForSlot($context['college'], '2026-06-01', '09:00:00');

        $this->assertSame('partial', $result['status']);
        $this->assertSame(1, InvigilatorAssignment::query()->count());
        $this->assertSame(1, $result['shortage_count']);
    }

    #[Test]
    public function invigilator_personal_allow_multiple_per_day_is_respected(): void
    {
        $context = $this->createSlotContext();
        $hall = $this->createUsedHall($context['college'], 'قاعة 1', ExamHallType::Large);
        $this->createRequirement($context['college'], ExamHallType::Large, 0, 0, 1, 0);

        InvigilatorDistributionSetting::query()->create([
            'college_id' => $context['college']->id,
            'default_max_assignments_per_invigilator' => 10,
            'allow_multiple_assignments_per_day' => true,
            'max_assignments_per_day' => 3,
            'distribution_pattern' => 'balanced',
            'day_preference' => 'balanced',
        ]);

        $invigilator = Invigilator::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'مراقب لا يسمح له بالتكرار اليومي',
            'phone' => '0988111001',
            'staff_category' => StaffCategory::Doctor->value,
            'invigilation_role' => InvigilationRole::Regular->value,
            'allow_multiple_assignments_per_day' => false,
            'max_assignments_per_day' => 3,
            'is_active' => true,
        ]);

        InvigilatorAssignment::query()->create([
            'college_id' => $context['college']->id,
            'exam_date' => '2026-06-01',
            'start_time' => '07:00:00',
            'exam_hall_id' => $hall->id,
            'invigilator_id' => $invigilator->id,
            'invigilation_role' => InvigilationRole::Regular->value,
            'assignment_status' => InvigilatorAssignmentStatus::Manual->value,
        ]);

        $result = app(InvigilatorDistributionService::class)->distributeForSlot($context['college'], '2026-06-01', '09:00:00');

        $this->assertSame('partial', $result['status']);
        $this->assertSame(1, InvigilatorAssignment::query()->count());
        $this->assertSame(1, $result['shortage_count']);
        $this->assertSame('لا يسمح لهذا المراقب بأكثر من مراقبة في اليوم.', InvigilatorUnassignedRequirement::query()->first()?->reason);
    }

    #[Test]
    public function invigilator_personal_max_per_day_is_respected(): void
    {
        $context = $this->createSlotContext();
        $hall = $this->createUsedHall($context['college'], 'قاعة 1', ExamHallType::Large);
        $this->createRequirement($context['college'], ExamHallType::Large, 0, 0, 1, 0);

        InvigilatorDistributionSetting::query()->create([
            'college_id' => $context['college']->id,
            'default_max_assignments_per_invigilator' => 10,
            'allow_multiple_assignments_per_day' => true,
            'max_assignments_per_day' => 3,
            'distribution_pattern' => 'balanced',
            'day_preference' => 'balanced',
        ]);

        $invigilator = Invigilator::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'مراقب بحد يومي شخصي',
            'phone' => '0988111002',
            'staff_category' => StaffCategory::Doctor->value,
            'invigilation_role' => InvigilationRole::Regular->value,
            'allow_multiple_assignments_per_day' => true,
            'max_assignments_per_day' => 1,
            'is_active' => true,
        ]);

        InvigilatorAssignment::query()->create([
            'college_id' => $context['college']->id,
            'exam_date' => '2026-06-01',
            'start_time' => '07:00:00',
            'exam_hall_id' => $hall->id,
            'invigilator_id' => $invigilator->id,
            'invigilation_role' => InvigilationRole::Regular->value,
            'assignment_status' => InvigilatorAssignmentStatus::Manual->value,
        ]);

        $result = app(InvigilatorDistributionService::class)->distributeForSlot($context['college'], '2026-06-01', '09:00:00');

        $this->assertSame('partial', $result['status']);
        $this->assertSame(1, InvigilatorAssignment::query()->count());
        $this->assertSame(1, $result['shortage_count']);
        $this->assertSame('تجاوز هذا المراقب الحد الأقصى اليومي المحدد له.', InvigilatorUnassignedRequirement::query()->first()?->reason);
    }

    #[Test]
    public function invigilator_personal_day_preference_overrides_faculty_setting(): void
    {
        $context = $this->createSlotContext();
        $setting = InvigilatorDistributionSetting::query()->create([
            'college_id' => $context['college']->id,
            'default_max_assignments_per_invigilator' => 10,
            'allow_multiple_assignments_per_day' => true,
            'max_assignments_per_day' => 3,
            'distribution_pattern' => 'balanced',
            'day_preference' => 'late',
        ]);

        $invigilator = Invigilator::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'مراقب بتفضيل شخصي',
            'phone' => '0988111003',
            'staff_category' => StaffCategory::Doctor->value,
            'invigilation_role' => InvigilationRole::Regular->value,
            'day_preference' => 'early',
            'is_active' => true,
        ]);

        InvigilatorAssignment::query()->create([
            'college_id' => $context['college']->id,
            'exam_date' => '2026-05-31',
            'start_time' => '09:00:00',
            'exam_hall_id' => $this->createUsedHall($context['college'], 'قاعة سابقة', ExamHallType::Small)->id,
            'invigilator_id' => $invigilator->id,
            'invigilation_role' => InvigilationRole::Regular->value,
            'assignment_status' => InvigilatorAssignmentStatus::Manual->value,
        ]);

        $method = new \ReflectionMethod(InvigilatorDistributionService::class, 'score');
        $method->setAccessible(true);

        $personalScore = $method->invoke(app(InvigilatorDistributionService::class), $invigilator->fresh(), '2026-06-01', $setting);

        $invigilator->forceFill(['day_preference' => null])->save();
        $facultyScore = $method->invoke(app(InvigilatorDistributionService::class), $invigilator->fresh(), '2026-06-01', $setting);

        $this->assertSame(1, $personalScore[3]);
        $this->assertSame(-1, $facultyScore[3]);
    }

    #[Test]
    public function it_blocks_invigilator_distribution_until_student_hall_distribution_is_complete(): void
    {
        $context = $this->createSlotContext();

        ExamStudent::query()->create([
            'subject_exam_offering_id' => $context['offering']->id,
            'student_number' => '20260001',
            'full_name' => 'طالب أول',
            'student_type' => ExamStudentType::Regular->value,
        ]);

        $this->createRequirement($context['college'], ExamHallType::Large, 0, 0, 1, 0);
        $this->createInvigilators($context['college'], InvigilationRole::Regular, 1);

        $result = app(InvigilatorDistributionService::class)->distributeForFaculty(
            $context['college'],
            Carbon::parse('2026-06-01'),
            Carbon::parse('2026-06-01'),
        );

        $this->assertSame('danger', $result['status']);
        $this->assertSame(__('exam.readiness.reasons.unassigned_students_block_invigilators'), $result['message']);
        $this->assertSame(0, InvigilatorAssignment::query()->count());
        $this->assertSame(1, $result['readiness']['incomplete_slots_count']);
    }

    #[Test]
    public function it_distributes_students_globally_by_college_and_groups_same_time_offerings(): void
    {
        $context = $this->createSlotContext();
        $otherSubject = Subject::query()->create([
            'college_id' => $context['college']->id,
            'department_id' => $context['offering']->subject->department_id,
            'study_level_id' => $context['offering']->subject->study_level_id,
            'name' => 'جبر',
            'is_active' => true,
        ]);
        $otherOffering = SubjectExamOffering::query()->create([
            'subject_id' => $otherSubject->id,
            'academic_year_id' => $context['offering']->academic_year_id,
            'semester_id' => $context['offering']->semester_id,
            'exam_date' => '2026-06-01',
            'exam_start_time' => '09:00:00',
            'status' => ExamOfferingStatus::Draft->value,
        ]);

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قاعة شاملة',
            'location' => 'المبنى الأول',
            'capacity' => 50,
            'hall_type' => ExamHallType::Large->value,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        foreach ([$context['offering'], $otherOffering] as $offeringIndex => $offering) {
            for ($index = 1; $index <= 5; $index++) {
                ExamStudent::query()->create([
                    'subject_exam_offering_id' => $offering->id,
                    'student_number' => '2026'.$offeringIndex.$index,
                    'full_name' => 'طالب '.$offeringIndex.'-'.$index,
                    'student_type' => ExamStudentType::Regular->value,
                ]);
            }
        }

        $result = app(ExamHallDistributionService::class)->distributeForFacultyDateRange(
            collegeId: $context['college']->id,
            fromDate: '2026-06-01',
            toDate: '2026-06-01',
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame(2, $result['offerings_count']);
        $this->assertSame(1, $result['slots_count']);
        $this->assertSame(10, $result['assigned_students_count']);
        $this->assertSame(0, $result['unassigned_students_count']);
        $this->assertSame(1, HallAssignment::query()->count());
        $this->assertDatabaseHas('hall_assignment_subjects', [
            'subject_exam_offering_id' => $context['offering']->id,
            'assigned_students_count' => 5,
        ]);
        $this->assertDatabaseHas('hall_assignment_subjects', [
            'subject_exam_offering_id' => $otherOffering->id,
            'assigned_students_count' => 5,
        ]);
        $this->assertDatabaseHas('student_distribution_runs', [
            'college_id' => $context['college']->id,
            'status' => 'success',
            'total_offerings' => 2,
            'total_slots' => 1,
            'total_students' => 10,
            'distributed_students' => 10,
            'unassigned_students' => 0,
        ]);
    }

    #[Test]
    public function it_persists_partial_global_distribution_results_with_issue_details(): void
    {
        $context = $this->createSlotContext();

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قاعة محدودة',
            'location' => 'المبنى الأول',
            'capacity' => 3,
            'hall_type' => ExamHallType::Small->value,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        for ($index = 1; $index <= 5; $index++) {
            ExamStudent::query()->create([
                'subject_exam_offering_id' => $context['offering']->id,
                'student_number' => 'PARTIAL'.$index,
                'full_name' => 'طالب غير مكتمل '.$index,
                'student_type' => ExamStudentType::Regular->value,
            ]);
        }

        $result = app(ExamHallDistributionService::class)->distributeForFacultyDateRange(
            collegeId: $context['college']->id,
            fromDate: '2026-06-01',
            toDate: '2026-06-01',
        );

        $this->assertSame('partial', $result['status']);
        $this->assertSame(3, $result['distributed_students']);
        $this->assertSame(2, $result['unassigned_students']);
        $this->assertSame(2, $result['capacity_shortage']);

        $run = StudentDistributionRun::query()->first();
        $this->assertNotNull($run);
        $this->assertSame('partial', $run->status);
        $this->assertSame(2, $run->unassigned_students);
        $this->assertSame(1, StudentDistributionRunIssue::query()->count());
        $this->assertDatabaseHas('student_distribution_run_issues', [
            'student_distribution_run_id' => $run->id,
            'subject_exam_offering_id' => $context['offering']->id,
            'issue_type' => 'capacity_shortage',
            'affected_students_count' => 2,
        ]);
    }

    #[Test]
    public function it_persists_failed_global_distribution_result_when_no_active_halls_exist(): void
    {
        $context = $this->createSlotContext();

        for ($index = 1; $index <= 4; $index++) {
            ExamStudent::query()->create([
                'subject_exam_offering_id' => $context['offering']->id,
                'student_number' => 'FAILED'.$index,
                'full_name' => 'طالب بلا قاعة '.$index,
                'student_type' => ExamStudentType::Regular->value,
            ]);
        }

        $result = app(ExamHallDistributionService::class)->distributeForFacultyDateRange(
            collegeId: $context['college']->id,
            fromDate: '2026-06-01',
            toDate: '2026-06-01',
        );

        $this->assertSame('failed', $result['status']);
        $this->assertSame(1, $result['total_offerings']);
        $this->assertSame(1, $result['total_slots']);
        $this->assertSame(4, $result['total_students']);
        $this->assertSame(4, $result['unassigned_students']);
        $this->assertSame(4, $result['capacity_shortage']);
        $this->assertCount(1, $result['unassigned_by_slot']);
        $this->assertCount(1, $result['unassigned_by_subject']);

        $run = StudentDistributionRun::query()->first();

        $this->assertNotNull($run);
        $this->assertSame('failed', $run->status);
        $this->assertSame(1, $run->total_slots);
        $this->assertSame(4, $run->capacity_shortage);
        $this->assertDatabaseHas('student_distribution_run_issues', [
            'student_distribution_run_id' => $run->id,
            'issue_type' => 'no_available_halls',
            'affected_students_count' => 4,
        ]);
    }

    #[Test]
    public function global_distribution_results_page_accepts_model_bound_run_parameter(): void
    {
        $context = $this->createSlotContext();
        $this->actingAs(User::factory()->create(['college_id' => $context['college']->id]));

        $run = StudentDistributionRun::query()->create([
            'college_id' => $context['college']->id,
            'from_date' => '2026-06-01',
            'to_date' => '2026-06-01',
            'status' => 'success',
            'executed_at' => now(),
        ]);

        $page = app(GlobalDistributionResults::class);
        $page->mount($run);

        $this->assertTrue($page->run?->is($run));
    }

    #[Test]
    public function it_uses_workload_reduction_percentage_when_calculating_effective_max_assignments(): void
    {
        $context = $this->createSlotContext();
        $this->createUsedHall($context['college'], 'قاعة 1', ExamHallType::Large);
        $this->createUsedHall($context['college'], 'قاعة 2', ExamHallType::Large);
        $this->createRequirement($context['college'], ExamHallType::Large, 0, 0, 1, 0);

        InvigilatorDistributionSetting::query()->create([
            'college_id' => $context['college']->id,
            'default_max_assignments_per_invigilator' => 2,
            'allow_multiple_assignments_per_day' => true,
            'max_assignments_per_day' => 2,
            'distribution_pattern' => 'balanced',
            'day_preference' => 'balanced',
        ]);

        Invigilator::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'مراقب مخفض',
            'phone' => '0988000001',
            'staff_category' => StaffCategory::Doctor->value,
            'invigilation_role' => InvigilationRole::Regular->value,
            'workload_reduction_percentage' => 50,
            'is_active' => true,
        ]);

        $result = app(InvigilatorDistributionService::class)->distributeForSlot($context['college'], '2026-06-01', '09:00:00');

        $this->assertSame('partial', $result['status']);
        $this->assertSame(1, InvigilatorAssignment::query()->count());
        $this->assertSame(1, $result['shortage_count']);
    }

    #[Test]
    public function it_preserves_manual_assignments_when_rerunning_distribution(): void
    {
        $context = $this->createSlotContext();
        $hall = $this->createUsedHall($context['college'], 'قاعة يدوية', ExamHallType::Small);
        $this->createRequirement($context['college'], ExamHallType::Small, 0, 0, 1, 0);

        $manualInvigilator = Invigilator::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'مراقب يدوي',
            'phone' => '0988000002',
            'staff_category' => StaffCategory::Doctor->value,
            'invigilation_role' => InvigilationRole::Regular->value,
            'is_active' => true,
        ]);

        $automaticInvigilator = Invigilator::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'مراقب آلي',
            'phone' => '0988000003',
            'staff_category' => StaffCategory::Doctor->value,
            'invigilation_role' => InvigilationRole::Regular->value,
            'is_active' => true,
        ]);

        InvigilatorAssignment::query()->create([
            'college_id' => $context['college']->id,
            'exam_date' => '2026-06-01',
            'start_time' => '09:00:00',
            'exam_hall_id' => $hall->id,
            'invigilator_id' => $manualInvigilator->id,
            'invigilation_role' => InvigilationRole::Regular->value,
            'assignment_status' => InvigilatorAssignmentStatus::Manual->value,
        ]);

        app(InvigilatorDistributionService::class)->distributeForSlot($context['college'], '2026-06-01', '09:00:00');

        $this->assertDatabaseHas('invigilator_assignments', [
            'invigilator_id' => $manualInvigilator->id,
            'assignment_status' => InvigilatorAssignmentStatus::Manual->value,
        ]);
        $this->assertDatabaseMissing('invigilator_assignments', [
            'invigilator_id' => $automaticInvigilator->id,
            'assignment_status' => InvigilatorAssignmentStatus::Assigned->value,
        ]);
        $this->assertSame(1, InvigilatorAssignment::query()->count());
    }

    #[Test]
    public function faculty_admin_cannot_access_another_college_invigilator(): void
    {
        $college = College::query()->create(['name' => 'كلية الهندسة', 'is_active' => true]);
        $otherCollege = College::query()->create(['name' => 'كلية العلوم', 'is_active' => true]);
        $user = User::factory()->create(['college_id' => $college->id]);
        Role::findOrCreate(RoleNames::ADMIN, 'web');
        Permission::findOrCreate(ShieldPermission::resource('view', 'Invigilator'), 'web');
        $user->assignRole(RoleNames::ADMIN);
        $user->givePermissionTo(ShieldPermission::resource('view', 'Invigilator'));

        $invigilator = Invigilator::query()->create([
            'college_id' => $otherCollege->id,
            'name' => 'مراقب خارج النطاق',
            'phone' => '0988000004',
            'staff_category' => StaffCategory::Doctor->value,
            'invigilation_role' => InvigilationRole::Regular->value,
            'is_active' => true,
        ]);

        $this->assertFalse($user->can('view', $invigilator));
    }

    #[Test]
    public function invigilator_seeder_creates_repeatable_invigilators_with_required_unique_phones(): void
    {
        $college = College::query()->create(['name' => 'كلية الهندسة', 'code' => 'ENG', 'is_active' => true]);

        $this->seed(InvigilatorSeeder::class);
        $this->seed(InvigilatorSeeder::class);

        $this->assertSame(35, Invigilator::query()->where('college_id', $college->id)->count());
        $this->assertSame(0, Invigilator::query()->where('college_id', $college->id)->whereNull('phone')->count());

        $duplicatePhones = Invigilator::query()
            ->where('college_id', $college->id)
            ->select('phone')
            ->groupBy('phone')
            ->havingRaw('count(*) > 1')
            ->exists();

        $this->assertFalse($duplicatePhones);
        $this->assertDatabaseHas('invigilator_distribution_settings', ['college_id' => $college->id]);
        $this->assertSame(3, InvigilatorHallRequirement::query()->where('college_id', $college->id)->count());
    }

    protected function createSlotContext(): array
    {
        $college = College::query()->create(['name' => 'كلية الهندسة', 'is_active' => true]);
        $department = Department::query()->create(['college_id' => $college->id, 'name' => 'قسم المعلوماتية', 'is_active' => true]);
        $studyLevel = StudyLevel::query()->create(['name' => 'السنة الثالثة', 'is_active' => true]);
        $academicYear = AcademicYear::query()->create(['name' => '2025-2026', 'is_active' => true, 'is_current' => true]);
        $semester = Semester::query()->create(['name' => 'الفصل الثاني', 'is_active' => true]);
        $subject = Subject::query()->create([
            'college_id' => $college->id,
            'department_id' => $department->id,
            'study_level_id' => $studyLevel->id,
            'name' => 'تحليل',
            'is_active' => true,
        ]);

        $offering = SubjectExamOffering::query()->create([
            'subject_id' => $subject->id,
            'academic_year_id' => $academicYear->id,
            'semester_id' => $semester->id,
            'exam_date' => '2026-06-01',
            'exam_start_time' => '09:00:00',
            'status' => ExamOfferingStatus::Draft->value,
        ]);

        return compact('college', 'offering');
    }

    protected function createUsedHall(College $college, string $name, ExamHallType $type): ExamHall
    {
        $hall = ExamHall::query()->create([
            'college_id' => $college->id,
            'name' => $name,
            'location' => 'المبنى الأول',
            'capacity' => 80,
            'hall_type' => $type->value,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        HallAssignment::query()->create([
            'exam_hall_id' => $hall->id,
            'exam_date' => '2026-06-01',
            'exam_start_time' => '09:00:00',
            'college_id' => $college->id,
            'total_capacity' => 80,
            'assigned_students_count' => 20,
            'remaining_capacity' => 60,
        ]);

        return $hall;
    }

    protected function createRequirement(College $college, ExamHallType $type, int $heads, int $secretaries, int $regulars, int $reserves): void
    {
        InvigilatorHallRequirement::query()->create([
            'college_id' => $college->id,
            'hall_type' => $type->value,
            'hall_head_count' => $heads,
            'secretary_count' => $secretaries,
            'regular_count' => $regulars,
            'reserve_count' => $reserves,
        ]);
    }

    protected function createInvigilators(College $college, InvigilationRole $role, int $count): void
    {
        $roleOffset = match ($role) {
            InvigilationRole::HallHead => 100,
            InvigilationRole::Secretary => 200,
            InvigilationRole::Regular => 300,
            InvigilationRole::Reserve => 400,
        };

        for ($index = 1; $index <= $count; $index++) {
            Invigilator::query()->create([
                'college_id' => $college->id,
                'name' => $role->value.'-'.$index,
                'phone' => '0977'.str_pad((string) ($college->id * 1000 + $roleOffset + $index), 6, '0', STR_PAD_LEFT),
                'staff_category' => StaffCategory::Doctor->value,
                'invigilation_role' => $role->value,
                'is_active' => true,
            ]);
        }
    }
}
