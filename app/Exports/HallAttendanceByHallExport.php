<?php

namespace App\Exports;

use App\Services\HallAttendanceSheetService;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class HallAttendanceByHallExport implements WithMultipleSheets
{
    public function __construct(
        protected int $collegeId,
        protected string $examDate,
        protected string $examStartTime,
    ) {}

    public function sheets(): array
    {
        $service = app(HallAttendanceSheetService::class);
        $hallAssignments = $service->hallAssignmentsForSlot($this->collegeId, $this->examDate, $this->examStartTime);
        $viewData = $service->viewData($hallAssignments);
        $titles = [];

        $sheets = collect($viewData['sheets'] ?? [])
            ->map(function (array $sheet) use (&$titles): ReportArraySheet {
                $title = $this->uniqueTitle((string) ($sheet['hall_name'] ?? __('exam.fields.hall_name')), $titles);

                return new ReportArraySheet($title, $this->rowsForSheet($sheet));
            })
            ->values()
            ->all();

        if ($sheets !== []) {
            return $sheets;
        }

        return [
            new ReportArraySheet(__('exam.reports.hall_inspection_by_hall'), [
                [__('exam.helpers.no_distribution_empty_title')],
            ]),
        ];
    }

    protected function rowsForSheet(array $sheet): array
    {
        $rows = [
            [__('exam.reports.hall_inspection_by_hall')],
            [__('exam.fields.college'), $sheet['college_name'] ?? '—'],
            [__('exam.fields.exam_date'), $sheet['day_date'] ?? ($sheet['exam_date'] ?? '—')],
            [__('exam.fields.period'), $sheet['period'] ?? '—'],
            [__('exam.fields.hall_name'), $sheet['hall_name'] ?? '—'],
            [__('exam.fields.subjects'), $sheet['subject_summary'] ?? '—'],
            [__('exam.fields.students_count'), $sheet['students_count'] ?? 0],
            [],
        ];

        $supervisors = collect($sheet['supervisors'] ?? [])
            ->filter(fn (array $supervisor): bool => filled($supervisor['name'] ?? null) || filled($supervisor['role'] ?? null) || filled($supervisor['notes'] ?? null))
            ->values();

        if ($supervisors->isNotEmpty()) {
            $rows[] = [__('exam.reports.supervisors')];
            $rows[] = [__('exam.fields.name'), __('exam.fields.role_in_hall'), __('exam.fields.notes'), __('exam.fields.signature')];

            foreach ($supervisors as $supervisor) {
                $rows[] = [
                    $supervisor['name'] ?? '',
                    $supervisor['role'] ?? '',
                    $supervisor['notes'] ?? '',
                    '',
                ];
            }

            $rows[] = [];
        }

        $rows[] = [
            __('exam.fields.seat_number'),
            __('exam.fields.student_number'),
            __('exam.fields.full_name'),
            __('exam.fields.subject'),
            __('exam.fields.attendance'),
            __('exam.fields.signature'),
            __('exam.fields.notes'),
        ];

        foreach ($sheet['students'] ?? [] as $student) {
            $rows[] = [
                $student['seat_number'] ?? '',
                $student['student_number'] ?? '',
                $student['full_name'] ?? '',
                $student['subject_name'] ?? '',
                '',
                '',
                '',
            ];
        }

        return $rows;
    }

    protected function uniqueTitle(string $title, array &$titles): string
    {
        $base = $this->sanitizeTitle($title);
        $candidate = $base;
        $index = 2;

        while (in_array($candidate, $titles, true)) {
            $suffix = ' '.$index;
            $limit = 31 - $this->stringLength($suffix);
            $candidate = $this->truncateTitle($base, max(1, $limit)).$suffix;
            $index++;
        }

        $titles[] = $candidate;

        return $candidate;
    }

    protected function sanitizeTitle(string $title): string
    {
        $title = preg_replace('/[\\\\\\/\\?\\*\\[\\]:]/u', ' ', $title) ?: $title;
        $title = trim((string) preg_replace('/\s+/u', ' ', $title));

        return $this->truncateTitle($title !== '' ? $title : __('exam.fields.hall_name'), 31);
    }

    protected function truncateTitle(string $title, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($title, 0, $length) : substr($title, 0, $length);
    }

    protected function stringLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }
}
