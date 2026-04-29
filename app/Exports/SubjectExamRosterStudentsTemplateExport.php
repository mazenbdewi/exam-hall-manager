<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class SubjectExamRosterStudentsTemplateExport implements FromCollection, ShouldAutoSize, WithHeadings
{
    public function collection(): Collection
    {
        return collect([
            ['S-001', 'اسم الطالب', 'مستجد', 'نعم', null],
            ['S-002', 'اسم طالب حملة', 'حملة', 'نعم', null],
        ]);
    }

    public function headings(): array
    {
        return [
            'الرقم الامتحاني',
            'اسم الطالب',
            'نوع الطالب',
            'نشط',
            'ملاحظات',
        ];
    }
}
