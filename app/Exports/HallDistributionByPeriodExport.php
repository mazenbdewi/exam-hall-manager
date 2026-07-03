<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class HallDistributionByPeriodExport implements FromArray, WithEvents, WithTitle
{
    protected array $rows = [];

    protected array $periodRows = [];

    protected array $tableHeaderRows = [];

    protected array $dataRows = [];

    protected int $maxColumns = 8;

    public function __construct(
        protected array $report,
    ) {
        $this->buildRows();
    }

    public function array(): array
    {
        return $this->rows;
    }

    public function title(): string
    {
        return 'توزيع القاعات';
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $this->styleSheet($event->sheet->getDelegate());
            },
        ];
    }

    protected function buildRows(): void
    {
        $meta = $this->report['meta'] ?? [];
        $periods = $this->report['periods'] ?? [];
        $hasSavedDistribution = (bool) ($this->report['has_saved_distribution'] ?? false);

        foreach ($periods as $period) {
            $this->maxColumns = max($this->maxColumns, 4 + count($period['halls'] ?? []));
        }

        $this->rows[] = ['تقرير توزيع أعداد الطلاب على القاعات حسب الفترة الامتحانية'];
        $this->rows[] = [
            'الجامعة',
            $meta['university_name'] ?? '',
            'الكلية',
            $meta['college_name'] ?? '—',
            'القسم',
            $meta['department_name'] ?? '—',
        ];
        $this->rows[] = [
            'العام الدراسي',
            $meta['academic_year'] ?? '—',
            'الفصل الدراسي',
            $meta['semester'] ?? '—',
            'من تاريخ',
            $meta['date_from'] ?? '—',
            'إلى تاريخ',
            $meta['date_to'] ?? '—',
        ];
        $this->rows[] = [
            'الفترة',
            $meta['exam_time_slot'] ?? 'كل الفترات',
        ];
        $this->rows[] = [];

        if (! $hasSavedDistribution) {
            $this->rows[] = ['لا توجد عملية توزيع محفوظة ضمن الفلاتر المحددة.'];

            return;
        }

        if ($periods === []) {
            $this->rows[] = ['لا توجد بيانات توزيع طلاب لهذه الفترة.'];

            return;
        }

        foreach ($periods as $period) {
            $periodRow = count($this->rows) + 1;
            $this->periodRows[] = $periodRow;
            $this->rows[] = [
                'التاريخ: '.($period['exam_date'] ?? '—').' - الفترة: '.$this->periodTimeLabel($period),
            ];

            $headerRow = count($this->rows) + 1;
            $this->tableHeaderRows[] = $headerRow;
            $this->rows[] = [
                'اسم المادة',
                'القسم',
                'السنة',
                'العدد الكلي',
                ...collect($period['halls'] ?? [])->pluck('name')->all(),
            ];

            foreach ($period['subjects'] ?? [] as $subject) {
                $rowNumber = count($this->rows) + 1;
                $this->dataRows[] = $rowNumber;
                $row = [
                    $subject['name'] ?? '',
                    $subject['department_name'] ?? '',
                    $subject['study_level_name'] ?? '',
                    (int) ($subject['total'] ?? 0),
                ];

                foreach ($period['halls'] ?? [] as $hall) {
                    $count = (int) (($subject['hall_counts'] ?? [])[$hall['id']] ?? 0);
                    $row[] = $count > 0 ? $count : '-';
                }

                $this->rows[] = $row;
            }

            $this->rows[] = [];
        }
    }

    protected function styleSheet(Worksheet $sheet): void
    {
        $highestRow = max(1, count($this->rows));
        $highestColumn = Coordinate::stringFromColumnIndex($this->maxColumns);
        $usedRange = "A1:{$highestColumn}{$highestRow}";

        $sheet->setRightToLeft(true);
        $sheet->getParent()?->getDefaultStyle()->getFont()->setName('Arial')->setSize(11);

        $sheet->mergeCells("A1:{$highestColumn}1");
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 16],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(28);

        $sheet->getStyle($usedRange)->applyFromArray([
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['argb' => 'FF666666'],
                ],
            ],
        ]);

        $sheet->getStyle("A2:{$highestColumn}4")->applyFromArray([
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FFF7F7F7'],
            ],
        ]);

        foreach ($this->periodRows as $row) {
            $sheet->mergeCells("A{$row}:{$highestColumn}{$row}");
            $sheet->getStyle("A{$row}:{$highestColumn}{$row}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 13],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FFE8EEF8'],
                ],
            ]);
            $sheet->getRowDimension($row)->setRowHeight(24);
        }

        foreach ($this->tableHeaderRows as $row) {
            $sheet->getStyle("A{$row}:{$highestColumn}{$row}")->applyFromArray([
                'font' => ['bold' => true],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FFD9EAD3'],
                ],
            ]);
            $sheet->getRowDimension($row)->setRowHeight(22);
        }

        foreach ($this->dataRows as $row) {
            $sheet->getRowDimension($row)->setRowHeight(21);
        }

        $sheet->getColumnDimension('A')->setWidth(32);
        $sheet->getColumnDimension('B')->setWidth(24);
        $sheet->getColumnDimension('C')->setWidth(18);
        $sheet->getColumnDimension('D')->setWidth(13);

        for ($column = 5; $column <= $this->maxColumns; $column++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setWidth(13);
        }

        if ($this->tableHeaderRows !== []) {
            $sheet->freezePane('A'.($this->tableHeaderRows[0] + 1));
        }

        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)
            ->setFitToHeight(0);
        $sheet->getPageMargins()
            ->setTop(0.35)
            ->setRight(0.25)
            ->setLeft(0.25)
            ->setBottom(0.35);
    }

    protected function periodTimeLabel(array $period): string
    {
        $start = $this->displayTime($period['exam_start_time'] ?? null);
        $end = $this->displayTime($period['exam_end_time'] ?? null);

        return "{$start} - {$end}";
    }

    protected function displayTime(mixed $time): string
    {
        $time = trim((string) $time);

        return $time !== '' ? substr($time, 0, 5) : '—';
    }
}
