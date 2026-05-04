<?php

namespace App\Http\Controllers\Admin;

use App\Filament\Resources\FixedExamPrograms\FixedExamProgramResource;
use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\FixedExamProgram;
use App\Models\Semester;
use App\Support\ExamCollegeScope;
use App\Support\ShieldPermission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ExamSchedulePrintController extends Controller
{
    public function __invoke(Request $request): RedirectResponse|Response
    {
        $this->authorizePrintAccess();

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
}
