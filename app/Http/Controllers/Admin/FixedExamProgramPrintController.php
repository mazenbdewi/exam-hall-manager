<?php

namespace App\Http\Controllers\Admin;

use App\Filament\Resources\FixedExamPrograms\FixedExamProgramResource;
use App\Http\Controllers\Controller;
use App\Models\FixedExamProgram;
use App\Support\InstitutionSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FixedExamProgramPrintController extends Controller
{
    public function __invoke(FixedExamProgram $fixedExamProgram): Response|StreamedResponse
    {
        Gate::authorize('view', $fixedExamProgram);

        $data = $this->viewData($fixedExamProgram);

        if (request()->boolean('download')) {
            return $this->downloadPdf($fixedExamProgram, $data);
        }

        return response()->view('admin.exam-schedules.print', $data);
    }

    /**
     * @return array<string, mixed>
     */
    protected function viewData(FixedExamProgram $fixedExamProgram): array
    {
        $snapshot = $fixedExamProgram->snapshot_data ?? [];
        $meta = data_get($snapshot, 'meta', []);
        $institution = InstitutionSettings::make();

        return [
            'fixedProgram' => $fixedExamProgram,
            'snapshot' => $snapshot,
            'filters' => [],
            'college' => (object) ['name' => data_get($meta, 'college_name', $fixedExamProgram->college_name)],
            'department' => (object) ['name' => data_get($meta, 'department_name', $fixedExamProgram->department_name)],
            'academicYear' => (object) ['name' => data_get($meta, 'academic_year', $fixedExamProgram->academic_year)],
            'semester' => (object) ['name' => data_get($meta, 'semester', $fixedExamProgram->semester)],
            'levels' => $this->objects(data_get($snapshot, 'levels', [])),
            'rows' => collect(data_get($snapshot, 'rows', [])),
            'offerings' => collect(data_get($snapshot, 'entries', [])),
            'systemSetting' => $institution->reportContext(
                collegeName: data_get($meta, 'college_name', $fixedExamProgram->college_name),
                departmentName: data_get($meta, 'department_name', $fixedExamProgram->department_name),
                academicYear: data_get($meta, 'academic_year', $fixedExamProgram->academic_year),
            ),
            'logoDataUri' => $institution->logoDataUri(),
            'regularFontDataUri' => $this->fontDataUri('NotoSansArabic-Regular.ttf'),
            'boldFontDataUri' => $this->fontDataUri('NotoSansArabic-Bold.ttf'),
            'filterOptions' => [],
            'fixedProgramsUrl' => FixedExamProgramResource::getUrl('index'),
            'printUrl' => route('filament.adminpanel.fixed-exam-programs.print', ['fixedExamProgram' => $fixedExamProgram]),
            'pdfUrl' => route('filament.adminpanel.fixed-exam-programs.print', [
                'fixedExamProgram' => $fixedExamProgram,
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
    protected function downloadPdf(FixedExamProgram $fixedExamProgram, array $data): StreamedResponse
    {
        $pdf = $this->makePdf();
        $pdf->WriteHTML(view('admin.exam-schedules.print', [
            ...$data,
            'isPdfDownload' => true,
        ])->render());

        $filename = Str::slug($fixedExamProgram->title ?: 'fixed-exam-program').'-'.$fixedExamProgram->id.'.pdf';

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
