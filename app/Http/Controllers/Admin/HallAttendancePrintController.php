<?php

namespace App\Http\Controllers\Admin;

use App\Filament\Resources\SubjectExamOfferings\SubjectExamOfferingResource;
use App\Http\Controllers\Controller;
use App\Models\HallAssignment;
use App\Services\HallAttendanceSheetService;
use App\Support\ExamCollegeScope;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HallAttendancePrintController extends Controller
{
    public function __construct(
        protected HallAttendanceSheetService $sheetService,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'college_id' => ['required', 'integer'],
            'exam_date' => ['required', 'date'],
            'exam_start_time' => ['required', 'string'],
            'allow_without_supervisors' => ['sometimes', 'boolean'],
        ]);

        $collegeId = (int) $filters['college_id'];
        $examDate = Carbon::parse($filters['exam_date'])->toDateString();
        $examStartTime = $this->normalizeTime($filters['exam_start_time']);

        $this->authorizeCollegeAccess($collegeId);

        $hallAssignments = $this->sheetService->hallAssignmentsForSlot($collegeId, $examDate, $examStartTime);

        abort_if($hallAssignments->isEmpty(), 404);

        return response()->view('admin.hall-attendance.print', $this->sheetService->viewData(
            hallAssignments: $hallAssignments,
            allowWithoutSupervisors: (bool) ($filters['allow_without_supervisors'] ?? false),
        ));
    }

    public function show(Request $request, HallAssignment $hallAssignment): Response
    {
        $this->authorizeCollegeAccess((int) $hallAssignment->college_id);

        $hallAssignment->loadMissing($this->sheetService->hallAssignmentRelations());

        return response()->view('admin.hall-attendance.print', $this->sheetService->viewData(
            hallAssignments: collect([$hallAssignment]),
            allowWithoutSupervisors: $request->boolean('allow_without_supervisors'),
        ));
    }

    protected function authorizeCollegeAccess(int $collegeId): void
    {
        abort_unless(SubjectExamOfferingResource::canViewAny(), 403);
        abort_unless(ExamCollegeScope::userCanAccessCollegeId(auth()->user(), $collegeId), 403);
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
