<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserGuidePdfService
{
    public function download(): StreamedResponse
    {
        $filename = 'latakia-exam-user-guide.pdf';
        $pdf = $this->makePdf();
        $pdf->SetTitle(__('help.guide.title'));
        $pdf->SetAuthor(__('help.guide.title'));
        $pdf->SetHTMLFooter($this->footerHtml());
        $pdf->WriteHTML($this->html());

        return response()->streamDownload(
            fn () => print $pdf->Output($filename, Destination::STRING_RETURN),
            $filename,
            ['Content-Type' => 'application/pdf'],
        );
    }

    protected function html(): string
    {
        return view('pdf.user-guide', [
            'guide' => __('help.guide'),
        ])->render();
    }

    protected function makePdf(): Mpdf
    {
        $tempDir = storage_path('app/mpdf-temp');

        if (! File::exists($tempDir)) {
            File::makeDirectory($tempDir, 0755, true);
        }

        $defaultConfig = (new ConfigVariables())->getDefaults();
        $defaultFontConfig = (new FontVariables())->getDefaults();
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
            'default_font_size' => 11,
            'margin_top' => 14,
            'margin_right' => 13,
            'margin_bottom' => 16,
            'margin_left' => 13,
        ]);

        $pdf->autoScriptToLang = true;
        $pdf->autoLangToFont = true;
        $pdf->SetDirectionality('rtl');

        return $pdf;
    }

    protected function footerHtml(): string
    {
        return '<div style="font-family: notosansarabic; font-size: 9px; color: #64748b; text-align: center; direction: rtl;">'
            . __('help.guide.footer_page')
            . ' {PAGENO} / {nbpg}</div>';
    }

}
