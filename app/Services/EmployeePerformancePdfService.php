<?php

namespace App\Services;

use App\Models\College;
use App\Support\InstitutionSettings;
use Illuminate\Support\Facades\File;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeePerformancePdfService
{
    public function __construct(
        protected EmployeePerformanceReportService $reportService,
    ) {}

    public function download(College $college, ?string $fromDate = null, ?string $toDate = null): StreamedResponse
    {
        $report = $this->reportService->report($college, $fromDate, $toDate);
        $institution = InstitutionSettings::make();
        $html = view('pdf.employee-performance-report', [
            'report' => $report,
            'systemSetting' => $institution->reportContext($college->name),
            'logoDataUri' => $institution->logoDataUri(),
            'reportDateRange' => $this->reportDateRange($report),
        ])->render();

        $pdf = $this->makePdf();
        $pdf->WriteHTML($html);

        $filename = 'employee-performance-report-'.now()->format('Y-m-d-H-i').'.pdf';

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
            'orientation' => 'L',
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

    /**
     * @param  array<string, mixed>  $report
     */
    protected function reportDateRange(array $report): string
    {
        return __('exam.fields.period').': '.($report['from_date'] ?: '—').' - '.($report['to_date'] ?: '—');
    }
}
