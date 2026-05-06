<?php

namespace App\Http\Controllers\Admin;

use App\Filament\Resources\SubjectExamOfferings\SubjectExamOfferingResource;
use App\Http\Controllers\Controller;
use App\Models\ExamStudentHallAssignment;
use App\Models\SubjectExamOffering;
use App\Support\ExamCollegeScope;
use App\Support\InstitutionSettings;
use Collator;
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

class StudentHallAssignmentReportController extends Controller
{
    public function __invoke(Request $request): Response|StreamedResponse
    {
        $filters = $request->validate([
            'subject_exam_offering_id' => ['required', 'integer', 'exists:subject_exam_offerings,id'],
        ]);

        abort_unless(SubjectExamOfferingResource::canViewAny(), 403);

        $offering = SubjectExamOffering::query()
            ->with([
                'subject.college',
                'subject.department',
                'subject.studyLevel',
                'academicYear',
                'semester',
                'examScheduleDraftItem',
                'examStudents',
            ])
            ->findOrFail((int) $filters['subject_exam_offering_id']);

        abort_unless(ExamCollegeScope::userCanAccessCollegeId(auth()->user(), $offering->subject?->college_id), 403);

        $rows = $this->rowsForOffering($offering);
        $hasStudents = $offering->examStudents->isNotEmpty();
        $hasMissingHalls = $rows->contains(fn (array $row): bool => blank($row['hall_name']));
        $emptyMessage = null;

        if ($rows->isEmpty() && $hasStudents) {
            $emptyMessage = 'لم يتم توزيع الطلاب على القاعات بعد لهذه المادة والفترة.';
        } elseif ($rows->isEmpty()) {
            $emptyMessage = 'لا يوجد طلاب ضمن هذه المادة والفترة.';
        } elseif ($hasMissingHalls) {
            $emptyMessage = 'توجد سجلات توزيع بدون قاعات مرتبطة. يرجى مراجعة توزيع القاعات قبل الطباعة.';
        }

        $institution = InstitutionSettings::make();
        $data = [
            'systemSetting' => $institution->reportContext(
                collegeName: $offering->subject?->college?->name,
                departmentName: $offering->subject?->department?->name,
                academicYear: $offering->academicYear?->name,
            ),
            'logoDataUri' => $institution->logoDataUri(),
            'offering' => $offering,
            'rows' => $rows->all(),
            'pages' => $this->pages($rows),
            'emptyMessage' => $emptyMessage,
            'periodLabel' => $this->periodLabel($offering),
            'printTitle' => 'كشف توزيع الطلاب على القاعات حسب المادة والفترة',
            'printUrl' => route('filament.adminpanel.reports.student-hall-assignments.print', [
                'subject_exam_offering_id' => $offering->getKey(),
            ]),
            'pdfUrl' => route('filament.adminpanel.reports.student-hall-assignments.print', [
                'subject_exam_offering_id' => $offering->getKey(),
                'download' => 1,
            ]),
        ];

        if ($request->boolean('download')) {
            return $this->downloadPdf($offering, $data);
        }

        return response()->view('admin.reports.student-hall-assignment-print', $data);
    }

    /**
     * @return Collection<int, array{student_number:string,full_name:string,seat_number:int,hall_name:string}>
     */
    public function rowsForOffering(SubjectExamOffering $offering): Collection
    {
        $assignments = ExamStudentHallAssignment::query()
            ->with(['examStudent', 'hallAssignment.examHall'])
            ->where('subject_exam_offering_id', $offering->getKey())
            ->get()
            ->filter(fn (ExamStudentHallAssignment $assignment): bool => filled($assignment->examStudent?->full_name));

        return $this->sortAssignmentsByStudentName($assignments)
            ->values()
            ->map(fn (ExamStudentHallAssignment $assignment, int $index): array => [
                'student_number' => (string) ($assignment->examStudent?->student_number ?? ''),
                'full_name' => (string) ($assignment->examStudent?->full_name ?? ''),
                'seat_number' => $index + 1,
                'hall_name' => (string) ($assignment->hallAssignment?->examHall?->name ?? ''),
            ]);
    }

    /**
     * @param  Collection<int, ExamStudentHallAssignment>  $assignments
     * @return Collection<int, ExamStudentHallAssignment>
     */
    protected function sortAssignmentsByStudentName(Collection $assignments): Collection
    {
        if (class_exists(Collator::class)) {
            $collator = new Collator('ar_SY');

            return $assignments->sort(function (ExamStudentHallAssignment $first, ExamStudentHallAssignment $second) use ($collator): int {
                $nameComparison = $collator->compare(
                    $this->normalizedName($first->examStudent?->full_name),
                    $this->normalizedName($second->examStudent?->full_name),
                );

                return $nameComparison !== 0
                    ? $nameComparison
                    : strcmp((string) $first->examStudent?->student_number, (string) $second->examStudent?->student_number);
            });
        }

        return $assignments->sortBy([
            fn (ExamStudentHallAssignment $assignment): string => $this->normalizedName($assignment->examStudent?->full_name),
            fn (ExamStudentHallAssignment $assignment): string => (string) $assignment->examStudent?->student_number,
        ]);
    }

    protected function normalizedName(mixed $name): string
    {
        return preg_replace('/\s+/u', ' ', trim((string) $name)) ?: '';
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array{right: array<int, array<string, mixed>>, left: array<int, array<string, mixed>>}>
     */
    protected function pages(Collection $rows): array
    {
        return $rows
            ->chunk(60)
            ->map(fn (Collection $pageRows): array => [
                'right' => $pageRows->take(30)->values()->all(),
                'left' => $pageRows->slice(30)->values()->all(),
            ])
            ->values()
            ->all();
    }

    protected function periodLabel(SubjectExamOffering $offering): string
    {
        $start = $this->displayTime($offering->exam_start_time);
        $end = $this->displayTime($offering->examScheduleDraftItem?->end_time);

        return filled($end) ? $start.' - '.$end : $start;
    }

    protected function displayTime(mixed $time): string
    {
        $time = trim((string) $time);

        return $time === '' ? '—' : substr($time, 0, 5);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function downloadPdf(SubjectExamOffering $offering, array $data): StreamedResponse
    {
        $pdf = $this->makePdf();
        $pdf->WriteHTML(view('admin.reports.student-hall-assignment-print', [
            ...$data,
            'isPdfDownload' => true,
        ])->render());

        $filename = sprintf(
            'student-hall-assignment-%s-%s.pdf',
            $offering->getKey(),
            Str::slug((string) ($offering->subject?->name ?? 'subject')),
        );

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
            'format' => 'A4',
            'orientation' => 'P',
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
