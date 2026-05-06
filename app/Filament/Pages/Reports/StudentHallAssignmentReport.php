<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Resources\SubjectExamOfferings\SubjectExamOfferingResource;
use App\Http\Controllers\Admin\StudentHallAssignmentReportController;
use App\Models\College;
use App\Models\Department;
use App\Models\SubjectExamOffering;
use App\Support\ExamCollegeScope;
use BackedEnum;
use Carbon\Carbon;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

class StudentHallAssignmentReport extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $slug = 'reports/student-hall-assignment';

    protected string $view = 'filament.pages.reports.student-hall-assignment-report';

    public ?int $college_id = null;

    public ?int $department_id = null;

    public ?string $exam_date = null;

    public ?string $exam_start_time = null;

    public ?int $subject_exam_offering_id = null;

    public function mount(): void
    {
        $this->college_id = ExamCollegeScope::currentCollegeId()
            ?? College::query()->orderBy('name')->value('id');
        $this->exam_date = SubjectExamOffering::query()
            ->whereHas('subject', fn (Builder $query) => $query->where('college_id', $this->college_id ?: 0))
            ->min('exam_date');
        $this->exam_start_time = $this->firstTimeForDate();
        $this->subject_exam_offering_id = $this->firstOfferingForFilters()?->getKey();
    }

    public static function getNavigationGroup(): ?string
    {
        return __('exam.navigation.reports_printing');
    }

    public static function getNavigationSort(): ?int
    {
        return 23;
    }

    public static function getNavigationLabel(): string
    {
        return 'كشف توزيع الطلاب على القاعات';
    }

    public function getTitle(): string|Htmlable
    {
        return 'كشف توزيع الطلاب على القاعات حسب المادة والفترة';
    }

    public function getHeading(): string
    {
        return 'كشف توزيع الطلاب على القاعات حسب المادة والفترة';
    }

    public static function canAccess(): bool
    {
        return SubjectExamOfferingResource::canViewAny();
    }

    public function updatedCollegeId(): void
    {
        $this->department_id = null;
        $this->exam_date = SubjectExamOffering::query()
            ->whereHas('subject', fn (Builder $query) => $query->where('college_id', $this->college_id ?: 0))
            ->min('exam_date');
        $this->exam_start_time = $this->firstTimeForDate();
        $this->subject_exam_offering_id = $this->firstOfferingForFilters()?->getKey();
    }

    public function updatedDepartmentId(): void
    {
        $this->subject_exam_offering_id = $this->firstOfferingForFilters()?->getKey();
    }

    public function updatedExamDate(): void
    {
        $this->exam_start_time = $this->firstTimeForDate();
        $this->subject_exam_offering_id = $this->firstOfferingForFilters()?->getKey();
    }

    public function updatedExamStartTime(): void
    {
        $this->subject_exam_offering_id = $this->firstOfferingForFilters()?->getKey();
    }

    public function resetFilters(): void
    {
        $this->department_id = null;
        $this->mount();
    }

    public function collegeOptions(): array
    {
        return College::query()
            ->when(! ExamCollegeScope::isSuperAdmin(), fn (Builder $query) => $query->whereKey(ExamCollegeScope::currentCollegeId()))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function departmentOptions(): array
    {
        return Department::query()
            ->when($this->college_id, fn (Builder $query) => $query->where('college_id', $this->college_id))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function dateOptions(): array
    {
        return $this->offeringsBaseQuery()
            ->select('exam_date')
            ->distinct()
            ->orderBy('exam_date')
            ->pluck('exam_date')
            ->mapWithKeys(fn ($date): array => [
                substr((string) $date, 0, 10) => substr((string) $date, 0, 10),
            ])
            ->all();
    }

    public function timeOptions(): array
    {
        if (! $this->exam_date) {
            return [];
        }

        return $this->offeringsBaseQuery()
            ->whereDate('exam_date', $this->exam_date)
            ->select('exam_start_time')
            ->distinct()
            ->orderBy('exam_start_time')
            ->pluck('exam_start_time')
            ->mapWithKeys(function ($time): array {
                $normalized = $this->normalizeTime($time);

                return [$normalized => substr($normalized, 0, 5)];
            })
            ->all();
    }

    public function offeringOptions(): array
    {
        return $this->offeringsForFilters()
            ->get()
            ->mapWithKeys(fn (SubjectExamOffering $offering): array => [
                $offering->getKey() => trim(($offering->subject?->name ?? '—').' - '.($offering->subject?->department?->name ?? '')),
            ])
            ->all();
    }

    public function printUrl(): ?string
    {
        if (! $this->subject_exam_offering_id) {
            return null;
        }

        return route('filament.adminpanel.reports.student-hall-assignments.print', [
            'subject_exam_offering_id' => $this->subject_exam_offering_id,
        ]);
    }

    public function pdfUrl(): ?string
    {
        if (! $this->subject_exam_offering_id) {
            return null;
        }

        return route('filament.adminpanel.reports.student-hall-assignments.print', [
            'subject_exam_offering_id' => $this->subject_exam_offering_id,
            'download' => 1,
        ]);
    }

    public function previewEmptyMessage(): string
    {
        if (! $this->subject_exam_offering_id) {
            return 'اختر المادة والتاريخ والفترة لعرض الكشف.';
        }

        $offering = SubjectExamOffering::query()
            ->withCount('examStudents')
            ->find($this->subject_exam_offering_id);

        if (! $offering || $offering->exam_students_count === 0) {
            return 'لا يوجد طلاب ضمن هذه المادة والفترة.';
        }

        return 'لم يتم توزيع الطلاب على القاعات بعد لهذه المادة والفترة.';
    }

    public function previewRows(): array
    {
        if (! $this->subject_exam_offering_id) {
            return [];
        }

        $offering = SubjectExamOffering::query()->find($this->subject_exam_offering_id);

        if (! $offering) {
            return [];
        }

        return app(StudentHallAssignmentReportController::class)
            ->rowsForOffering($offering)
            ->take(12)
            ->all();
    }

    protected function firstTimeForDate(): ?string
    {
        if (! $this->exam_date) {
            return null;
        }

        $time = $this->offeringsBaseQuery()
            ->whereDate('exam_date', $this->exam_date)
            ->orderBy('exam_start_time')
            ->value('exam_start_time');

        return $time ? $this->normalizeTime($time) : null;
    }

    protected function firstOfferingForFilters(): ?SubjectExamOffering
    {
        return $this->offeringsForFilters()->first();
    }

    protected function offeringsForFilters(): Builder
    {
        return $this->offeringsBaseQuery()
            ->join('subjects', 'subjects.id', '=', 'subject_exam_offerings.subject_id')
            ->leftJoin('departments', 'departments.id', '=', 'subjects.department_id')
            ->select('subject_exam_offerings.*')
            ->with(['subject.department'])
            ->when($this->exam_date, fn (Builder $query) => $query->whereDate('subject_exam_offerings.exam_date', $this->exam_date))
            ->when($this->exam_start_time, fn (Builder $query) => $query->whereTime('subject_exam_offerings.exam_start_time', $this->exam_start_time))
            ->orderBy('subject_exam_offerings.exam_date')
            ->orderBy('subject_exam_offerings.exam_start_time')
            ->orderBy('departments.name')
            ->orderBy('subjects.name');
    }

    protected function offeringsBaseQuery(): Builder
    {
        return SubjectExamOffering::query()
            ->whereHas('subject', function (Builder $query): void {
                $query
                    ->when($this->college_id, fn (Builder $query) => $query->where('college_id', $this->college_id))
                    ->when(! ExamCollegeScope::isSuperAdmin(), fn (Builder $query) => $query->where('college_id', ExamCollegeScope::currentCollegeId()))
                    ->when($this->department_id, fn (Builder $query) => $query->where('department_id', $this->department_id));
            });
    }

    protected function normalizeTime(mixed $time): string
    {
        $time = trim((string) $time);

        if ($time === '') {
            return '';
        }

        try {
            return Carbon::parse($time)->format('H:i:s');
        } catch (\Throwable) {
            return strlen($time) === 5 ? $time.':00' : $time;
        }
    }
}
