<?php

namespace App\Http\Controllers\Admin;

use App\Filament\Pages\ReportsDashboard;
use App\Filament\Resources\FixedExamPrograms\FixedExamProgramResource;
use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\College;
use App\Models\Department;
use App\Models\ExamScheduleDraft;
use App\Models\FixedExamProgram;
use App\Models\Semester;
use App\Services\FixedExamProgramSnapshotService;
use App\Support\ExamCollegeScope;
use App\Support\InstitutionSettings;
use App\Support\ShieldPermission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExamSchedulePrintController extends Controller
{
    public function __invoke(Request $request): RedirectResponse|Response|StreamedResponse
    {
        $this->authorizePrintAccess();

        if ($request->query('source') === 'draft') {
            return $this->printDraft($request);
        }

        $fixedProgramId = $request->integer('fixed_exam_program_id') ?: null;

        if ($fixedProgramId) {
            $fixedProgram = FixedExamProgram::query()->findOrFail($fixedProgramId);

            abort_unless(ExamCollegeScope::userCanAccessCollegeId(auth()->user(), $fixedProgram->college_id), 403);

            return redirect()->route('filament.adminpanel.fixed-exam-programs.print', [
                'fixedExamProgram' => $fixedProgram,
                'download' => $request->boolean('download') ? 1 : null,
            ]);
        }

        $fixedProgram = $this->latestMatchingFixedProgram($request);

        if (! $fixedProgram) {
            return redirect(FixedExamProgramResource::getUrl('index'));
        }

        return redirect()->route('filament.adminpanel.fixed-exam-programs.print', [
            'fixedExamProgram' => $fixedProgram,
            'download' => $request->boolean('download') ? 1 : null,
        ]);
    }

    protected function authorizePrintAccess(): void
    {
        $user = auth()->user();

        abort_unless(
            ExamCollegeScope::isSuperAdmin($user)
                || ($user?->can(ShieldPermission::resource('viewAny', 'FixedExamProgram')) ?? false)
                || ($user?->can('view_exam_schedule_generator') ?? false),
            403,
        );
    }

    protected function latestMatchingFixedProgram(Request $request): ?FixedExamProgram
    {
        $collegeId = $request->integer('college_id') ?: ExamCollegeScope::currentCollegeId();

        if (! ExamCollegeScope::isSuperAdmin()) {
            $collegeId = ExamCollegeScope::currentCollegeId();
        }

        if ($collegeId && ! ExamCollegeScope::userCanAccessCollegeId(auth()->user(), $collegeId)) {
            abort(403);
        }

        return FixedExamProgram::query()
            ->when($collegeId, fn ($query) => $query->where('college_id', $collegeId))
            ->when($request->integer('department_id'), fn ($query, int $departmentId) => $query->where('department_id', $departmentId))
            ->where('academic_year_id', $request->integer('academic_year_id')
                ?: AcademicYear::query()->where('is_current', true)->value('id')
                ?: AcademicYear::query()->where('is_active', true)->latest('id')->value('id'))
            ->where('semester_id', $request->integer('semester_id')
                ?: Semester::query()->where('is_active', true)->orderBy('sort_order')->value('id'))
            ->latest('fixed_at')
            ->latest('id')
            ->first();
    }

    protected function printDraft(Request $request): Response|StreamedResponse
    {
        $filters = $this->normalizedDraftFilters($request);
        $draft = $this->latestMatchingDraft($filters);

        if (! $draft) {
            return redirect(ReportsDashboard::getUrl());
        }

        $snapshot = app(FixedExamProgramSnapshotService::class)->snapshotFromDraft(
            draft: $draft,
            departmentId: $filters['department_id'],
            documentStatus: 'draft',
        );

        $data = $this->draftViewData($draft, $snapshot, $filters);

        if ($request->boolean('download')) {
            return $this->downloadDraftPdf($draft, $data);
        }

        return response()->view('admin.exam-schedules.print', $data);
    }

    /**
     * @return array{college_id:int,department_id:?int,academic_year_id:int,semester_id:int}
     */
    protected function normalizedDraftFilters(Request $request): array
    {
        $collegeId = $request->integer('college_id')
            ?: ExamCollegeScope::currentCollegeId()
            ?: (int) College::query()->orderBy('name')->value('id');

        if (! ExamCollegeScope::isSuperAdmin()) {
            $collegeId = (int) ExamCollegeScope::currentCollegeId();
        }

        abort_unless(ExamCollegeScope::userCanAccessCollegeId(auth()->user(), $collegeId), 403);

        $departmentId = $request->integer('department_id') ?: null;

        if ($departmentId) {
            abort_unless(
                Department::query()->whereKey($departmentId)->where('college_id', $collegeId)->exists(),
                403,
            );
        }

        return [
            'college_id' => $collegeId,
            'department_id' => $departmentId,
            'academic_year_id' => $request->integer('academic_year_id')
                ?: (int) AcademicYear::query()->where('is_current', true)->value('id')
                ?: (int) AcademicYear::query()->where('is_active', true)->latest('id')->value('id'),
            'semester_id' => $request->integer('semester_id')
                ?: (int) Semester::query()->where('is_active', true)->orderBy('sort_order')->value('id'),
        ];
    }

    /**
     * @param  array{college_id:int,department_id:?int,academic_year_id:int,semester_id:int}  $filters
     */
    protected function latestMatchingDraft(array $filters): ?ExamScheduleDraft
    {
        return ExamScheduleDraft::query()
            ->with([
                'college',
                'academicYear',
                'semester',
                'items.department',
                'items.subject.department',
                'items.subject.studyLevel',
            ])
            ->where('faculty_id', $filters['college_id'])
            ->where('academic_year_id', $filters['academic_year_id'])
            ->where('semester_id', $filters['semester_id'])
            ->whereIn('status', ['draft', 'generated'])
            ->when($filters['department_id'], function ($query, int $departmentId): void {
                $query->whereHas('items', function ($itemsQuery) use ($departmentId): void {
                    $itemsQuery->where(function ($departmentQuery) use ($departmentId): void {
                        $departmentQuery
                            ->where('department_id', $departmentId)
                            ->orWhereHas('subject', fn ($subjectQuery) => $subjectQuery->where('department_id', $departmentId));
                    });
                });
            })
            ->latest('updated_at')
            ->latest('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array{college_id:int,department_id:?int,academic_year_id:int,semester_id:int}  $filters
     * @return array<string, mixed>
     */
    protected function draftViewData(ExamScheduleDraft $draft, array $snapshot, array $filters): array
    {
        $meta = data_get($snapshot, 'meta', []);
        $institution = InstitutionSettings::make();
        $routeFilters = array_filter([
            'source' => 'draft',
            'college_id' => $filters['college_id'],
            'department_id' => $filters['department_id'],
            'academic_year_id' => $filters['academic_year_id'],
            'semester_id' => $filters['semester_id'],
        ], fn ($value): bool => filled($value));

        return [
            'fixedProgram' => null,
            'printMode' => 'draft',
            'draft' => $draft,
            'snapshot' => $snapshot,
            'filters' => $filters,
            'college' => (object) ['name' => data_get($meta, 'college_name', $draft->college?->name)],
            'department' => (object) ['name' => data_get($meta, 'department_name', 'كل الأقسام')],
            'academicYear' => (object) ['name' => data_get($meta, 'academic_year', $draft->academicYear?->name)],
            'semester' => (object) ['name' => data_get($meta, 'semester', $draft->semester?->name)],
            'levels' => $this->objects(data_get($snapshot, 'levels', [])),
            'rows' => collect(data_get($snapshot, 'rows', [])),
            'offerings' => collect(data_get($snapshot, 'entries', [])),
            'systemSetting' => $institution->reportContext(
                collegeName: data_get($meta, 'college_name', $draft->college?->name),
                departmentName: data_get($meta, 'department_name', 'كل الأقسام'),
                academicYear: data_get($meta, 'academic_year', $draft->academicYear?->name),
            ),
            'logoDataUri' => $institution->logoDataUri(),
            'regularFontDataUri' => $this->fontDataUri('NotoSansArabic-Regular.ttf'),
            'boldFontDataUri' => $this->fontDataUri('NotoSansArabic-Bold.ttf'),
            'filterOptions' => $this->filterOptions($filters['college_id']),
            'fixedProgramsUrl' => FixedExamProgramResource::getUrl('index'),
            'printUrl' => route('filament.adminpanel.exam-schedules.print', $routeFilters),
            'pdfUrl' => route('filament.adminpanel.exam-schedules.print', [
                ...$routeFilters,
                'download' => 1,
            ]),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return Collection<int, object>
     */
    protected function objects(array $items): Collection
    {
        return collect($items)->map(fn (array $item): object => (object) $item);
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function filterOptions(int $collegeId): array
    {
        return [
            'colleges' => College::query()
                ->when(! ExamCollegeScope::isSuperAdmin(), fn ($query) => $query->whereKey(ExamCollegeScope::currentCollegeId()))
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all(),
            'departments' => Department::query()
                ->where('college_id', $collegeId)
                ->where('is_active', true)
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all(),
            'academicYears' => AcademicYear::query()
                ->where('is_active', true)
                ->latest('is_current')
                ->latest('id')
                ->pluck('name', 'id')
                ->all(),
            'semesters' => Semester::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->pluck('name', 'id')
                ->all(),
        ];
    }

    protected function fontDataUri(string $filename): ?string
    {
        $path = resource_path('fonts/'.$filename);

        if (! File::exists($path)) {
            return null;
        }

        return 'data:font/ttf;base64,'.base64_encode((string) File::get($path));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function downloadDraftPdf(ExamScheduleDraft $draft, array $data): StreamedResponse
    {
        $pdf = $this->makePdf();
        $pdf->WriteHTML(view('admin.exam-schedules.print', [
            ...$data,
            'isPdfDownload' => true,
        ])->render());

        $filename = Str::slug('draft-exam-schedule').'-'.$draft->id.'.pdf';

        return response()->streamDownload(
            fn () => print $pdf->Output($filename, Destination::STRING_RETURN),
            $filename,
            ['Content-Type' => 'application/pdf'],
        );
    }

    protected function makePdf(): Mpdf
    {
        $tempDir = storage_path('app/mpdf-temp');

        if (! File::exists($tempDir)) {
            File::makeDirectory($tempDir, 0755, true);
        }

        $defaultConfig = (new ConfigVariables)->getDefaults();
        $defaultFontConfig = (new FontVariables)->getDefaults();

        $pdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4-L',
            'tempDir' => $tempDir,
            'fontDir' => array_merge($defaultConfig['fontDir'], [resource_path('fonts')]),
            'fontdata' => $defaultFontConfig['fontdata'] + [
                'notosansarabic' => [
                    'R' => 'NotoSansArabic-Regular.ttf',
                    'B' => 'NotoSansArabic-Bold.ttf',
                    'useOTL' => 0xFF,
                    'useKashida' => 75,
                ],
            ],
            'default_font' => 'notosansarabic',
            'default_font_size' => 10,
            'margin_top' => 8,
            'margin_right' => 8,
            'margin_bottom' => 8,
            'margin_left' => 8,
        ]);

        $pdf->autoScriptToLang = true;
        $pdf->autoLangToFont = true;
        $pdf->SetDirectionality('rtl');

        return $pdf;
    }
}
