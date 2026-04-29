<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class SubjectExamRosterStudentsTemplateExport implements FromCollection, ShouldAutoSize, WithHeadings
{
    public function __construct(protected ?string $studentType = null) {}

    public function collection(): Collection
    {
        if ($this->studentType) {
            return collect([
                ['S-001', 'اسم الطالب', null],
            ]);
        }

        return collect([
            ['S-001', 'اسم الطالب', 'مستجد', 'نعم', null],
        ]);
    }

    public function headings(): array
    {
        if ($this->studentType) {
            return [
                'الرقم الامتحاني',
                'اسم الطالب',
                'ملاحظات',
            ];
        }

        return [
            'الرقم الامتحاني',
            'اسم الطالب',
            'نوع الطالب',
            'نشط',
            'ملاحظات',
        ];
    }
}
