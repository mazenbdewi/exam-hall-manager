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
use App\Models\SubjectExamRoster;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\ExamHallDistributionService;
use App\Services\InvigilatorDistributionPdfService;
use App\Services\InvigilatorDistributionService;
use App\Support\RoleNames;
use App\Support\ShieldPermission;
use Carbon\Carbon;
use Database\Seeders\InvigilatorSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\StreamedResponse;
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
                    'الدور الأساسي',
                    'أدوار إضافية ممكنة',
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
                    ['د. أحمد', 'دكتور', '0999', 'رئيس قاعة', '', 4, '25%', 'نعم', null, 'الأيام الأولى', 'نعم', 'محدث'],
                    ['سارة', 'موظف إداري', '0998', 'مراقب عادي', 'أمين سر', null, null, null, null, 'استخدام الإعداد العام', 'yes', null],
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
        $this->assertSame([InvigilationRole::HallHead->value], $updated->eligible_roles);
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
    public function it_imports_numeric_phone_values_as_text_from_excel(): void
    {
        $college = College::query()->create(['name' => 'كلية الهندسة', 'is_active' => true]);
        $path = 'testing/invigilators-numeric-phone.xlsx';

        Excel::store(new class implements FromArray, WithHeadings
        {
            public function headings(): array
            {
                return ['اسم المراقب', 'نوع الكادر', 'رقم الهاتف', 'نوع المراقبة'];
            }

            public function array(): array
            {
                return [
                    ['د. أحمد', 'دكتور', 991000001, 'رئيس قاعة'],
                ];
            }
        }, $path, 'local');

        $import = new InvigilatorsImport($college);
        Excel::import($import, Storage::disk('local')->path($path));

        $this->assertSame(1, $import->getImportedCount());
        $this->assertDatabaseHas('invigilators', [
            'college_id' => $college->id,
            'phone' => '991000001',
        ]);
    }

    #[Test]
    public function it_can_import_invigilator_college_from_the_excel_file(): void
    {
        $engineering = College::query()->create(['name' => 'كلية الهندسة', 'code' => 'ENG', 'is_active' => true]);
        $science = College::query()->create(['name' => 'كلية العلوم', 'code' => 'SCI', 'is_active' => true]);

        $path = 'testing/invigilators-with-college.xlsx';
        Excel::store(new class implements FromArray, WithHeadings
        {
            public function headings(): array
            {
                return [
                    'اسم المراقب',
                    'الكلية',
                    'رقم الهاتف',
                    'نوع الكادر',
                    'نوع المراقبة',
                    'الأدوار الممكنة',
                ];
            }

            public function array(): array
            {
                return [
                    ['مراقب علوم', 'كلية العلوم', '0997', 'دكتور', 'مراقب عادي', 'أمين سر، مراقب عادي'],
                    ['مراقب هندسة', 'ENG', '0996', 'موظف إداري', 'أمين سر', 'أمين سر'],
                ];
            }
        }, $path, 'local');

        $import = new InvigilatorsImport($engineering, allowRowCollege: true);
        Excel::import($import, Storage::disk('local')->path($path));

        $this->assertSame(2, $import->getImportedCount());
        $this->assertDatabaseHas('invigilators', [
            'college_id' => $science->id,
            'name' => 'مراقب علوم',
            'phone' => '0997',
        ]);
        $this->assertSame(
            [InvigilationRole::Regular->value, InvigilationRole::Secretary->value],
            Invigilator::query()->where('phone', '0997')->firstOrFail()->eligible_roles,
        );
        $this->assertDatabaseHas('invigilators', [
            'college_id' => $engineering->id,
            'name' => 'مراقب هندسة',
            'phone' => '0996',
        ]);
    }

    #[Test]
    public function invigilator_template_includes_personal_distribution_columns(): void
    {
        $export = new InvigilatorsTemplateExport;

        $this->assertSame([
            'اسم المراقب',
            'الكلية',
            'رقم الهاتف',
            'نوع الكادر',
            'الدور الأساسي',
            'أدوار إضافية ممكنة',
            'الحد الأقصى للمراقبات',
            'الحد الأقصى في اليوم',
            'السماح بأكثر من مراقبة في اليوم',
            'تفضيل الأيام',
            'نسبة تخفيض المراقبات',
            'فعال',
            'ملاحظات',
        ], $export->headings());

        $this->assertSame('كلية الهندسة', $export->collection()->first()[1]);
        $this->assertSame('', $export->collection()->first()[5]);
        $this->assertSame('لا', $export->collection()->first()[8]);
        $this->assertSame('متوازن', $export->collection()->first()[9]);
        $this->assertSame(
            ['C' => NumberFormat::FORMAT_TEXT],
            $export->columnFormats(),
        );
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
    public function eligible_roles_allow_secretary_to_serve_as_regular_without_general_fallback(): void
    {
        $context = $this->createSlotContext();
        $hall = $this->createUsedHall($context['college'], 'قاعة تحتاج مراقب مرن', ExamHallType::Small);

        InvigilatorDistributionSetting::query()->create([
            'college_id' => $context['college']->id,
            'default_max_assignments_per_invigilator' => 10,
            'allow_multiple_assignments_per_day' => true,
            'allow_role_fallback' => false,
            'max_assignments_per_day' => 3,
            'distribution_pattern' => 'balanced',
            'day_preference' => 'balanced',
        ]);

        $this->createRequirement($context['college'], ExamHallType::Small, 0, 0, 1, 0);
        $secretary = Invigilator::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'أمين سر مرن',
            'phone' => '0997000001',
            'staff_category' => StaffCategory::Doctor->value,
            'invigilation_role' => InvigilationRole::Secretary->value,
            'eligible_roles' => [InvigilationRole::Secretary->value, InvigilationRole::Regular->value],
            'is_active' => true,
        ]);

        $result = app(InvigilatorDistributionService::class)->distributeForSlot($context['college'], '2026-06-01', '09:00:00');
        $assignment = InvigilatorAssignment::query()->first();

        $this->assertSame('success', $result['status']);
        $this->assertSame($hall->id, $assignment?->exam_hall_id);
        $this->assertSame($secretary->id, $assignment?->invigilator_id);
        $this->assertSame(InvigilationRole::Regular, $assignment?->invigilation_role);
        $this->assertSame(0, InvigilatorUnassignedRequirement::query()->count());
    }

    #[Test]
    public function distribution_prefers_primary_role_before_secondary_eligible_roles(): void
    {
        $context = $this->createSlotContext();
        $this->createUsedHall($context['college'], 'قاعة تفضيل الدور الأساسي', ExamHallType::Small);

        InvigilatorDistributionSetting::query()->create([
            'college_id' => $context['college']->id,
            'default_max_assignments_per_invigilator' => 10,
            'allow_multiple_assignments_per_day' => true,
            'allow_role_fallback' => false,
            'max_assignments_per_day' => 3,
            'distribution_pattern' => 'balanced',
            'day_preference' => 'balanced',
        ]);

        $this->createRequirement($context['college'], ExamHallType::Small, 0, 0, 1, 0);
        $secretary = Invigilator::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'أمين سر مرن',
            'phone' => '0997000002',
            'staff_category' => StaffCategory::Doctor->value,
            'invigilation_role' => InvigilationRole::Secretary->value,
            'eligible_roles' => [InvigilationRole::Secretary->value, InvigilationRole::Regular->value],
            'is_active' => true,
        ]);
        $regular = Invigilator::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'مراقب أساسي',
            'phone' => '0997000003',
            'staff_category' => StaffCategory::Doctor->value,
            'invigilation_role' => InvigilationRole::Regular->value,
            'eligible_roles' => [InvigilationRole::Regular->value],
            'is_active' => true,
        ]);

        app(InvigilatorDistributionService::class)->distributeForSlot($context['college'], '2026-06-01', '09:00:00');
        $assignment = InvigilatorAssignment::query()->first();

        $this->assertSame($regular->id, $assignment?->invigilator_id);
        $this->assertNotSame($secretary->id, $assignment?->invigilator_id);
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
    public function summary_diagnosis_and_shortage_pdf_use_the_same_computed_shortage_metrics(): void
    {
        $context = $this->createSlotContext();
        $this->createUsedHall($context['college'], 'قاعة نقص أولى', ExamHallType::Small);
        $this->createUsedHall($context['college'], 'قاعة نقص ثانية', ExamHallType::Small);
        $this->createRequirement($context['college'], ExamHallType::Small, 0, 1, 0, 0);

        InvigilatorDistributionSetting::query()->create([
            'college_id' => $context['college']->id,
            'default_max_assignments_per_invigilator' => 10,
            'allow_multiple_assignments_per_day' => true,
            'max_assignments_per_day' => 3,
            'distribution_pattern' => 'balanced',
            'day_preference' => 'balanced',
        ]);

        $summary = app(InvigilatorDistributionService::class)->getSummary($context['college'], null, null, '2026-06-01', '2026-06-01');
        $secretaryShortage = $summary['shortage_by_role'][InvigilationRole::Secretary->value];
        $html = view('pdf.invigilator-distribution-shortage', [
            'summary' => $summary,
            'systemSetting' => SystemSetting::current(),
            'logoDataUri' => null,
        ])->render();

        $this->assertSame(2, $secretaryShortage['required_count']);
        $this->assertSame(0, $secretaryShortage['assigned_count']);
        $this->assertSame(2, $secretaryShortage['shortage_count']);
        $this->assertSame(2, $secretaryShortage['recommended_additional_observers_count']);
        $this->assertSame(2, $summary['shortage_count']);
        $this->assertCount(2, $summary['shortages']);
        $this->assertStringContainsString('يوجد 2 مهمة غير مغطاة من نوع أمين سر', $summary['diagnosis'][0]['message']);
        $this->assertStringContainsString(__('exam.reports.invigilator_shortage_report_title'), $html);
        $this->assertStringContainsString(__('exam.fields.missing_assignments_count'), $html);
        $this->assertStringContainsString(__('exam.fields.recommended_additional_observers_count'), $html);
        $this->assertStringContainsString(__('exam.reports.shortage_by_slot'), $html);
        $this->assertStringNotContainsString('تقرير النقص في المراقبين', $html);
        $this->assertStringContainsString('قاعة نقص أولى', $html);
        $this->assertStringContainsString('قاعة نقص ثانية', $html);
    }

    #[Test]
    public function invigilator_pdf_service_splits_large_html_before_sending_it_to_mpdf(): void
    {
        $service = app(InvigilatorDistributionPdfService::class);
        $method = new \ReflectionMethod($service, 'htmlChunks');
        $method->setAccessible(true);

        $html = '<html><body><table>'
            .str_repeat('<tr><td>سطر تقرير كبير لاختبار تقسيم HTML قبل mPDF</td></tr>', 12000)
            .'</table></body></html>';

        $chunks = $method->invoke($service, $html, 100_000);

        $this->assertGreaterThan(1, count($chunks));
        $this->assertSame($html, implode('', $chunks));

        foreach (array_slice($chunks, 0, -1) as $chunk) {
            $this->assertLessThanOrEqual(100_000, strlen($chunk));
        }
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
        $this->assertStringContainsString('مهام غير مغطاة', $html);
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
    public function reserve_invigilator_is_not_assigned_to_active_hall_role_even_if_eligible_roles_include_it(): void
    {
        $context = $this->createSlotContext();
        $hall = $this->createUsedHall($context['college'], 'قاعة لا تقبل الاحتياط كرئيس', ExamHallType::Large);

        InvigilatorDistributionSetting::query()->create([
            'college_id' => $context['college']->id,
            'default_max_assignments_per_invigilator' => 10,
            'allow_multiple_assignments_per_day' => true,
            'allow_role_fallback' => true,
            'max_assignments_per_day' => 3,
            'distribution_pattern' => 'balanced',
            'day_preference' => 'balanced',
        ]);

        $this->createRequirement($context['college'], ExamHallType::Large, 1, 0, 0, 0);

        Invigilator::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'مراقب احتياط غير صالح كرئيس',
            'phone' => '0988111101',
            'staff_category' => StaffCategory::Doctor->value,
            'invigilation_role' => InvigilationRole::Reserve->value,
            'eligible_roles' => [InvigilationRole::Reserve->value, InvigilationRole::HallHead->value],
            'is_active' => true,
        ]);

        $result = app(InvigilatorDistributionService::class)->distributeForSlot($context['college'], '2026-06-01', '09:00:00');

        $this->assertSame('partial', $result['status']);
        $this->assertSame(0, InvigilatorAssignment::query()->count());
        $this->assertDatabaseHas('invigilator_unassigned_requirements', [
            'exam_hall_id' => $hall->id,
            'invigilation_role' => InvigilationRole::HallHead->value,
            'shortage_count' => 1,
        ]);
    }

    #[Test]
    public function reserve_hall_requirements_are_reported_as_shortage_without_linking_reserve_to_hall(): void
    {
        $context = $this->createSlotContext();
        $hall = $this->createUsedHall($context['college'], 'قاعة احتياط', ExamHallType::Large);
        $this->createRequirement($context['college'], ExamHallType::Large, 0, 0, 0, 1);
        $this->createInvigilators($context['college'], InvigilationRole::Reserve, 1);

        $result = app(InvigilatorDistributionService::class)->distributeForSlot($context['college'], '2026-06-01', '09:00:00');

        $this->assertSame('partial', $result['status']);
        $this->assertSame(0, InvigilatorAssignment::query()->count());
        $this->assertDatabaseHas('invigilator_unassigned_requirements', [
            'exam_hall_id' => $hall->id,
            'invigilation_role' => InvigilationRole::Reserve->value,
            'required_count' => 1,
            'assigned_count' => 0,
            'shortage_count' => 1,
            'reason' => 'لا يتم ربط مراقبي الاحتياط بالقاعات في التوزيع الآلي. يجب تحويل المراقب إلى دور فعال قبل استخدامه لتغطية نقص.',
        ]);
    }

    #[Test]
    public function final_validation_blocks_saving_when_existing_manual_assignment_uses_reserve_as_active_role(): void
    {
        $context = $this->createSlotContext();
        $hall = $this->createUsedHall($context['college'], 'قاعة فيها تكليف يدوي غير صالح', ExamHallType::Small);
        $this->createRequirement($context['college'], ExamHallType::Small, 1, 0, 1, 0);

        $reserve = Invigilator::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'احتياط يدوي',
            'phone' => '0988111102',
            'staff_category' => StaffCategory::Doctor->value,
            'invigilation_role' => InvigilationRole::Reserve->value,
            'is_active' => true,
        ]);

        $regular = Invigilator::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'مراقب آلي صالح',
            'phone' => '0988111103',
            'staff_category' => StaffCategory::Doctor->value,
            'invigilation_role' => InvigilationRole::Regular->value,
            'is_active' => true,
        ]);

        InvigilatorAssignment::query()->create([
            'college_id' => $context['college']->id,
            'exam_date' => '2026-06-01',
            'start_time' => '09:00:00',
            'exam_hall_id' => $hall->id,
            'invigilator_id' => $reserve->id,
            'invigilation_role' => InvigilationRole::HallHead->value,
            'assignment_status' => InvigilatorAssignmentStatus::Manual->value,
        ]);

        $result = app(InvigilatorDistributionService::class)->distributeForSlot($context['college'], '2026-06-01', '09:00:00');

        $this->assertSame('danger', $result['status']);
        $this->assertStringContainsString('لا يمكن تكليف مراقب الاحتياط', $result['message']);
        $this->assertDatabaseHas('invigilator_assignments', [
            'invigilator_id' => $reserve->id,
            'assignment_status' => InvigilatorAssignmentStatus::Manual->value,
        ]);
        $this->assertDatabaseMissing('invigilator_assignments', [
            'invigilator_id' => $regular->id,
            'assignment_status' => InvigilatorAssignmentStatus::Assigned->value,
        ]);
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
    public function invigilator_personal_allow_multiple_with_daily_max_two_allows_second_assignment(): void
    {
        $context = $this->createSlotContext();
        $hall = $this->createUsedHall($context['college'], 'قاعة 1', ExamHallType::Large);
        $this->createRequirement($context['college'], ExamHallType::Large, 0, 0, 1, 0);

        InvigilatorDistributionSetting::query()->create([
            'college_id' => $context['college']->id,
            'default_max_assignments_per_invigilator' => 10,
            'allow_multiple_assignments_per_day' => false,
            'max_assignments_per_day' => 1,
            'distribution_pattern' => 'balanced',
            'day_preference' => 'balanced',
        ]);

        $invigilator = Invigilator::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'مراقب يسمح له بتكليفين يوميًا',
            'phone' => '0988111004',
            'staff_category' => StaffCategory::Doctor->value,
            'invigilation_role' => InvigilationRole::Regular->value,
            'allow_multiple_assignments_per_day' => true,
            'max_assignments_per_day' => 2,
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

        $this->assertSame('success', $result['status']);
        $this->assertSame(2, InvigilatorAssignment::query()->where('invigilator_id', $invigilator->id)->count());
        $this->assertSame(0, $result['shortage_count']);
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
    public function invigilator_null_personal_settings_fall_back_to_faculty_daily_rules(): void
    {
        $context = $this->createSlotContext();
        $hall = $this->createUsedHall($context['college'], 'قاعة 1', ExamHallType::Large);
        $this->createRequirement($context['college'], ExamHallType::Large, 0, 0, 1, 0);

        InvigilatorDistributionSetting::query()->create([
            'college_id' => $context['college']->id,
            'default_max_assignments_per_invigilator' => 10,
            'allow_multiple_assignments_per_day' => true,
            'max_assignments_per_day' => 2,
            'distribution_pattern' => 'balanced',
            'day_preference' => 'balanced',
        ]);

        $invigilator = Invigilator::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'مراقب يستخدم إعداد الكلية',
            'phone' => '0988111005',
            'staff_category' => StaffCategory::Doctor->value,
            'invigilation_role' => InvigilationRole::Regular->value,
            'allow_multiple_assignments_per_day' => null,
            'max_assignments_per_day' => null,
            'day_preference' => null,
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

        $this->assertSame('success', $result['status']);
        $this->assertSame(2, InvigilatorAssignment::query()->where('invigilator_id', $invigilator->id)->count());
        $this->assertSame(0, $result['shortage_count']);
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
            'issue_type' => 'insufficient_capacity',
            'affected_students_count' => 2,
        ]);
    }

    #[Test]
    public function global_distribution_capacity_failure_stores_detailed_reason_and_logs_it(): void
    {
        Log::spy();
        $context = $this->createSlotContext();

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قاعة صغيرة',
            'location' => 'المبنى الأول',
            'capacity' => 3,
            'hall_type' => ExamHallType::Small->value,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        for ($index = 1; $index <= 5; $index++) {
            ExamStudent::query()->create([
                'subject_exam_offering_id' => $context['offering']->id,
                'student_number' => 'CAP'.$index,
                'full_name' => 'طالب سعة '.$index,
                'student_type' => ExamStudentType::Regular->value,
            ]);
        }

        $result = app(ExamHallDistributionService::class)->distributeForFacultyDateRange(
            collegeId: $context['college']->id,
            fromDate: '2026-06-01',
            toDate: '2026-06-01',
        );

        $this->assertSame('partial', $result['status']);
        $this->assertSame('insufficient_capacity', $result['failure_details'][0]['reason_code']);
        $this->assertSame(5, $result['failure_details'][0]['required_capacity']);
        $this->assertSame(3, $result['failure_details'][0]['available_capacity']);
        $this->assertSame(2, $result['failure_details'][0]['capacity_shortage']);
        $this->assertStringContainsString('عدد الطلاب 5', $result['failure_details'][0]['reason_message']);

        Log::shouldHaveReceived('warning')
            ->with('Global hall distribution failed', \Mockery::on(fn (array $context): bool => $context['reason_code'] === 'insufficient_capacity'
                && $context['students_count'] === 5
                && $context['required_capacity'] === 5
                && $context['available_capacity'] === 3))
            ->once();
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
        $this->assertSame('no_available_halls', $run->fresh()->summary_json['failure_details'][0]['reason_code']);
    }

    #[Test]
    public function global_distribution_reports_drawing_subject_without_suitable_studio(): void
    {
        $context = $this->createSlotContext();
        $context['offering']->subject()->update(['is_drawing_subject' => true]);

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قاعة عادية',
            'location' => 'المبنى الأول',
            'capacity' => 50,
            'hall_type' => ExamHallType::Large->value,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
            'is_drawing_studio' => false,
        ]);

        for ($index = 1; $index <= 2; $index++) {
            ExamStudent::query()->create([
                'subject_exam_offering_id' => $context['offering']->id,
                'student_number' => 'DRAW'.$index,
                'full_name' => 'طالب رسم '.$index,
                'student_type' => ExamStudentType::Regular->value,
            ]);
        }

        $result = app(ExamHallDistributionService::class)->distributeForFacultyDateRange(
            collegeId: $context['college']->id,
            fromDate: '2026-06-01',
            toDate: '2026-06-01',
        );

        $this->assertSame('partial', $result['status']);
        $this->assertSame('hall_type_required_not_available', $result['failure_details'][0]['reason_code']);
        $this->assertStringContainsString('مرسم', $result['failure_details'][0]['reason_message']);
        $this->assertSame(0, $result['failure_details'][0]['available_halls_count']);
    }

    #[Test]
    public function global_distribution_reports_missing_ready_roster_when_students_are_zero(): void
    {
        $context = $this->createSlotContext();

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قاعة متاحة',
            'location' => 'المبنى الأول',
            'capacity' => 50,
            'hall_type' => ExamHallType::Large->value,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        $result = app(ExamHallDistributionService::class)->distributeForFacultyDateRange(
            collegeId: $context['college']->id,
            fromDate: '2026-06-01',
            toDate: '2026-06-01',
        );

        $this->assertSame('failed', $result['status']);
        $this->assertSame('missing_student_roster', $result['failure_details'][0]['reason_code']);
        $this->assertStringContainsString('قائمة طلاب', $result['failure_details'][0]['reason_message']);
    }

    #[Test]
    public function global_distribution_reads_students_from_ready_roster_when_exam_students_are_empty(): void
    {
        $context = $this->createSlotContext();

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قاعة كافية للقائمة',
            'location' => 'المبنى الأول',
            'capacity' => 10,
            'hall_type' => ExamHallType::Large->value,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        $roster = $this->createReadyRosterForOffering($context['offering'], [
            ['SYNC1', 'طالب مزامنة 1', ExamStudentType::Regular->value, true],
            ['SYNC2', 'طالب مزامنة 2', ExamStudentType::Carry->value, true],
            ['SYNC3', 'طالب مزامنة 3', ExamStudentType::Regular->value, true],
        ]);

        $result = app(ExamHallDistributionService::class)->distributeForFacultyDateRange(
            collegeId: $context['college']->id,
            fromDate: '2026-06-01',
            toDate: '2026-06-01',
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame(3, $result['total_students']);
        $this->assertSame(3, $result['distributed_students']);
        $this->assertSame([], $result['failure_details']);
        $this->assertSame(3, ExamStudent::query()->where('subject_exam_offering_id', $context['offering']->id)->count());
        $this->assertSame(3, $roster->rosterStudents()->count());
    }

    #[Test]
    public function global_distribution_reports_no_eligible_students_for_ready_roster_with_only_ineligible_students(): void
    {
        $context = $this->createSlotContext();
        $user = User::factory()->create(['college_id' => $context['college']->id]);
        $user->givePermissionTo(Permission::findOrCreate(ShieldPermission::resource('viewAny', 'SubjectExamOffering'), 'web'));

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قاعة متاحة',
            'location' => 'المبنى الأول',
            'capacity' => 10,
            'hall_type' => ExamHallType::Large->value,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        $roster = $this->createReadyRosterForOffering($context['offering'], [
            ['NOELIG1', 'طالب غير مؤهل 1', ExamStudentType::Regular->value, false],
            ['NOELIG2', 'طالب غير مؤهل 2', ExamStudentType::Carry->value, false],
            ['NOELIG3', 'طالب غير مؤهل 3', ExamStudentType::Regular->value, false],
        ]);

        $result = app(ExamHallDistributionService::class)->distributeForFacultyDateRange(
            collegeId: $context['college']->id,
            fromDate: '2026-06-01',
            toDate: '2026-06-01',
        );

        $detail = $result['failure_details'][0];

        $this->assertSame('failed', $result['status']);
        $this->assertSame('no_eligible_students', $detail['reason_code']);
        $this->assertStringContainsString('لا يوجد طلاب مؤهلون', $detail['reason_message']);
        $this->assertSame($roster->id, $detail['roster_id']);
        $this->assertSame(3, $detail['roster_students_count_raw']);
        $this->assertSame(0, $detail['eligible_students_count']);
        $this->assertSame(0, $detail['students_count_after_filters']);
        $this->assertNotSame('zero_students', $detail['reason_code']);

        $run = StudentDistributionRun::query()->latest('id')->firstOrFail();
        Filament::setCurrentPanel(Filament::getPanel('adminpanel'));

        Livewire::actingAs($user)
            ->test(GlobalDistributionResults::class, ['run' => $run])
            ->assertSee('رقم القائمة')
            ->assertSee((string) $roster->id)
            ->assertSee('طلاب القائمة فعليًا')
            ->assertSee('القائمة تحتوي 3 طالب')
            ->assertSee('كل الطلاب داخل القائمة غير مؤهلين للتوزيع');
    }

    #[Test]
    public function global_distribution_reports_roster_filter_mismatch_and_logs_student_resolution_context(): void
    {
        Log::spy();
        $context = $this->createSlotContext();
        $differentAcademicYear = AcademicYear::query()->create(['name' => '2024-2025', 'is_active' => true]);

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قاعة متاحة',
            'location' => 'المبنى الأول',
            'capacity' => 10,
            'hall_type' => ExamHallType::Large->value,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        $roster = $this->createReadyRosterForOffering($context['offering'], [
            ['YEAR1', 'طالب سنة مختلفة 1', ExamStudentType::Regular->value, true],
            ['YEAR2', 'طالب سنة مختلفة 2', ExamStudentType::Regular->value, true],
        ], [
            'academic_year_id' => $differentAcademicYear->id,
        ]);

        $result = app(ExamHallDistributionService::class)->distributeForFacultyDateRange(
            collegeId: $context['college']->id,
            fromDate: '2026-06-01',
            toDate: '2026-06-01',
        );

        $detail = $result['failure_details'][0];

        $this->assertSame('failed', $result['status']);
        $this->assertSame('roster_filter_mismatch', $detail['reason_code']);
        $this->assertStringContainsString('لا تطابق شروط التوزيع', $detail['reason_message']);
        $this->assertSame($roster->id, $detail['roster_id']);
        $this->assertSame(2, $detail['roster_students_count_raw']);
        $this->assertSame(2, $detail['eligible_students_count']);
        $this->assertSame(0, $detail['students_count_after_filters']);
        $this->assertContains('القائمة تابعة لعام دراسي مختلف عن العرض الامتحاني.', $detail['student_resolution_exclusion_reasons']);

        Log::shouldHaveReceived('warning')
            ->with('Distribution failed because students were not resolved', \Mockery::on(fn (array $context): bool => ($context['roster_id'] ?? null) === $roster->id
                && ($context['roster_students_count_raw'] ?? null) === 2
                && ($context['eligible_students_count'] ?? null) === 2
                && ($context['students_count_after_filters'] ?? null) === 0
                && ($context['filters_applied']['academic_year_id'] ?? null) === $context['academic_year_id']))
            ->once();
    }

    #[Test]
    public function global_distribution_does_not_trust_stale_roster_metadata_student_counts(): void
    {
        $context = $this->createSlotContext();

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قاعة متاحة',
            'location' => 'المبنى الأول',
            'capacity' => 10,
            'hall_type' => ExamHallType::Large->value,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        $roster = $this->createReadyRosterForOffering($context['offering'], [], [
            'metadata' => ['students_count' => 455],
        ]);

        $result = app(ExamHallDistributionService::class)->distributeForFacultyDateRange(
            collegeId: $context['college']->id,
            fromDate: '2026-06-01',
            toDate: '2026-06-01',
        );

        $detail = $result['failure_details'][0];

        $this->assertSame('failed', $result['status']);
        $this->assertSame('zero_students', $detail['reason_code']);
        $this->assertSame($roster->id, $detail['roster_id']);
        $this->assertSame(0, $detail['roster_students_count_raw']);
        $this->assertSame(0, $detail['eligible_students_count']);
        $this->assertSame(0, $detail['students_count_after_filters']);
        $this->assertSame(0, ExamStudent::query()->where('subject_exam_offering_id', $context['offering']->id)->count());
    }

    #[Test]
    public function global_distribution_results_page_shows_saved_failure_details_after_refresh(): void
    {
        $context = $this->createSlotContext();
        $user = User::factory()->create(['college_id' => $context['college']->id]);
        $user->givePermissionTo(Permission::findOrCreate(ShieldPermission::resource('viewAny', 'SubjectExamOffering'), 'web'));

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
                'student_number' => 'PAGE'.$index,
                'full_name' => 'طالب صفحة '.$index,
                'student_type' => ExamStudentType::Regular->value,
            ]);
        }

        app(ExamHallDistributionService::class)->distributeForFacultyDateRange(
            collegeId: $context['college']->id,
            fromDate: '2026-06-01',
            toDate: '2026-06-01',
        );

        $run = StudentDistributionRun::query()->latest('id')->firstOrFail();
        Filament::setCurrentPanel(Filament::getPanel('adminpanel'));

        Livewire::actingAs($user)
            ->test(GlobalDistributionResults::class, ['run' => $run])
            ->assertSee('تفاصيل سبب فشل التوزيع')
            ->assertSee('insufficient_capacity')
            ->assertSee('عدد الطلاب 5')
            ->assertSee('السعة المتاحة في القاعات المناسبة هي 3')
            ->assertSee('النقص: 2')
            ->assertSee('فتح المادة')
            ->assertDontSee('فشل التوزيع بسبب مشكلة في البيانات أو القاعات');
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
    public function successful_global_distribution_does_not_create_zero_count_problem_items(): void
    {
        $context = $this->createSlotContext();

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قاعة كافية',
            'location' => 'المبنى الأول',
            'capacity' => 20,
            'hall_type' => ExamHallType::Large->value,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        foreach (range(1, 4) as $index) {
            ExamStudent::query()->create([
                'subject_exam_offering_id' => $context['offering']->id,
                'student_number' => 'SUCCESS'.$index,
                'full_name' => 'طالب موزع '.$index,
                'student_type' => ExamStudentType::Regular->value,
            ]);
        }

        SubjectExamOffering::query()->create([
            'subject_id' => $context['offering']->subject_id,
            'academic_year_id' => $context['offering']->academic_year_id,
            'semester_id' => $context['offering']->semester_id,
            'exam_date' => '2026-06-02',
            'exam_start_time' => '09:00:00',
            'status' => ExamOfferingStatus::Draft->value,
        ]);

        $result = app(ExamHallDistributionService::class)->distributeForFacultyDateRange(
            collegeId: $context['college']->id,
            fromDate: '2026-06-01',
            toDate: '2026-06-02',
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame(0, $result['unassigned_students']);
        $this->assertSame(0, $result['capacity_shortage']);
        $this->assertSame([], $result['issues']);
        $this->assertSame([], $result['unassigned_by_slot']);
        $this->assertSame([], $result['unassigned_by_subject']);
        $this->assertSame(0, StudentDistributionRunIssue::query()->count());
    }

    #[Test]
    public function global_distribution_results_page_hides_legacy_zero_count_issues(): void
    {
        $context = $this->createSlotContext();
        $user = User::factory()->create(['college_id' => $context['college']->id]);
        $user->givePermissionTo(Permission::findOrCreate(ShieldPermission::resource('viewAny', 'SubjectExamOffering'), 'web'));

        $run = StudentDistributionRun::query()->create([
            'college_id' => $context['college']->id,
            'from_date' => '2026-06-01',
            'to_date' => '2026-06-01',
            'status' => 'success',
            'total_offerings' => 1,
            'total_slots' => 1,
            'total_students' => 4,
            'distributed_students' => 4,
            'unassigned_students' => 0,
            'total_capacity' => 20,
            'used_halls' => 1,
            'capacity_shortage' => 0,
            'executed_by' => $user->id,
            'executed_at' => now(),
            'summary_json' => [
                'slots' => [[
                    'status' => 'success',
                    'exam_date' => '2026-06-01',
                    'exam_start_time' => '09:00:00',
                    'students_count' => 4,
                    'used_halls_count' => 1,
                    'unassigned_students_count' => 0,
                    'capacity_shortage' => 0,
                    'message' => 'لا توجد قاعات متاحة',
                ]],
                'unassigned_by_slot' => [[
                    'exam_date' => '2026-06-01',
                    'start_time' => '09:00:00',
                    'unassigned_count' => 0,
                    'reason' => 'لا توجد قاعات متاحة',
                    'capacity_shortage' => 0,
                ]],
                'unassigned_by_subject' => [[
                    'subject_name' => 'تحليل',
                    'exam_date' => '2026-06-01',
                    'start_time' => '09:00:00',
                    'unassigned_count' => 0,
                    'reason' => 'لا توجد قاعات متاحة',
                ]],
            ],
        ]);

        StudentDistributionRunIssue::query()->create([
            'student_distribution_run_id' => $run->id,
            'exam_date' => '2026-06-01',
            'start_time' => '09:00:00',
            'issue_type' => 'no_available_halls',
            'message' => 'لا توجد قاعات متاحة',
            'affected_students_count' => 0,
            'payload_json' => [],
        ]);

        Filament::setCurrentPanel(Filament::getPanel('adminpanel'));

        Livewire::actingAs($user)
            ->test(GlobalDistributionResults::class, ['run' => $run])
            ->assertSee('كل الطلاب تم توزيعهم بنجاح')
            ->assertSee('ولا توجد مشاكل مسجلة.')
            ->assertSee('ملخص المواعيد الامتحانية')
            ->assertSee('لا توجد مشاكل مسجلة ضمن هذا التصنيف.')
            ->assertDontSee('لا توجد قاعات متاحة');
    }

    #[Test]
    public function global_distribution_results_page_disables_unassigned_exports_when_saved_validation_has_zero_unassigned(): void
    {
        $context = $this->createSlotContext();
        $user = User::factory()->create(['college_id' => $context['college']->id]);
        $user->givePermissionTo(Permission::findOrCreate(ShieldPermission::resource('viewAny', 'SubjectExamOffering'), 'web'));

        $run = StudentDistributionRun::query()->create([
            'college_id' => $context['college']->id,
            'from_date' => '2026-06-01',
            'to_date' => '2026-06-01',
            'status' => 'success',
            'total_offerings' => 1,
            'total_slots' => 1,
            'total_students' => 4,
            'distributed_students' => 4,
            'unassigned_students' => 0,
            'total_capacity' => 20,
            'used_halls' => 1,
            'capacity_shortage' => 0,
            'executed_by' => $user->id,
            'executed_at' => now(),
            'summary_json' => [
                'validation' => [
                    'expected_students' => 4,
                    'assigned_students' => 4,
                    'unassigned_students' => 0,
                    'used_halls_count' => 1,
                    'used_hall_capacity' => 20,
                    'remaining_capacity' => 16,
                    'data_source' => 'student_distribution_runs.summary_json.validation',
                    'unassigned_students_list' => [],
                ],
            ],
        ]);

        Filament::setCurrentPanel(Filament::getPanel('adminpanel'));

        Livewire::actingAs($user)
            ->test(GlobalDistributionResults::class, ['run' => $run])
            ->assertSee(__('exam.global_hall_distribution.unassigned_report_not_needed'))
            ->assertSeeHtml('disabled');

        $page = app(GlobalDistributionResults::class);
        $page->mount($run);

        $this->assertSame(0, $page->savedUnassignedStudentsCount());
        $this->assertFalse($page->canExportUnassignedReports());
        $this->assertNull($page->exportUnassignedPdf());
        $this->assertNull($page->exportUnassignedExcel());
    }

    #[Test]
    public function global_distribution_results_page_keeps_unassigned_exports_enabled_when_saved_validation_has_students(): void
    {
        $context = $this->createSlotContext();
        $user = User::factory()->create(['college_id' => $context['college']->id]);
        $user->givePermissionTo(Permission::findOrCreate(ShieldPermission::resource('viewAny', 'SubjectExamOffering'), 'web'));

        $run = StudentDistributionRun::query()->create([
            'college_id' => $context['college']->id,
            'from_date' => '2026-06-01',
            'to_date' => '2026-06-01',
            'status' => 'partial',
            'total_offerings' => 1,
            'total_slots' => 1,
            'total_students' => 10,
            'distributed_students' => 8,
            'unassigned_students' => 2,
            'total_capacity' => 8,
            'used_halls' => 1,
            'capacity_shortage' => 2,
            'executed_by' => $user->id,
            'executed_at' => now(),
            'summary_json' => [
                'validation' => [
                    'expected_students' => 10,
                    'assigned_students' => 8,
                    'unassigned_students' => 2,
                    'used_halls_count' => 1,
                    'used_hall_capacity' => 8,
                    'remaining_capacity' => 0,
                    'data_source' => 'student_distribution_runs.summary_json.validation',
                    'unassigned_students_list' => [
                        ['student_number' => '2026001', 'full_name' => 'طالب 1', 'student_type_label' => 'مستجد', 'subject_name' => 'تحليل', 'exam_date' => '2026-06-01', 'start_time' => '09:00', 'reason' => 'يوجد طلاب غير موزعين يحتاجون إلى مراجعة'],
                        ['student_number' => '2026002', 'full_name' => 'طالب 2', 'student_type_label' => 'مستجد', 'subject_name' => 'تحليل', 'exam_date' => '2026-06-01', 'start_time' => '09:00', 'reason' => 'يوجد طلاب غير موزعين يحتاجون إلى مراجعة'],
                    ],
                ],
            ],
        ]);

        Filament::setCurrentPanel(Filament::getPanel('adminpanel'));

        Livewire::actingAs($user)
            ->test(GlobalDistributionResults::class, ['run' => $run])
            ->assertSee(__('exam.actions.export_unassigned_students_pdf'))
            ->assertSee(__('exam.actions.export_unassigned_students_excel'))
            ->assertSee('(2)');

        $page = app(GlobalDistributionResults::class);
        $page->mount($run);

        $this->assertSame(2, $page->savedUnassignedStudentsCount());
        $this->assertTrue($page->canExportUnassignedReports());
        $this->assertInstanceOf(StreamedResponse::class, $page->exportUnassignedPdf());
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

    protected function createReadyRosterForOffering(SubjectExamOffering $offering, array $students, array $overrides = []): SubjectExamRoster
    {
        $offering->loadMissing('subject');

        $roster = SubjectExamRoster::query()->create([
            'college_id' => $offering->subject->college_id,
            'department_id' => $offering->subject->department_id,
            'subject_id' => $offering->subject_id,
            'academic_year_id' => $offering->academic_year_id,
            'semester_id' => $offering->semester_id,
            'study_level_id' => $offering->subject->study_level_id,
            'name' => 'قائمة اختبار التوزيع',
            'status' => 'ready',
            'source' => 'test',
            ...$overrides,
        ]);

        foreach ($students as [$studentNumber, $fullName, $studentType, $isEligible]) {
            $roster->rosterStudents()->create([
                'student_number' => $studentNumber,
                'original_student_number' => $studentNumber,
                'full_name' => $fullName,
                'student_type' => $studentType,
                'is_eligible' => $isEligible,
            ]);
        }

        return $roster;
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
