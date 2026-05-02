<?php

namespace App\Services;

use App\Exports\StudentDistributionUnassignedExport;
use App\Models\StudentDistributionRun;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentDistributionRunReportService
{
    public function __construct(
        protected ExamHallDistributionService $distributionService,
    ) {}

    public function downloadSummaryPdf(StudentDistributionRun $run): StreamedResponse
    {
        $this->auditExport($run, 'student_distribution_summary', 'pdf');

        return $this->downloadPdf(
            view: 'pdf.student-distribution-run-summary',
            filenamePrefix: 'student-distribution-summary',
            run: $run,
        );
    }

    public function downloadUnassignedPdf(StudentDistributionRun $run): StreamedResponse
    {
        $this->auditExport($run, 'student_distribution_unassigned', 'pdf');

        return $this->downloadPdf(
            view: 'pdf.student-distribution-run-unassigned',
            filenamePrefix: 'unassigned-students',
            run: $run,
            data: [
                'unassignedStudents' => $this->distributionService->unassignedStudentsForRun($run),
            ],
        );
    }

    public function downloadUnassignedExcel(StudentDistributionRun $run): BinaryFileResponse
    {
        $filename = 'unassigned-students-'.$run->id.'-'.now()->format('Y-m-d-H-i').'.xlsx';

        $this->auditExport($run, 'student_distribution_unassigned', 'excel');

        return Excel::download(new StudentDistributionUnassignedExport($run), $filename);
    }

    protected function auditExport(StudentDistributionRun $run, string $reportType, string $format): void
    {
        app(AuditLogService::class)->log(
            action: "export.{$format}",
            module: 'exports',
            auditable: $run,
            description: 'تصدير تقرير',
            metadata: [
                'report_type' => $reportType,
                'faculty_id' => $run->college_id,
                'date_range' => collect([$run->from_date?->format('Y-m-d'), $run->to_date?->format('Y-m-d')])->filter()->implode(' - '),
            ],
        );
    }

    protected function downloadPdf(string $view, string $filenamePrefix, StudentDistributionRun $run, array $data = []): StreamedResponse
    {
        $run->loadMissing(['college', 'executor', 'issues.subjectExamOffering.subject']);
        $systemSetting = SystemSetting::current();

        $html = view($view, [
            'run' => $run,
            'summary' => $run->summary_json ?? [],
            'systemSetting' => $systemSetting,
            'logoDataUri' => $this->getLogoDataUri($systemSetting->university_logo),
            ...$data,
        ])->render();

        $pdf = $this->makePdf();
        $pdf->WriteHTML($html);

        $filename = $filenamePrefix.'-'.$run->id.'-'.now()->format('Y-m-d-H-i').'.pdf';

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
        $fontDir = array_merge($defaultConfig['fontDir'], [resource_path('fonts')]);
        $fontData = $defaultFontConfig['fontdata'] + [
            'notosansarabic' => [
                'R' => 'NotoSansArabic-Regular.ttf',
                'B' => 'NotoSansArabic-Bold.ttf',
                'useOTL' => 0xFF,
                'useKashida' => 75,
            ],
        ];

        $pdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'orientation' => 'P',
            'tempDir' => $tempDir,
            'fontDir' => $fontDir,
            'fontdata' => $fontData,
            'default_font' => 'notosansarabic',
            'default_font_size' => 10,
            'margin_top' => 10,
            'margin_right' => 8,
            'margin_bottom' => 10,
            'margin_left' => 8,
        ]);

        $pdf->autoScriptToLang = true;
        $pdf->autoLangToFont = true;
        $pdf->SetDirectionality('rtl');

        return $pdf;
    }

    protected function getLogoDataUri(?string $path): ?string
    {
        if (! $path || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        $contents = Storage::disk('public')->get($path);
        $mimeType = Storage::disk('public')->mimeType($path) ?: 'image/png';

        return 'data:'.$mimeType.';base64,'.base64_encode($contents);
    }
}
