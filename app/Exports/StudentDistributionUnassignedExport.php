<?php

namespace App\Exports;

use App\Models\StudentDistributionRun;
use App\Services\ExamHallDistributionService;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class StudentDistributionUnassignedExport implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    public function __construct(
        protected StudentDistributionRun $run,
    ) {}

    public function headings(): array
    {
        return [
            __('exam.fields.student_number'),
            __('exam.fields.full_name'),
            __('exam.fields.subject'),
            __('exam.fields.exam_date'),
            __('exam.fields.exam_start_time'),
            __('exam.fields.reason'),
        ];
    }

    public function array(): array
    {
        return collect(app(ExamHallDistributionService::class)->unassignedStudentsForRun($this->run))
            ->map(fn (array $student): array => [
                $student['student_number'],
                $student['full_name'],
                $student['subject_name'],
                $student['exam_date'],
                $student['start_time'],
                $student['reason'],
            ])
            ->all();
    }

    public function title(): string
    {
        return __('exam.global_hall_distribution.unassigned_report_title');
    }
}
