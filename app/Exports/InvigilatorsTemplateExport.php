<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class InvigilatorsTemplateExport implements FromCollection, ShouldAutoSize, WithHeadings
{
    public function collection(): Collection
    {
        return collect([
            [
                'د. أحمد محمد',
                'دكتور',
                '0991000001',
                'رئيس قاعة',
                3,
                1,
                0,
                'نعم',
                '',
            ],
            [
                'أ. خالد محمود',
                'موظف إداري',
                '0991000002',
                'مراقب عادي',
                4,
                1,
                25,
                'نعم',
                'رقم الهاتف مطلوب',
            ],
        ]);
    }

    public function headings(): array
    {
        return [
            'اسم المراقب',
            'نوع الكادر',
            'رقم الهاتف',
            'نوع المراقبة',
            'الحد الأقصى للمراقبات',
            'الحد الأقصى في اليوم',
            'نسبة تخفيض المراقبات',
            'فعال',
            'ملاحظات',
        ];
    }
}
