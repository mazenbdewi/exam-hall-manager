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
                4,
                0,
                'لا',
                1,
                'متوازن',
                'نعم',
                '-',
            ],
            [
                'أ. خالد محمود',
                'موظف إداري',
                '0991000002',
                'مراقب عادي',
                4,
                25,
                'نعم',
                2,
                'استخدام الإعداد العام',
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
            'نسبة تخفيض المراقبات',
            'السماح بأكثر من مراقبة في اليوم',
            'الحد الأقصى في اليوم',
            'تفضيل الأيام',
            'فعال',
            'ملاحظات',
        ];
    }
}
