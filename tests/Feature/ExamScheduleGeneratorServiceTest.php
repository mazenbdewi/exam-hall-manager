<?php

namespace Tests\Feature;

use App\Enums\ExamOfferingStatus;
use App\Enums\ExamStudentType;
use App\Filament\Pages\ExamScheduleGenerator;
use App\Models\AcademicYear;
use App\Models\College;
use App\Models\Department;
use App\Models\ExamScheduleDraft;
use App\Models\ExamScheduleDraftItem;
use App\Models\ExamStudent;
use App\Models\Semester;
use App\Models\StudyLevel;
use App\Models\Subject;
use App\Models\SubjectExamOffering;
use App\Models\SubjectExamRoster;
use App\Models\User;
use App\Services\ExamScheduleGeneratorService;
use App\Services\RosterStudentNumberPrefixService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ExamScheduleGeneratorServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function generator_uses_roster_students(): void
    {
        $context = $this->createAcademicContext();
        $this->actingAs(User::factory()->create(['college_id' => $context['college']->id]));
        $subject = $this->createSubject($context, 'تحليل رياضي');
        $this->createRoster($context, $subject, [
            ['S-001', 'طالب أول', 'regular'],
            ['S-002', 'طالب ثان', 'carry'],
        ]);

        $draft = app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context));
        $item = $draft->items()->firstOrFail();

        $this->assertSame(ExamScheduleDraft::STATUS_COMPLETED, $draft->status);
        $this->assertSame(2, $item->student_count);
        $this->assertSame(1, $item->regular_count);
        $this->assertSame(1, $item->carry_count);
        $this->assertSame(['S-001', 'S-002'], $item->metadata['student_numbers']);
    }

    #[Test]
    public function failed_generation_does_not_leave_a_completed_or_partial_draft(): void
    {
        $context = $this->createAcademicContext();
        $this->actingAs(User::factory()->create(['college_id' => $context['college']->id]));

        try {
            app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context));
            $this->fail('Expected generation without ready rosters to fail.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('لا توجد قوائم مواد جاهزة', collect($exception->errors())->flatten()->first());
        }

        $this->assertSame(0, ExamScheduleDraft::query()->count());
        $this->assertSame(0, ExamScheduleDraftItem::query()->count());
        $this->assertSame(0, SubjectExamOffering::query()->whereNotNull('exam_schedule_draft_id')->count());
    }

    #[Test]
    public function regenerating_same_scope_replaces_previous_unapproved_draft_inside_transaction(): void
    {
        $context = $this->createAcademicContext();
        $this->actingAs(User::factory()->create(['college_id' => $context['college']->id]));
        $subject = $this->createSubject($context, 'إعادة توليد');
        $this->createRoster($context, $subject, [['S-001', 'طالب أول', 'regular']]);

        $firstDraft = app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context));
        $secondDraft = app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context));

        $this->assertNotSame($firstDraft->id, $secondDraft->id);
        $this->assertDatabaseMissing('exam_schedule_drafts', ['id' => $firstDraft->id]);
        $this->assertSame(1, ExamScheduleDraft::query()
            ->where('faculty_id', $context['college']->id)
            ->where('academic_year_id', $context['academic_year']->id)
            ->where('semester_id', $context['semester']->id)
            ->where('status', '<>', ExamScheduleDraft::STATUS_APPROVED)
            ->count());
        $this->assertSame(1, SubjectExamOffering::query()->where('exam_schedule_draft_id', $secondDraft->id)->count());
    }

    #[Test]
    public function generated_draft_is_materialized_as_visible_draft_offerings(): void
    {
        $context = $this->createAcademicContext();
        $this->actingAs(User::factory()->create(['college_id' => $context['college']->id]));
        $subject = $this->createSubject($context, 'تحليل مرئي');
        $this->createRoster($context, $subject, [
            ['S-001', 'طالب أول', 'regular'],
        ]);

        $draft = app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context));
        $item = $draft->items()->firstOrFail();
        $offering = SubjectExamOffering::query()->where('exam_schedule_draft_id', $draft->id)->firstOrFail();

        $this->assertSame($subject->id, $offering->subject_id);
        $this->assertSame(ExamOfferingStatus::Draft, $offering->status);
        $this->assertSame($item->exam_date?->toDateString(), $offering->exam_date?->toDateString());
        $this->assertSame(substr((string) $item->start_time, 0, 8), substr((string) $offering->exam_start_time, 0, 8));
        $this->assertSame($offering->id, $item->subject_exam_offering_id);
    }

    #[Test]
    public function editing_visible_draft_offering_schedule_updates_the_linked_draft_item(): void
    {
        $context = $this->createAcademicContext();
        $this->actingAs(User::factory()->create(['college_id' => $context['college']->id]));
        $subject = $this->createSubject($context, 'موعد قابل للتعديل');
        $this->createRoster($context, $subject, [
            ['S-001', 'طالب أول', 'regular'],
        ]);

        $draft = app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context));
        $offering = SubjectExamOffering::query()->where('exam_schedule_draft_id', $draft->id)->firstOrFail();

        app(ExamScheduleGeneratorService::class)->updateOfferingSchedule($offering, [
            'exam_date' => '2026-05-06',
            'period_key' => '1',
        ]);

        $item = $draft->items()->firstOrFail()->refresh();
        $offering = $offering->refresh();

        $this->assertSame('2026-05-06', $offering->exam_date?->toDateString());
        $this->assertSame('12:00:00', substr((string) $offering->exam_start_time, 0, 8));
        $this->assertSame('2026-05-06', $item->exam_date?->toDateString());
        $this->assertSame('12:00:00', substr((string) $item->start_time, 0, 8));
        $this->assertSame('14:00:00', substr((string) $item->end_time, 0, 8));
        $this->assertSame('manually_adjusted', $item->status);
    }

    #[Test]
    public function pinned_offering_keeps_its_slot_and_other_subjects_are_scheduled_around_it(): void
    {
        $context = $this->createAcademicContext();
        $this->actingAs(User::factory()->create(['college_id' => $context['college']->id]));
        $pinnedSubject = $this->createSubject($context, 'مادة مثبتة');
        $otherSubject = $this->createSubject($context, 'مادة حول التثبيت');
        $this->createRoster($context, $pinnedSubject, [['S-001', 'طالب أول', 'regular']]);
        $this->createRoster($context, $otherSubject, [['S-002', 'طالب ثان', 'regular']]);
        $pinnedOffering = SubjectExamOffering::query()->create([
            'subject_id' => $pinnedSubject->id,
            'academic_year_id' => $context['academic_year']->id,
            'semester_id' => $context['semester']->id,
            'exam_date' => '2026-05-03',
            'exam_start_time' => '09:00:00',
            'is_pinned' => true,
            'status' => ExamOfferingStatus::Draft->value,
        ]);

        $draft = app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context, [
            'start_date' => '2026-05-03',
            'end_date' => '2026-05-03',
            'periods' => [
                ['name' => 'صباحية', 'start_time' => '09:00', 'end_time' => '11:00', 'period_type' => 'morning'],
                ['name' => 'وسطى', 'start_time' => '12:00', 'end_time' => '14:00', 'period_type' => 'mid_day'],
            ],
        ]));

        $pinnedItem = $draft->items()->where('subject_id', $pinnedSubject->id)->firstOrFail();
        $otherItem = $draft->items()->where('subject_id', $otherSubject->id)->firstOrFail();

        $this->assertSame($pinnedOffering->id, $pinnedItem->subject_exam_offering_id);
        $this->assertTrue((bool) ($pinnedItem->metadata['pinned'] ?? false));
        $this->assertSame('2026-05-03', $pinnedItem->exam_date?->toDateString());
        $this->assertSame('09:00:00', substr((string) $pinnedItem->start_time, 0, 8));
        $this->assertSame('12:00:00', substr((string) $otherItem->start_time, 0, 8));
        $this->assertTrue($pinnedOffering->refresh()->is_pinned);
    }

    #[Test]
    public function pinning_conflicting_offerings_is_rejected(): void
    {
        $context = $this->createAcademicContext();
        $this->actingAs(User::factory()->create(['college_id' => $context['college']->id]));
        $firstSubject = $this->createSubject($context, 'تثبيت أول');
        $secondSubject = $this->createSubject($context, 'تثبيت متعارض');
        $firstOffering = SubjectExamOffering::query()->create([
            'subject_id' => $firstSubject->id,
            'academic_year_id' => $context['academic_year']->id,
            'semester_id' => $context['semester']->id,
            'exam_date' => '2026-05-03',
            'exam_start_time' => '09:00:00',
            'is_pinned' => true,
            'status' => ExamOfferingStatus::Draft->value,
        ]);
        $secondOffering = SubjectExamOffering::query()->create([
            'subject_id' => $secondSubject->id,
            'academic_year_id' => $context['academic_year']->id,
            'semester_id' => $context['semester']->id,
            'exam_date' => '2026-05-03',
            'exam_start_time' => '09:00:00',
            'status' => ExamOfferingStatus::Draft->value,
        ]);

        $this->assertTrue($firstOffering->is_pinned);

        try {
            app(ExamScheduleGeneratorService::class)->pinOffering($secondOffering);
            $this->fail('Expected pinning the conflicting offering to fail.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'لا يمكن تثبيت هذه المادة في هذا الموعد لوجود تعارض مع مادة مثبتة أخرى.',
                $exception->errors()['is_pinned'][0] ?? null,
            );
        }
    }

    #[Test]
    public function unpinned_offering_can_be_rescheduled_by_new_generation(): void
    {
        $context = $this->createAcademicContext();
        $this->actingAs(User::factory()->create(['college_id' => $context['college']->id]));
        $subject = $this->createSubject($context, 'إلغاء تثبيت');
        $this->createRoster($context, $subject, [['S-001', 'طالب أول', 'regular']]);
        $offering = SubjectExamOffering::query()->create([
            'subject_id' => $subject->id,
            'academic_year_id' => $context['academic_year']->id,
            'semester_id' => $context['semester']->id,
            'exam_date' => '2026-05-03',
            'exam_start_time' => '12:00:00',
            'is_pinned' => true,
            'status' => ExamOfferingStatus::Draft->value,
        ]);

        app(ExamScheduleGeneratorService::class)->unpinOffering($offering);

        $draft = app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context, [
            'start_date' => '2026-05-03',
            'end_date' => '2026-05-03',
            'periods' => [
                ['name' => 'صباحية', 'start_time' => '09:00', 'end_time' => '11:00', 'period_type' => 'morning'],
                ['name' => 'وسطى', 'start_time' => '12:00', 'end_time' => '14:00', 'period_type' => 'mid_day'],
            ],
        ]));
        $item = $draft->items()->where('subject_id', $subject->id)->firstOrFail();

        $this->assertFalse($offering->refresh()->is_pinned);
        $this->assertFalse((bool) ($item->metadata['pinned'] ?? false));
        $this->assertSame('09:00:00', substr((string) $item->start_time, 0, 8));
    }

    #[Test]
    public function generator_does_not_use_subject_exam_offering_students_or_old_fallbacks(): void
    {
        $context = $this->createAcademicContext();
        $this->actingAs(User::factory()->create(['college_id' => $context['college']->id]));
        $subject = $this->createSubject($context, 'فيزياء');
        $this->createRoster($context, $subject, [
            ['R-001', 'طالب من القائمة', 'regular'],
        ]);

        $oldOffering = SubjectExamOffering::query()->create([
            'subject_id' => $subject->id,
            'academic_year_id' => $context['academic_year']->id,
            'semester_id' => $context['semester']->id,
            'exam_date' => '2026-04-01',
            'exam_start_time' => '09:00:00',
            'status' => ExamOfferingStatus::Draft->value,
        ]);

        ExamStudent::query()->create([
            'subject_exam_offering_id' => $oldOffering->id,
            'student_number' => 'OLD-001',
            'full_name' => 'طالب قديم',
            'student_type' => ExamStudentType::Regular->value,
        ]);

        $draft = app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context));
        $item = $draft->items()->firstOrFail();

        $this->assertSame(1, $item->student_count);
        $this->assertSame(['R-001'], $item->metadata['student_numbers']);
        $this->assertArrayNotHasKey('source_offering_id', $item->metadata ?? []);
    }

    #[Test]
    public function same_student_same_time_conflict_is_prevented(): void
    {
        $context = $this->createAcademicContext();
        $this->actingAs(User::factory()->create(['college_id' => $context['college']->id]));
        $this->createRoster($context, $this->createSubject($context, 'تحليل 1'), [['S-001', 'طالب مشترك', 'regular']]);
        $this->createRoster($context, $this->createSubject($context, 'فيزياء 1'), [['S-001', 'طالب مشترك', 'carry']]);

        $draft = app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context, [
            'start_date' => '2026-05-03',
            'end_date' => '2026-05-03',
            'periods' => [
                ['name' => 'صباحية', 'start_time' => '09:00', 'end_time' => '11:00', 'period_type' => 'morning'],
            ],
        ]));

        $this->assertSame(1, $draft->items()->where('status', 'unscheduled')->count());

        $unscheduledItem = $draft->items()->where('status', 'unscheduled')->firstOrFail();
        $this->assertSame('student_conflict', $unscheduledItem->metadata['unscheduled_reason_code']);
        $this->assertStringContainsString('تعذر إيجاد موعد لا يسبب تعارضاً للطلاب', $unscheduledItem->metadata['unscheduled_reason']);
        $this->assertSame(1, $unscheduledItem->metadata['attempted_slots_count']);
        $this->assertSame(1, $unscheduledItem->metadata['student_conflict_slots_count']);
        $this->assertSame(['S-001'], $unscheduledItem->metadata['sample_conflicting_student_numbers']);
        $this->assertStringContainsString('S-001', $unscheduledItem->conflict_notes);

        $validation = app(ExamScheduleGeneratorService::class)->validateDraft($draft->refresh());
        $unscheduledConflict = collect($validation['conflicts'])->firstWhere('type', 'unscheduled');
        $this->assertNotNull($unscheduledConflict);
        $this->assertSame(['S-001'], $unscheduledConflict['conflicting_student_numbers']);
        $this->assertStringContainsString('عدد الفترات المجربة: 1', $unscheduledConflict['details']);
        $this->assertSame(1, count($validation['unscheduled_items']));
        $this->assertSame('student_conflict', $validation['unscheduled_items'][0]['reason_code']);
    }

    #[Test]
    public function conflict_report_shows_only_the_intersecting_student_number(): void
    {
        $context = $this->createAcademicContext();
        $this->actingAs(User::factory()->create(['college_id' => $context['college']->id]));
        $this->createRoster($context, $this->createSubject($context, 'تحليل تقاطع'), [
            ['1001', 'طالب أول', 'regular'],
            ['1002', 'طالب مشترك', 'regular'],
            ['1003', 'طالب ثالث', 'regular'],
        ]);
        $this->createRoster($context, $this->createSubject($context, 'فيزياء تقاطع'), [
            ['1002', 'طالب مشترك', 'carry'],
            ['1005', 'طالب خامس', 'regular'],
        ]);

        $draft = app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context));

        $draft->items()->update([
            'exam_date' => '2026-05-03',
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
            'period_type' => 'morning',
            'status' => 'scheduled',
        ]);

        $validation = app(ExamScheduleGeneratorService::class)->validateDraft($draft->refresh());
        $studentConflicts = collect($validation['conflicts'])->where('type', 'same_student_time')->values();

        $this->assertNotEmpty($studentConflicts);
        $studentConflicts->each(function (array $conflict): void {
            $this->assertSame(['1002'], $conflict['conflicting_student_numbers']);
            $this->assertSame('1002', $conflict['conflicting_student_numbers_label']);
            $this->assertStringContainsString('1002', $conflict['details']);
            $this->assertStringNotContainsString('1001', $conflict['details']);
            $this->assertStringNotContainsString('1003', $conflict['details']);
            $this->assertStringNotContainsString('1005', $conflict['details']);
        });

        $html = view('pdf.exam-schedule-conflicts', [
            'draft' => $draft->fresh('college'),
            'conflicts' => $validation['conflicts'],
            'summary' => $validation['summary'],
            'systemSetting' => (object) ['university_name' => 'جامعة الاختبار'],
            'logoDataUri' => null,
        ])->render();

        $this->assertStringContainsString('رقم الطالب المتعارض', $html);
        $this->assertStringContainsString('1002', $html);
        $this->assertStringNotContainsString('1001', $html);
        $this->assertStringNotContainsString('1003', $html);
        $this->assertStringNotContainsString('1005', $html);
    }

    #[Test]
    public function student_conflict_detail_rows_include_every_conflicting_student_without_preview_limit(): void
    {
        $context = $this->createAcademicContext();
        $this->actingAs(User::factory()->create(['college_id' => $context['college']->id]));
        $students = collect(range(1, 12))
            ->map(fn (int $index): array => [sprintf('30%02d', $index), 'طالب '.$index, 'regular'])
            ->all();

        $this->createRoster($context, $this->createSubject($context, 'تصميم آلات 1'), $students);
        $this->createRoster($context, $this->createSubject($context, 'ديناميك آلات'), $students);

        $draft = app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context));

        $draft->items()->update([
            'exam_date' => '2026-05-03',
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
            'period_type' => 'morning',
            'status' => 'scheduled',
        ]);

        $service = app(ExamScheduleGeneratorService::class);
        $validation = $service->validateDraft($draft->refresh());
        $studentConflict = collect($validation['conflicts'])->firstWhere('type', 'same_student_time');

        $this->assertNotNull($studentConflict);
        $this->assertSame(12, $studentConflict['affected_students']);
        $this->assertSame('3001, 3002, 3003, 3004, 3005, 3006, 3007, 3008, 3009, 3010 + 2 آخرين', $studentConflict['conflicting_student_numbers_label']);

        $detailRows = $service->studentConflictDetailRows($draft->refresh());

        $this->assertCount(12, $detailRows);
        $this->assertSame(
            collect($students)->pluck(0)->sort()->values()->all(),
            collect($detailRows)->pluck('student_number')->sort()->values()->all(),
        );
        $this->assertContains('3012', collect($detailRows)->pluck('student_number')->all());

        $html = view('pdf.exam-schedule-conflicts', [
            'draft' => $draft->fresh('college'),
            'conflicts' => $validation['conflicts'],
            'summary' => $validation['summary'],
            'studentConflictDetailsCount' => count($detailRows),
            'systemSetting' => (object) ['university_name' => 'جامعة الاختبار'],
            'logoDataUri' => null,
        ])->render();

        $this->assertStringContainsString('+ 2 آخرين', $html);
        $this->assertStringContainsString('تم إرفاق ملف تفصيلي يحتوي جميع أرقام الطلاب المتعارضين بدون اختصار', $html);
        $this->assertStringContainsString('عدد الصفوف التفصيلية: 12', $html);
    }

    #[Test]
    public function department_student_number_prefixing_prevents_false_conflicts_between_departments(): void
    {
        $context = $this->createAcademicContext();
        $context['college']->update(['enable_department_student_number_prefix' => true]);
        $context['department']->update(['student_number_prefix' => '11']);
        $secondDepartment = Department::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قسم القوى الميكانيكية',
            'student_number_prefix' => '12',
            'is_active' => true,
        ]);
        $this->actingAs(User::factory()->create(['college_id' => $context['college']->id]));

        $firstSubject = $this->createSubject($context, 'تصميم آلات 1');
        $secondSubject = $this->createSubject($context, 'تصميم آلات 2', ['department_id' => $secondDepartment->id]);
        $firstRoster = $this->createRoster($context, $firstSubject, [['3456', 'طالب قسم التصميم', 'regular']]);
        $secondRoster = $this->createRoster($context, $secondSubject, [['3456', 'طالب قسم القوى', 'regular']], ['department_id' => $secondDepartment->id]);
        $prefixService = app(RosterStudentNumberPrefixService::class);

        $firstResult = $prefixService->applyPrefixing($firstRoster->refresh());
        $secondResult = $prefixService->applyPrefixing($secondRoster->refresh());

        $this->assertSame(1, $firstResult['updated_students_count']);
        $this->assertSame(1, $secondResult['updated_students_count']);
        $this->assertSame('113456', $firstRoster->rosterStudents()->firstOrFail()->student_number);
        $this->assertSame('3456', $firstRoster->rosterStudents()->firstOrFail()->original_student_number);
        $this->assertSame('123456', $secondRoster->rosterStudents()->firstOrFail()->student_number);

        $secondApply = $prefixService->applyPrefixing($firstRoster->refresh());
        $this->assertSame(0, $secondApply['updated_students_count']);
        $this->assertSame('113456', $firstRoster->rosterStudents()->firstOrFail()->student_number);

        $draft = app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context, [
            'start_date' => '2026-05-03',
            'end_date' => '2026-05-03',
            'periods' => [
                ['name' => 'صباحية', 'start_time' => '09:00', 'end_time' => '11:00', 'period_type' => 'morning'],
            ],
        ]));
        $validation = app(ExamScheduleGeneratorService::class)->validateDraft($draft->refresh());

        $this->assertSame(0, $draft->items()->where('status', 'unscheduled')->count());
        $this->assertSame([], collect($validation['conflicts'])->whereIn('type', ['same_student_time', 'same_student_day'])->values()->all());

        $restoreResult = $prefixService->restoreOriginalNumbers($firstRoster->refresh());
        $this->assertSame(1, $restoreResult['updated_students_count']);
        $this->assertSame('3456', $firstRoster->rosterStudents()->firstOrFail()->student_number);
    }

    #[Test]
    public function department_student_number_prefixing_does_not_skip_numbers_that_already_start_with_prefix(): void
    {
        $context = $this->createAcademicContext();
        $context['college']->update(['enable_department_student_number_prefix' => true]);
        $context['department']->update(['student_number_prefix' => '12']);
        $this->actingAs(User::factory()->create(['college_id' => $context['college']->id]));
        $subject = $this->createSubject($context, 'قياسات مخبرية');
        $roster = $this->createRoster($context, $subject, [
            ['1284', 'طالب يبدأ رقمه بالرمز', 'regular'],
            ['816', 'طالب لا يبدأ رقمه بالرمز', 'regular'],
        ]);
        $prefixService = app(RosterStudentNumberPrefixService::class);

        $preview = $prefixService->previewPrefixing($roster->refresh());
        $this->assertSame('1284', $preview['preview_rows'][0]['original_number']);
        $this->assertSame('1284', $preview['preview_rows'][0]['current_number']);
        $this->assertSame('121284', $preview['preview_rows'][0]['new_number']);

        $firstResult = $prefixService->applyPrefixing($roster->refresh());

        $this->assertSame(2, $firstResult['updated_students_count']);
        $this->assertSame(0, $firstResult['already_correct_students_count']);
        $this->assertSame('121284', $roster->rosterStudents()->where('full_name', 'طالب يبدأ رقمه بالرمز')->firstOrFail()->student_number);
        $this->assertSame('1284', $roster->rosterStudents()->where('full_name', 'طالب يبدأ رقمه بالرمز')->firstOrFail()->original_student_number);
        $this->assertSame('12816', $roster->rosterStudents()->where('full_name', 'طالب لا يبدأ رقمه بالرمز')->firstOrFail()->student_number);
        $this->assertSame('816', $roster->rosterStudents()->where('full_name', 'طالب لا يبدأ رقمه بالرمز')->firstOrFail()->original_student_number);

        $secondResult = $prefixService->applyPrefixing($roster->refresh());

        $this->assertSame(0, $secondResult['updated_students_count']);
        $this->assertSame(2, $secondResult['already_correct_students_count']);
        $this->assertSame('121284', $roster->rosterStudents()->where('full_name', 'طالب يبدأ رقمه بالرمز')->firstOrFail()->student_number);
        $this->assertSame('12816', $roster->rosterStudents()->where('full_name', 'طالب لا يبدأ رقمه بالرمز')->firstOrFail()->student_number);
    }

    #[Test]
    public function same_student_same_day_conflict_is_prevented_when_enabled(): void
    {
        $context = $this->createAcademicContext();
        $this->actingAs(User::factory()->create(['college_id' => $context['college']->id]));
        $this->createRoster($context, $this->createSubject($context, 'تحليل 2'), [['S-001', 'طالب مشترك', 'regular']]);
        $this->createRoster($context, $this->createSubject($context, 'فيزياء 2'), [['S-001', 'طالب مشترك', 'carry']]);

        $draft = app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context, [
            'start_date' => '2026-05-03',
            'end_date' => '2026-05-03',
            'prevent_same_day' => true,
            'periods' => [
                ['name' => 'صباحية', 'start_time' => '09:00', 'end_time' => '11:00', 'period_type' => 'morning'],
                ['name' => 'وسطى', 'start_time' => '12:00', 'end_time' => '14:00', 'period_type' => 'mid_day'],
            ],
        ]));

        $this->assertSame(1, $draft->items()->where('status', 'unscheduled')->count());
    }

    #[Test]
    public function carry_student_conflict_across_years_is_detected(): void
    {
        $context = $this->createAcademicContext();
        $this->actingAs(User::factory()->create(['college_id' => $context['college']->id]));
        $secondLevel = StudyLevel::query()->create(['name' => 'السنة الرابعة', 'is_active' => true]);
        $regular = $this->createSubject($context, 'شبكات', ['study_level_id' => $context['study_level']->id]);
        $carry = $this->createSubject($context, 'برمجة قديمة', ['study_level_id' => $secondLevel->id]);
        $this->createRoster($context, $regular, [['S-777', 'طالب حملة', 'regular']]);
        $this->createRoster($context, $carry, [['S-777', 'طالب حملة', 'carry']], ['study_level_id' => $secondLevel->id]);

        $draft = app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context, [
            'start_date' => '2026-05-03',
            'end_date' => '2026-05-03',
            'periods' => [
                ['name' => 'صباحية', 'start_time' => '09:00', 'end_time' => '11:00', 'period_type' => 'morning'],
            ],
        ]));

        $this->assertSame(1, $draft->items()->where('status', 'unscheduled')->count());
    }

    #[Test]
    public function core_subject_is_preferred_in_morning(): void
    {
        $context = $this->createAcademicContext();
        $this->actingAs(User::factory()->create(['college_id' => $context['college']->id]));
        $subject = $this->createSubject($context, 'خوارزميات', [
            'is_core_subject' => true,
            'preferred_exam_period' => 'morning',
        ]);
        $this->createRoster($context, $subject, [['S-001', 'طالب', 'regular']]);

        $draft = app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context, [
            'periods' => [
                ['name' => 'مسائية', 'start_time' => '15:00', 'end_time' => '17:00', 'period_type' => 'evening'],
                ['name' => 'صباحية', 'start_time' => '09:00', 'end_time' => '11:00', 'period_type' => 'morning'],
            ],
        ]));

        $this->assertSame('morning', $draft->items()->firstOrFail()->period_type);
    }

    #[Test]
    public function strict_core_subject_fails_if_morning_slot_unavailable(): void
    {
        $context = $this->createAcademicContext();
        $this->actingAs(User::factory()->create(['college_id' => $context['college']->id]));
        $subject = $this->createSubject($context, 'نظم تشغيل', [
            'is_core_subject' => true,
            'preferred_exam_period' => 'morning',
            'core_subject_priority' => 'strict',
        ]);
        $this->createRoster($context, $subject, [['S-001', 'طالب', 'regular']]);

        $draft = app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context, [
            'periods' => [
                ['name' => 'مسائية', 'start_time' => '15:00', 'end_time' => '17:00', 'period_type' => 'evening'],
            ],
        ]));

        $this->assertSame('unscheduled', $draft->items()->firstOrFail()->status);
    }

    #[Test]
    public function canceling_a_manual_draft_item_deletes_it_even_after_the_source_roster_was_deleted(): void
    {
        $context = $this->createAcademicContext();
        $user = User::factory()->create(['college_id' => $context['college']->id]);
        $user->givePermissionTo([
            Permission::findOrCreate('view_exam_schedule_generator', 'web'),
            Permission::findOrCreate('update_exam_schedule_draft', 'web'),
        ]);
        $this->actingAs($user);

        $subject = $this->createSubject($context, 'تحليل محذوف');
        $roster = $this->createRoster($context, $subject, [['S-001', 'طالب', 'regular']]);
        $draft = app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context));
        $item = $draft->items()->firstOrFail();

        $roster->delete();
        $this->assertNull($item->refresh()->sourceRoster);

        Filament::setCurrentPanel(Filament::getPanel('adminpanel'));

        Livewire::actingAs($user)
            ->test(ExamScheduleGenerator::class)
            ->set('draft_id', $draft->id)
            ->call('cancelDraftItem', $item->id);

        $this->assertDatabaseMissing('exam_schedule_draft_items', ['id' => $item->id]);
        $this->assertSame(0, $draft->fresh()->items()->count());
    }

    #[Test]
    public function generator_does_not_copy_pinned_items_whose_source_roster_was_deleted(): void
    {
        $context = $this->createAcademicContext();
        $this->actingAs(User::factory()->create(['college_id' => $context['college']->id]));

        $oldSubject = $this->createSubject($context, 'قائمة قديمة');
        $oldRoster = $this->createRoster($context, $oldSubject, [['S-001', 'طالب قديم', 'regular']]);
        $previousDraft = app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context));
        $previousItem = $previousDraft->items()->firstOrFail();
        $previousItem->update([
            'metadata' => array_merge($previousItem->metadata ?? [], ['pinned' => true]),
        ]);

        $oldRoster->delete();
        $this->assertNull($previousItem->refresh()->sourceRoster);

        $newSubject = $this->createSubject($context, 'قائمة جديدة');
        $this->createRoster($context, $newSubject, [['S-002', 'طالب جديد', 'regular']]);

        $newDraft = app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context, [
            'previous_draft_id' => $previousDraft->id,
        ]));

        $this->assertSame(1, $newDraft->items()->count());
        $this->assertSame($newSubject->id, $newDraft->items()->firstOrFail()->subject_id);
    }

    #[Test]
    public function shared_subject_all_departments_together_groups_rosters(): void
    {
        $context = $this->createAcademicContext();
        $this->actingAs(User::factory()->create(['college_id' => $context['college']->id]));
        $secondDepartment = Department::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قسم الاتصالات',
            'is_active' => true,
        ]);
        $first = $this->createSubject($context, 'ثقافة', [
            'code' => 'SHARED-101',
            'is_shared_subject' => true,
            'shared_subject_scheduling_mode' => 'all_departments_together',
        ]);
        $second = $this->createSubject($context, 'ثقافة', [
            'department_id' => $secondDepartment->id,
            'code' => 'SHARED-101',
            'is_shared_subject' => true,
            'shared_subject_scheduling_mode' => 'all_departments_together',
        ]);
        $this->createRoster($context, $first, [['S-001', 'طالب أول', 'regular']]);
        $this->createRoster($context, $second, [['S-002', 'طالب ثان', 'regular']], ['department_id' => $secondDepartment->id]);

        $draft = app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context));
        $items = $draft->items()->get();

        $this->assertCount(2, $items);
        $this->assertSame(1, $items->pluck('shared_group_key')->unique()->count());
        $this->assertSame(1, $items->pluck('exam_date')->unique()->count());
        $this->assertSame(1, $items->pluck('start_time')->unique()->count());
    }

    #[Test]
    public function approval_creates_official_offering_and_copies_roster_students(): void
    {
        $context = $this->createAcademicContext();
        $this->actingAs(User::factory()->create(['college_id' => $context['college']->id]));
        $subject = $this->createSubject($context, 'قواعد معطيات');
        $this->createRoster($context, $subject, [
            ['S-001', 'طالب أول', 'regular'],
            ['S-002', 'طالب ثان', 'carry'],
        ]);

        $draft = app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context));
        $result = app(ExamScheduleGeneratorService::class)->approveDraft($draft);
        $offering = SubjectExamOffering::query()->where('exam_schedule_draft_id', $draft->id)->firstOrFail();

        $this->assertSame('success', $result['status']);
        $this->assertSame(0, $result['created_count']);
        $this->assertSame(1, $result['updated_count']);
        $this->assertSame(ExamOfferingStatus::Ready, $offering->status);
        $this->assertSame(0, SubjectExamOffering::query()->where('exam_schedule_draft_id', $draft->id)->where('status', ExamOfferingStatus::Draft->value)->count());
        $this->assertSame(2, $offering->examStudents()->count());
        $this->assertSame(1, $offering->carryStudents()->count());
    }

    #[Test]
    public function manual_workflow_still_allows_direct_offering_students(): void
    {
        $context = $this->createAcademicContext();
        $subject = $this->createSubject($context, 'يدوي');
        $offering = SubjectExamOffering::query()->create([
            'subject_id' => $subject->id,
            'academic_year_id' => $context['academic_year']->id,
            'semester_id' => $context['semester']->id,
            'exam_date' => '2026-05-03',
            'exam_start_time' => '09:00:00',
            'status' => ExamOfferingStatus::Draft->value,
        ]);

        $offering->examStudents()->create([
            'student_number' => 'M-001',
            'full_name' => 'طالب يدوي',
            'student_type' => ExamStudentType::Regular->value,
        ]);

        $this->assertSame(1, $offering->examStudents()->count());
    }

    protected function createAcademicContext(): array
    {
        $college = College::query()->create([
            'name' => 'كلية الهندسة',
            'is_active' => true,
        ]);

        $department = Department::query()->create([
            'college_id' => $college->id,
            'name' => 'قسم المعلوماتية',
            'is_active' => true,
        ]);

        $studyLevel = StudyLevel::query()->create([
            'name' => 'السنة الثالثة',
            'is_active' => true,
        ]);

        $academicYear = AcademicYear::query()->create([
            'name' => '2025-2026',
            'is_active' => true,
            'is_current' => true,
        ]);

        $semester = Semester::query()->create([
            'name' => 'الفصل الثاني',
            'is_active' => true,
        ]);

        return [
            'college' => $college,
            'department' => $department,
            'study_level' => $studyLevel,
            'academic_year' => $academicYear,
            'semester' => $semester,
        ];
    }

    protected function createSubject(array $context, string $name, array $overrides = []): Subject
    {
        return Subject::query()->create([
            'college_id' => $context['college']->id,
            'department_id' => $overrides['department_id'] ?? $context['department']->id,
            'study_level_id' => $overrides['study_level_id'] ?? $context['study_level']->id,
            'name' => $name,
            'code' => $overrides['code'] ?? null,
            'is_active' => true,
            'is_shared_subject' => $overrides['is_shared_subject'] ?? false,
            'shared_subject_scheduling_mode' => $overrides['shared_subject_scheduling_mode'] ?? 'auto',
            'is_core_subject' => $overrides['is_core_subject'] ?? false,
            'preferred_exam_period' => $overrides['preferred_exam_period'] ?? 'none',
            'core_subject_priority' => $overrides['core_subject_priority'] ?? 'preference',
        ]);
    }

    protected function createRoster(array $context, Subject $subject, array $students, array $overrides = []): SubjectExamRoster
    {
        $roster = SubjectExamRoster::query()->create([
            'college_id' => $context['college']->id,
            'department_id' => $overrides['department_id'] ?? $subject->department_id,
            'subject_id' => $subject->id,
            'academic_year_id' => $context['academic_year']->id,
            'semester_id' => $context['semester']->id,
            'study_level_id' => $overrides['study_level_id'] ?? $subject->study_level_id,
            'status' => 'ready',
            'source' => 'manual',
        ]);

        foreach ($students as [$number, $name, $type]) {
            $roster->rosterStudents()->create([
                'student_number' => $number,
                'full_name' => $name,
                'student_type' => $type,
                'is_eligible' => true,
            ]);
        }

        return $roster;
    }

    protected function settings(array $context, array $overrides = []): array
    {
        return array_replace([
            'faculty_id' => $context['college']->id,
            'academic_year_id' => $context['academic_year']->id,
            'semester_id' => $context['semester']->id,
            'start_date' => '2026-05-03',
            'end_date' => '2026-05-07',
            'excluded_weekdays' => [5, 6],
            'holidays' => [],
            'periods' => [
                ['name' => 'الفترة الأولى', 'start_time' => '09:00', 'end_time' => '11:00', 'period_type' => 'morning'],
                ['name' => 'الفترة الثانية', 'start_time' => '12:00', 'end_time' => '14:00', 'period_type' => 'mid_day'],
            ],
            'prevent_same_day' => false,
        ], $overrides);
    }
}
