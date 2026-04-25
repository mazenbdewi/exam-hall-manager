<?php

namespace App\Services;

use App\Models\SubjectExamOffering;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HallDistributionPdfService
{
    public function __construct(
        protected ExamHallDistributionService $distributionService,
    ) {}

    public function downloadForOffering(SubjectExamOffering $offering): StreamedResponse
    {
        $summary = $this->distributionService->getSlotSummary($offering);
        $systemSetting = SystemSetting::current();
        $html = view('pdf.hall-distribution-slot', [
            'summary' => $summary,
            'systemSetting' => $systemSetting,
            'logoDataUri' => $this->getLogoDataUri($systemSetting->university_logo),
        ])->render();

        $pdf = $this->makePdf();
        $pdf->WriteHTML($html);

        $filename = sprintf(
            'hall-distribution-%s-%s.pdf',
            $summary['exam_date'],
            str_replace(':', '-', substr($summary['exam_start_time'], 0, 5)),
        );

        return response()->streamDownload(
            fn () => print $pdf->Output($filename, Destination::STRING_RETURN),
            $filename,
            ['Content-Type' => 'application/pdf'],
        );
    }

    public function downloadUnassignedForOffering(SubjectExamOffering $offering, ?array $summary = null): StreamedResponse
    {
        $summary ??= $this->distributionService->getSlotSummary($offering);
        $systemSetting = SystemSetting::current();
        $html = view('pdf.unassigned-students-slot', [
            'summary' => $summary,
            'systemSetting' => $systemSetting,
            'logoDataUri' => $this->getLogoDataUri($systemSetting->university_logo),
        ])->render();

        $pdf = $this->makePdf();
        $pdf->WriteHTML($html);

        $filename = sprintf(
            'unassigned-students-%s-%s.pdf',
            $summary['exam_date'],
            str_replace(':', '-', substr($summary['exam_start_time'], 0, 5)),
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

        $defaultConfig = (new ConfigVariables())->getDefaults();
        $defaultFontConfig = (new FontVariables())->getDefaults();
        $fontDir = array_merge(
            $defaultConfig['fontDir'],
            [resource_path('fonts')],
        );
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
            'default_font_size' => 11,
            'margin_top' => 12,
            'margin_right' => 10,
            'margin_bottom' => 12,
            'margin_left' => 10,
        ]);

        $pdf->autoScriptToLang = true;
        $pdf->autoLangToFont = true;
        $pdf->SetDirectionality('rtl');
        $pdf->mirrorMargins = false;
        $pdf->showImageErrors = true;

        return $pdf;
    }

    protected function getLogoDataUri(?string $path): ?string
    {
        if (! $path || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        $contents = Storage::disk('public')->get($path);
        $mimeType = Storage::disk('public')->mimeType($path) ?: 'image/png';

        return 'data:' . $mimeType . ';base64,' . base64_encode($contents);
    }
}
