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

class InvigilatorDistributionPdfService
{
    public function __construct(
        protected InvigilatorDistributionService $distributionService,
    ) {}

    public function downloadByInvigilator(College $college, ?string $examDate = null, ?string $startTime = null, ?string $fromDate = null, ?string $toDate = null): StreamedResponse
    {
        return $this->download(
            'pdf.invigilator-distribution-by-invigilator',
            'invigilator-distribution-by-invigilator',
            $college,
            $examDate,
            $startTime,
            $fromDate,
            $toDate,
        );
    }

    public function downloadByHall(College $college, ?string $examDate = null, ?string $startTime = null, ?string $fromDate = null, ?string $toDate = null): StreamedResponse
    {
        return $this->download(
            'pdf.invigilator-distribution-by-hall',
            'invigilator-distribution-by-hall',
            $college,
            $examDate,
            $startTime,
            $fromDate,
            $toDate,
        );
    }

    public function downloadByDay(College $college, ?string $examDate = null, ?string $startTime = null, ?string $fromDate = null, ?string $toDate = null): StreamedResponse
    {
        return $this->download(
            'pdf.invigilator-distribution-by-day',
            'invigilator-distribution-by-day',
            $college,
            $examDate,
            $startTime,
            $fromDate,
            $toDate,
        );
    }

    public function downloadShortage(College $college, ?string $examDate = null, ?string $startTime = null, ?string $fromDate = null, ?string $toDate = null): StreamedResponse
    {
        return $this->download(
            'pdf.invigilator-distribution-shortage',
            'invigilator-distribution-shortage',
            $college,
            $examDate,
            $startTime,
            $fromDate,
            $toDate,
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

    protected function download(string $view, string $filenamePrefix, College $college, ?string $examDate, ?string $startTime, ?string $fromDate, ?string $toDate): StreamedResponse
    {
        $summary = $this->distributionService->getSummary($college, $examDate, $startTime, $fromDate, $toDate);
        $institution = InstitutionSettings::make();
        $html = view($view, [
            'summary' => $summary,
            'systemSetting' => $institution->reportContext($college->name),
            'logoDataUri' => $institution->logoDataUri(),
            'reportDateRange' => $this->reportDateRange($summary),
        ])->render();

        $pdf = $this->makePdf();
        $this->writeHtml($pdf, $html);

        $filename = $filenamePrefix.'-'.now()->format('Y-m-d-H-i').'.pdf';

        return response()->streamDownload(
            fn () => print $pdf->Output($filename, Destination::STRING_RETURN),
            $filename,
            ['Content-Type' => 'application/pdf'],
        );
    }

    protected function writeHtml(Mpdf $pdf, string $html): void
    {
        $this->prepareHtmlParserLimits(strlen($html));

        foreach ($this->htmlChunks($html) as $chunk) {
            if ($chunk === '') {
                continue;
            }

            $pdf->WriteHTML($chunk);
        }
    }

    protected function prepareHtmlParserLimits(int $htmlLength): void
    {
        $backtrackLimit = max(
            (int) ini_get('pcre.backtrack_limit'),
            min(100_000_000, max(5_000_000, $htmlLength * 2)),
        );

        ini_set('pcre.backtrack_limit', (string) $backtrackLimit);
        ini_set('pcre.recursion_limit', (string) max((int) ini_get('pcre.recursion_limit'), 1_000_000));
    }

    /**
     * mPDF can fail on very large single WriteHTML() calls because PCRE parses
     * the whole document at once. Keep the rendered report intact, but pass it
     * in chunks that end on common block boundaries whenever possible.
     *
     * @return array<int, string>
     */
    protected function htmlChunks(string $html, int $maxLength = 500_000): array
    {
        if (strlen($html) <= $maxLength) {
            return [$html];
        }

        $chunks = [];
        $remaining = $html;

        while (strlen($remaining) > $maxLength) {
            $window = substr($remaining, 0, $maxLength);
            $splitAt = $this->lastHtmlBoundary($window);

            if ($splitAt <= 0) {
                $splitAt = $maxLength;
            }

            $chunks[] = substr($remaining, 0, $splitAt);
            $remaining = substr($remaining, $splitAt);
        }

        $chunks[] = $remaining;

        return $chunks;
    }

    protected function lastHtmlBoundary(string $html): int
    {
        $lastPosition = 0;

        foreach (['</tr>', '</table>', '</div>', '</p>', '</style>', '</head>'] as $boundary) {
            $position = strripos($html, $boundary);

            if ($position === false) {
                continue;
            }

            $lastPosition = max($lastPosition, $position + strlen($boundary));
        }

        return $lastPosition;
    }

    protected function reportDateRange(array $summary): string
    {
        if (filled($summary['from_date'] ?? null) || filled($summary['to_date'] ?? null)) {
            return __('exam.fields.period').': '.($summary['from_date'] ?: '—').' - '.($summary['to_date'] ?: '—');
        }

        $slots = collect($summary['slots'] ?? []);

        if ($slots->count() === 1) {
            $slot = $slots->first();

            return __('exam.fields.exam_date').': '.($slot['exam_date'] ?? '—')
                .' | '.__('exam.fields.exam_start_time').': '.substr((string) ($slot['start_time'] ?? ''), 0, 5);
        }

        if ($slots->isNotEmpty()) {
            return __('exam.fields.period').': '.$slots->pluck('exam_date')->filter()->min().' - '.$slots->pluck('exam_date')->filter()->max();
        }

        return __('exam.fields.period').': —';
    }
}
