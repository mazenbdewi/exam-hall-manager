<?php

namespace Tests\Feature;

use App\Enums\ExamOfferingStatus;
use App\Enums\ExamStudentType;
use App\Exceptions\ExamScheduleGenerationException;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
    public function generation_failure_for_subject_without_students_has_actionable_details_and_no_draft(): void
    {
        $context = $this->createAcademicContext();
        $this->actingAs(User::factory()->create(['college_id' => $context['college']->id]));
        $subject = $this->createSubject($context, 'آلات خاصة');
        $roster = $this->createRoster($context, $subject, []);

        try {
            app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context));
            $this->fail('Expected generation to fail for a subject roster without students.');
        } catch (ExamScheduleGenerationException $exception) {
            $this->assertSame('missing_student_data', $exception->reasonCode);
            $this->assertStringContainsString('بدون طلاب', $exception->userMessage);
            $this->assertSame('آلات خاصة', $exception->details[0]['subject']);
            $this->assertSame($context['college']->name, $exception->details[0]['college']);
            $this->assertSame($context['department']->name, $exception->details[0]['department']);
            $this->assertSame($roster->id, $exception->details[0]['roster_id']);
        }

        $this->assertSame(0, ExamScheduleDraft::query()->count());
        $this->assertSame(0, ExamScheduleDraftItem::query()->count());
        $this->assertSame(0, SubjectExamOffering::query()->whereNotNull('exam_schedule_draft_id')->count());
    }

    #[Test]
    public function generation_failure_for_missing_exam_periods_has_clear_reason_and_no_draft(): void
    {
        $context = $this->createAcademicContext();
        $this->actingAs(User::factory()->create(['college_id' => $context['college']->id]));
        $this->createRoster($context, $this->createSubject($context, 'تحليل'), [['S-001', 'طالب', 'regular']]);

        try {
            app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context, [
                'periods' => [],
            ]));
            $this->fail('Expected generation to fail without exam periods.');
        } catch (ExamScheduleGenerationException $exception) {
            $this->assertSame('missing_exam_periods', $exception->reasonCode);
            $this->assertStringContainsString('لا توجد فترات امتحانية', $exception->userMessage);
        }

        $this->assertSame(0, ExamScheduleDraft::query()->count());
        $this->assertSame(0, ExamScheduleDraftItem::query()->count());
    }

    #[Test]
    public function generation_failure_for_missing_exam_days_has_clear_reason_and_no_draft(): void
    {
        $context = $this->createAcademicContext();
        $this->actingAs(User::factory()->create(['college_id' => $context['college']->id]));
        $this->createRoster($context, $this->createSubject($context, 'فيزياء'), [['S-001', 'طالب', 'regular']]);

        try {
            app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context, [
                'start_date' => '2026-05-03',
                'end_date' => '2026-05-03',
                'excluded_weekdays' => [0],
            ]));
            $this->fail('Expected generation to fail without available exam days.');
        } catch (ExamScheduleGenerationException $exception) {
            $this->assertSame('missing_exam_days', $exception->reasonCode);
            $this->assertStringContainsString('لا توجد أيام امتحانية', $exception->userMessage);
        }

        $this->assertSame(0, ExamScheduleDraft::query()->count());
        $this->assertSame(0, ExamScheduleDraftItem::query()->count());
    }

    #[Test]
    public function generator_never_schedules_subjects_on_excluded_friday(): void
    {
        $context = $this->createAcademicContext();
        $this->actingAs(User::factory()->create(['college_id' => $context['college']->id]));
        $this->createRoster($context, $this->createSubject($context, 'رسم هندسي 1'), [['S-001', 'طالب', 'regular']]);

        $draft = app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context, [
            'start_date' => '2026-07-09',
            'end_date' => '2026-07-12',
            'excluded_weekdays' => [5],
            'periods' => [
                ['name' => 'صباحية', 'start_time' => '09:00', 'end_time' => '11:00', 'period_type' => 'morning'],
            ],
        ]));

        $this->assertSame(0, $draft->items()->whereDate('exam_date', '2026-07-10')->count());
        $this->assertNotSame('2026-07-10', $draft->items()->firstOrFail()->exam_date?->toDateString());
    }

    #[Test]
    public function pinned_subject_on_excluded_day_stops_generation_with_clear_holiday_reason(): void
    {
        $context = $this->createAcademicContext();
        $this->actingAs(User::factory()->create(['college_id' => $context['college']->id]));
        $subject = $this->createSubject($context, 'رسم هندسي 1');
        $roster = $this->createRoster($context, $subject, [['S-001', 'طالب', 'regular']]);

        SubjectExamOffering::query()->create([
            'subject_id' => $subject->id,
            'academic_year_id' => $context['academic_year']->id,
            'semester_id' => $context['semester']->id,
            'exam_date' => '2026-07-10',
            'exam_start_time' => '09:00:00',
            'is_pinned' => true,
            'status' => ExamOfferingStatus::Draft->value,
        ]);

        try {
            app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context, [
                'start_date' => '2026-07-09',
                'end_date' => '2026-07-12',
                'excluded_weekdays' => [5],
                'periods' => [
                    ['name' => 'صباحية', 'start_time' => '09:00', 'end_time' => '11:00', 'period_type' => 'morning'],
                ],
            ]));
            $this->fail('Expected pinned subject on excluded Friday to stop generation.');
        } catch (ExamScheduleGenerationException $exception) {
            $this->assertSame('holiday', $exception->reasonCode);
            $this->assertStringContainsString('يوم عطلة', $exception->userMessage);
            $this->assertSame('رسم هندسي 1', $exception->details[0]['subject']);
            $this->assertSame($context['department']->name, $exception->details[0]['department']);
            $this->assertSame('2026-07-10', $exception->details[0]['date']);
            $this->assertSame('09:00', $exception->details[0]['time']);
            $this->assertTrue($exception->details[0]['pinned']);
            $this->assertSame($roster->id, $exception->details[0]['roster_id']);
        }

        $this->assertSame(0, ExamScheduleDraft::query()->count());
        $this->assertSame(0, SubjectExamOffering::query()->whereNotNull('exam_schedule_draft_id')->count());
    }

    #[Test]
    public function holiday_conflict_from_validation_is_user_facing_not_unexpected(): void
    {
        $context = $this->createAcademicContext();
        $this->actingAs(User::factory()->create(['college_id' => $context['college']->id]));
        $subject = $this->createSubject($context, 'التمثيل والرسم الهندسي');
        $this->createRoster($context, $subject, [['S-001', 'طالب', 'regular']]);

        app()->instance(ExamScheduleGeneratorService::class, new class extends ExamScheduleGeneratorService
        {
            public function validateDraft(ExamScheduleDraft $draft): array
            {
                $item = $draft->items()->with(['subject.department', 'department'])->firstOrFail();

                return [
                    'summary' => ['status' => 'failed'],
                    'conflicts' => [[
                        'item_id' => $item->id,
                        'subject' => $item->subject?->name,
                        'department' => $item->department?->name ?? $item->subject?->department?->name,
                        'date' => '2026-07-10',
                        'time' => '09:00',
                        'type' => 'holiday',
                        'type_label' => 'يوم عطلة',
                        'impact' => 'تاريخ مستبعد',
                        'details' => 'تاريخ مستبعد',
                        'suggested_action' => 'انقل المادة إلى يوم غير مستبعد.',
                        'hard' => true,
                    ]],
                    'unscheduled_items' => [],
                    'hard_conflicts_count' => 1,
                    'warnings_count' => 0,
                ];
            }
        });

        try {
            app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context));
            $this->fail('Expected validation holiday conflict to stop generation.');
        } catch (ExamScheduleGenerationException $exception) {
            $this->assertSame('holiday', $exception->reasonCode);
            $this->assertStringContainsString('يوم عطلة', $exception->userMessage);
            $this->assertStringNotContainsString('غير متوقع', $exception->userMessage);
            $this->assertSame('التمثيل والرسم الهندسي', $exception->details[0]['subject']);
        }

        $this->assertSame(0, ExamScheduleDraft::query()->count());
    }

    #[Test]
    public function successful_generation_logs_before_and_after_draft_validation(): void
    {
        Log::spy();

        $context = $this->createAcademicContext();
        $this->actingAs(User::factory()->create(['college_id' => $context['college']->id]));
        $this->createRoster($context, $this->createSubject($context, 'تحليل سجلات'), [['S-001', 'طالب', 'regular']]);

        app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context));

        Log::shouldHaveReceived('info')
            ->with('Exam schedule draft generation: before validating draft.', \Mockery::type('array'))
            ->once();

        Log::shouldHaveReceived('info')
            ->with('Exam schedule draft generation: after validating draft.', \Mockery::on(
                fn (array $context): bool => ($context['hard_conflicts_count'] ?? null) === 0
                    && array_key_exists('duration_ms', $context)
            ))
            ->once();

        Log::shouldHaveReceived('info')
            ->with('Draft validation completed.', \Mockery::type('array'))
            ->once();
    }

    #[Test]
    public function validation_exception_during_generation_is_wrapped_logged_and_rolls_back_draft(): void
    {
        Log::spy();

        $context = $this->createAcademicContext();
        $this->actingAs(User::factory()->create(['college_id' => $context['college']->id]));
        $this->createRoster($context, $this->createSubject($context, 'تحقق يفشل'), [['S-001', 'طالب', 'regular']]);

        app()->instance(ExamScheduleGeneratorService::class, new class extends ExamScheduleGeneratorService
        {
            public function validateDraft(ExamScheduleDraft $draft): array
            {
                throw new \RuntimeException('Forced validation failure.');
            }
        });

        try {
            app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context));
            $this->fail('Expected validation failure to be wrapped for the UI.');
        } catch (ExamScheduleGenerationException $exception) {
            $this->assertSame('draft_validation_failed', $exception->reasonCode);
            $this->assertStringContainsString('فشل التحقق', $exception->userMessage);
            $this->assertInstanceOf(\RuntimeException::class, $exception->getPrevious());
        }

        Log::shouldHaveReceived('error')
            ->with('Exam schedule draft validation failed.', \Mockery::on(
                fn (array $context): bool => ($context['reason_code'] ?? null) === 'draft_validation_failed'
                    && ($context['message'] ?? null) === 'Forced validation failure.'
            ))
            ->once();

        $this->assertSame(0, ExamScheduleDraft::query()->count());
        $this->assertSame(0, ExamScheduleDraftItem::query()->count());
        $this->assertSame(0, SubjectExamOffering::query()->whereNotNull('exam_schedule_draft_id')->count());
    }

    #[Test]
    public function validation_preloads_large_roster_student_numbers_without_per_item_queries(): void
    {
        $context = $this->createAcademicContext();
        $this->actingAs(User::factory()->create(['college_id' => $context['college']->id]));

        foreach (range(1, 3) as $subjectIndex) {
            $students = collect(range(1, 501))
                ->map(fn (int $studentIndex): array => [
                    'S'.$subjectIndex.'-'.$studentIndex,
                    'طالب '.$subjectIndex.'-'.$studentIndex,
                    'regular',
                ])
                ->all();

            $this->createRoster($context, $this->createSubject($context, 'مادة كبيرة '.$subjectIndex), $students);
        }

        $draft = app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context));

        DB::flushQueryLog();
        DB::enableQueryLog();

        app(ExamScheduleGeneratorService::class)->validateDraft($draft->refresh());

        $rosterStudentQueries = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains($query['query'] ?? '', 'subject_exam_roster_students'))
            ->count();

        DB::disableQueryLog();

        $this->assertLessThanOrEqual(1, $rosterStudentQueries);
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

        try {
            app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context, [
                'start_date' => '2026-05-03',
                'end_date' => '2026-05-03',
                'periods' => [
                    ['name' => 'صباحية', 'start_time' => '09:00', 'end_time' => '11:00', 'period_type' => 'morning'],
                ],
            ]));
            $this->fail('Expected generation to fail because the student has two exams in the only available slot.');
        } catch (ExamScheduleGenerationException $exception) {
            $this->assertSame('student_conflict', $exception->reasonCode);
            $this->assertStringContainsString('تعارض', $exception->userMessage);
            $this->assertSame(['S-001'], $exception->details[0]['conflicting_student_numbers']);
        }

        $this->assertSame(0, ExamScheduleDraft::query()->count());
        $this->assertSame(0, ExamScheduleDraftItem::query()->count());
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

        try {
            app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context, [
                'start_date' => '2026-05-03',
                'end_date' => '2026-05-03',
                'prevent_same_day' => true,
                'periods' => [
                    ['name' => 'صباحية', 'start_time' => '09:00', 'end_time' => '11:00', 'period_type' => 'morning'],
                    ['name' => 'وسطى', 'start_time' => '12:00', 'end_time' => '14:00', 'period_type' => 'mid_day'],
                ],
            ]));
            $this->fail('Expected generation to fail because same-day exams are prevented.');
        } catch (ExamScheduleGenerationException $exception) {
            $this->assertSame('student_conflict', $exception->reasonCode);
        }

        $this->assertSame(0, ExamScheduleDraft::query()->count());
        $this->assertSame(0, ExamScheduleDraftItem::query()->count());
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

        try {
            app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context, [
                'start_date' => '2026-05-03',
                'end_date' => '2026-05-03',
                'periods' => [
                    ['name' => 'صباحية', 'start_time' => '09:00', 'end_time' => '11:00', 'period_type' => 'morning'],
                ],
            ]));
            $this->fail('Expected generation to fail because the carry student has two exams in the only available slot.');
        } catch (ExamScheduleGenerationException $exception) {
            $this->assertSame('student_conflict', $exception->reasonCode);
        }

        $this->assertSame(0, ExamScheduleDraft::query()->count());
        $this->assertSame(0, ExamScheduleDraftItem::query()->count());
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

        try {
            app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context, [
                'periods' => [
                    ['name' => 'مسائية', 'start_time' => '15:00', 'end_time' => '17:00', 'period_type' => 'evening'],
                ],
            ]));
            $this->fail('Expected strict core subject generation to fail without a matching period.');
        } catch (ExamScheduleGenerationException $exception) {
            $this->assertSame('preferred_period_constraint', $exception->reasonCode);
        }

        $this->assertSame(0, ExamScheduleDraft::query()->count());
        $this->assertSame(0, ExamScheduleDraftItem::query()->count());
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
        $first->sharedDepartments()->sync([$context['department']->id, $secondDepartment->id]);
        $second = $this->createSubject($context, 'ثقافة', [
            'department_id' => $secondDepartment->id,
            'code' => 'SHARED-101',
            'is_shared_subject' => true,
            'shared_subject_scheduling_mode' => 'all_departments_together',
        ]);
        $second->sharedDepartments()->sync([$context['department']->id, $secondDepartment->id]);
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
    public function subject_form_defines_conditional_shared_departments_select(): void
    {
        $form = file_get_contents(app_path('Filament/Resources/Subjects/Schemas/SubjectForm.php'));

        $this->assertStringContainsString("Select::make('sharedDepartments')", $form);
        $this->assertStringContainsString("->visible(fn (Get \$get): bool => (bool) \$get('is_shared_subject'))", $form);
        $this->assertStringContainsString("->minItems(fn (Get \$get): ?int => (bool) \$get('is_shared_subject') ? 2 : null)", $form);
        $this->assertStringContainsString('اختر فقط الأقسام التي تدرس هذه المادة فعليًا', $form);
    }

    #[Test]
    public function shared_subject_with_department_from_another_college_stops_generation(): void
    {
        $context = $this->createAcademicContext();
        $this->actingAs(User::factory()->create(['college_id' => $context['college']->id]));
        $otherCollege = College::query()->create(['name' => 'كلية أخرى', 'is_active' => true]);
        $otherDepartment = Department::query()->create([
            'college_id' => $otherCollege->id,
            'name' => 'قسم خارجي',
            'is_active' => true,
        ]);
        $subject = $this->createSubject($context, 'مادة مشتركة بكلية خاطئة', [
            'is_shared_subject' => true,
            'shared_subject_scheduling_mode' => 'auto',
        ]);
        $subject->sharedDepartments()->sync([$context['department']->id, $otherDepartment->id]);
        $this->createRoster($context, $subject, [['S-001', 'طالب', 'regular']]);

        try {
            app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context));
            $this->fail('Expected shared subject with cross-college department to fail.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('من نفس الكلية', collect($exception->errors())->flatten()->first());
        }
    }

    #[Test]
    public function shared_subject_affects_only_selected_departments_when_generating_schedule(): void
    {
        $context = $this->createAcademicContext();
        $this->actingAs(User::factory()->create(['college_id' => $context['college']->id]));
        $secondDepartment = Department::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قسم الاتصالات',
            'is_active' => true,
        ]);
        $thirdDepartment = Department::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قسم الميكانيك',
            'is_active' => true,
        ]);

        $shared = $this->createSubject($context, 'رسم هندسي 1', [
            'is_shared_subject' => true,
            'shared_subject_scheduling_mode' => 'all_departments_together',
        ]);
        $shared->sharedDepartments()->sync([$context['department']->id, $secondDepartment->id]);
        $thirdDepartmentSubject = $this->createSubject($context, 'مادة الميكانيك', [
            'department_id' => $thirdDepartment->id,
        ]);

        $this->createRoster($context, $shared, [['S-001', 'طالب مشترك', 'regular']]);
        $this->createRoster($context, $thirdDepartmentSubject, [['M-001', 'طالب ميكانيك', 'regular']], ['department_id' => $thirdDepartment->id]);

        $draft = app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context, [
            'start_date' => '2026-05-03',
            'end_date' => '2026-05-03',
            'periods' => [
                ['name' => 'الفترة الوحيدة', 'start_time' => '09:00', 'end_time' => '11:00', 'period_type' => 'morning'],
            ],
        ]));

        $items = $draft->items()->with('subject')->get();
        $sharedItem = $items->firstWhere('subject_id', $shared->id);

        $this->assertCount(2, $items);
        $this->assertSame(1, $items->pluck('start_time')->unique()->count());
        $this->assertContains('department:'.$context['department']->id.'|level:'.$context['study_level']->id, $sharedItem->metadata['academic_group_keys']);
        $this->assertContains('department:'.$secondDepartment->id.'|level:'.$context['study_level']->id, $sharedItem->metadata['academic_group_keys']);
        $this->assertNotContains('department:'.$thirdDepartment->id.'|level:'.$context['study_level']->id, $sharedItem->metadata['academic_group_keys']);
        $this->assertSame([], collect(app(ExamScheduleGeneratorService::class)->validateDraft($draft)['conflicts'])
            ->where('type', 'same_academic_group_time')
            ->values()
            ->all());
    }

    #[Test]
    public function shared_subject_conflicts_with_only_its_selected_departments(): void
    {
        $context = $this->createAcademicContext();
        $this->actingAs(User::factory()->create(['college_id' => $context['college']->id]));
        $secondDepartment = Department::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قسم الاتصالات',
            'is_active' => true,
        ]);

        $shared = $this->createSubject($context, 'رسم هندسي 1', [
            'is_shared_subject' => true,
            'shared_subject_scheduling_mode' => 'all_departments_together',
        ]);
        $shared->sharedDepartments()->sync([$context['department']->id, $secondDepartment->id]);
        $secondDepartmentSubject = $this->createSubject($context, 'مادة الاتصالات', [
            'department_id' => $secondDepartment->id,
        ]);

        $this->createRoster($context, $shared, [['S-001', 'طالب مشترك', 'regular']]);
        $this->createRoster($context, $secondDepartmentSubject, [['C-001', 'طالب اتصالات', 'regular']], ['department_id' => $secondDepartment->id]);

        try {
            app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context, [
                'start_date' => '2026-05-03',
                'end_date' => '2026-05-03',
                'periods' => [
                    ['name' => 'الفترة الوحيدة', 'start_time' => '09:00', 'end_time' => '11:00', 'period_type' => 'morning'],
                ],
            ]));
            $this->fail('Expected selected shared department conflict to stop generation.');
        } catch (ExamScheduleGenerationException $exception) {
            $this->assertSame('academic_group_conflict', $exception->reasonCode);
        }
    }

    #[Test]
    public function legacy_shared_subject_without_departments_stops_generation_with_clear_message(): void
    {
        $context = $this->createAcademicContext();
        $this->actingAs(User::factory()->create(['college_id' => $context['college']->id]));
        $subject = $this->createSubject($context, 'مادة مشتركة قديمة', [
            'is_shared_subject' => true,
            'shared_subject_scheduling_mode' => 'auto',
        ]);
        $this->createRoster($context, $subject, [['S-001', 'طالب', 'regular']]);

        try {
            app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context));
            $this->fail('Expected legacy shared subject without departments to fail.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('دون تحديد قسمين', collect($exception->errors())->flatten()->first());
        }
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

    #[Test]
    public function same_level_subjects_are_spread_across_the_available_exam_range(): void
    {
        $context = $this->createAcademicContext();
        $this->actingAs(User::factory()->create(['college_id' => $context['college']->id]));

        foreach (range(1, 10) as $index) {
            $subject = $this->createSubject($context, 'مادة توزيع '.$index);
            $this->createRoster($context, $subject, [['S-'.$index, 'طالب '.$index, 'regular']]);
        }

        $draft = app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context, [
            'start_date' => '2026-07-08',
            'end_date' => '2026-08-13',
            'excluded_weekdays' => [5, 6],
            'periods' => [
                ['name' => 'صباحية', 'start_time' => '09:00', 'end_time' => '11:00', 'period_type' => 'morning'],
            ],
        ]));

        $dates = $draft->items()->orderBy('exam_date')->pluck('exam_date')->map(fn ($date) => $date->toDateString())->values();

        $this->assertGreaterThanOrEqual(24, \Carbon\Carbon::parse($dates->first())->diffInDays(\Carbon\Carbon::parse($dates->last())));
        $this->assertLessThan(10, $dates->filter(fn (string $date): bool => \Carbon\Carbon::parse($date)->lte(\Carbon\Carbon::parse('2026-07-19')))->count());
    }

    #[Test]
    public function same_level_minimum_gap_is_respected_when_the_period_has_enough_room(): void
    {
        $context = $this->createAcademicContext();
        $this->actingAs(User::factory()->create(['college_id' => $context['college']->id]));

        foreach (range(1, 4) as $index) {
            $subject = $this->createSubject($context, 'مادة فاصل '.$index);
            $this->createRoster($context, $subject, [['G-'.$index, 'طالب '.$index, 'regular']]);
        }

        $draft = app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context, [
            'start_date' => '2026-05-03',
            'end_date' => '2026-05-14',
            'excluded_weekdays' => [5, 6],
            'periods' => [
                ['name' => 'صباحية', 'start_time' => '09:00', 'end_time' => '11:00', 'period_type' => 'morning'],
            ],
            'minimum_gap_days_between_same_level_exams' => 1,
        ]));

        $restDays = $this->restDaysBetweenScheduledItems($draft);

        $this->assertNotEmpty($restDays);
        $this->assertGreaterThanOrEqual(1, min($restDays));
        $this->assertSame(0, $draft->summary_json['same_level_consecutive_warnings_count'] ?? 0);
    }

    #[Test]
    public function same_level_consecutive_exams_are_avoided_when_there_is_enough_room(): void
    {
        $context = $this->createAcademicContext();
        $this->actingAs(User::factory()->create(['college_id' => $context['college']->id]));

        foreach (range(1, 5) as $index) {
            $subject = $this->createSubject($context, 'مادة غير متتالية '.$index);
            $this->createRoster($context, $subject, [['C-'.$index, 'طالب '.$index, 'regular']]);
        }

        $draft = app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context, [
            'start_date' => '2026-05-03',
            'end_date' => '2026-05-20',
            'excluded_weekdays' => [5, 6],
            'periods' => [
                ['name' => 'صباحية', 'start_time' => '09:00', 'end_time' => '11:00', 'period_type' => 'morning'],
            ],
            'avoid_consecutive_same_level_exams' => true,
        ]));

        $this->assertGreaterThanOrEqual(1, min($this->restDaysBetweenScheduledItems($draft)));
    }

    #[Test]
    public function tight_period_generates_with_soft_gap_warnings_instead_of_failing(): void
    {
        $context = $this->createAcademicContext();
        $this->actingAs(User::factory()->create(['college_id' => $context['college']->id]));

        foreach (range(1, 3) as $index) {
            $subject = $this->createSubject($context, 'مادة ضيقة '.$index);
            $this->createRoster($context, $subject, [['T-'.$index, 'طالب '.$index, 'regular']]);
        }

        $draft = app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context, [
            'start_date' => '2026-05-03',
            'end_date' => '2026-05-05',
            'excluded_weekdays' => [5, 6],
            'periods' => [
                ['name' => 'صباحية', 'start_time' => '09:00', 'end_time' => '11:00', 'period_type' => 'morning'],
            ],
            'minimum_gap_days_between_same_level_exams' => 1,
            'avoid_consecutive_same_level_exams' => true,
        ]));

        $this->assertSame(ExamScheduleDraft::STATUS_COMPLETED, $draft->status);
        $this->assertSame('warning', $draft->summary_json['status'] ?? null);
        $this->assertGreaterThan(0, $draft->summary_json['same_level_consecutive_warnings_count'] ?? 0);
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

    protected function restDaysBetweenScheduledItems(ExamScheduleDraft $draft): array
    {
        $dates = $draft->items()
            ->orderBy('exam_date')
            ->pluck('exam_date')
            ->map(fn ($date) => $date->toDateString())
            ->unique()
            ->values();

        $restDays = [];

        for ($index = 1; $index < $dates->count(); $index++) {
            $restDays[] = max(0, \Carbon\Carbon::parse($dates->get($index - 1))->diffInDays(\Carbon\Carbon::parse($dates->get($index))) - 1);
        }

        return $restDays;
    }
}
