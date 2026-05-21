<?php

namespace App\Exports;

use App\Models\College;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class InvigilatorsTemplateExport implements FromCollection, ShouldAutoSize, WithColumnFormatting, WithHeadings
{
    public function __construct(
        protected ?College $college = null,
    ) {}

    public function collection(): Collection
    {
        $collegeName = $this->college?->name ?? 'كلية الهندسة';

        return collect([
            [
                'د. أحمد محمد',
                $collegeName,
                '0991000001',
                'دكتور',
                'رئيس قاعة',
                '',
                4,
                1,
                'لا',
                'متوازن',
                0,
                'نعم',
                '-',
            ],
            [
                'أ. خالد محمود',
                $collegeName,
                '0991000002',
                'موظف إداري',
                'مراقب عادي',
                'أمين سر',
                4,
                2,
                'نعم',
                'استخدام الإعداد العام',
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
            'الكلية',
            'رقم الهاتف',
            'نوع الكادر',
            'الدور الأساسي',
            'أدوار إضافية ممكنة',
            'الحد الأقصى للمراقبات',
            'الحد الأقصى في اليوم',
            'السماح بأكثر من مراقبة في اليوم',
            'تفضيل الأيام',
            'نسبة تخفيض المراقبات',
            'فعال',
            'ملاحظات',
        ];
    }

    public function columnFormats(): array
    {
        return [
            'C' => NumberFormat::FORMAT_TEXT,
        ];
    }
}
