<?php

namespace App\Exports;

use App\Models\College;
use App\Services\InvigilatorDistributionService;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class InvigilatorDistributionByInvigilatorExport implements WithMultipleSheets
{
    public function __construct(
        protected College $college,
        protected ?string $examDate = null,
        protected ?string $startTime = null,
        protected ?string $fromDate = null,
        protected ?string $toDate = null,
    ) {}

    public function sheets(): array
    {
        $summary = app(InvigilatorDistributionService::class)->getSummary(
            $this->college,
            $this->examDate,
            $this->startTime,
            $this->fromDate,
            $this->toDate,
        );
        $titles = [];

        $sheets = collect($summary['by_invigilator'] ?? [])
            ->map(function (array $invigilator) use (&$titles): ReportArraySheet {
                $title = $this->uniqueTitle((string) ($invigilator['name'] ?? __('exam.fields.invigilator_name')), $titles);

                return new ReportArraySheet($title, $this->rowsForInvigilator($invigilator));
            })
            ->values()
            ->all();

        if ($sheets !== []) {
            return $sheets;
        }

        return [
            new ReportArraySheet(__('exam.reports.invigilator_distribution_by_invigilator'), [
                [__('exam.reports.invigilator_distribution_by_invigilator')],
                [__('exam.fields.college'), $this->college->name],
                [__('exam.helpers.no_invigilator_assignments')],
            ]),
        ];
    }

    protected function rowsForInvigilator(array $invigilator): array
    {
        $rows = [
            [__('exam.reports.invigilator_distribution_by_invigilator')],
            [__('exam.fields.invigilator_name'), $invigilator['name'] ?? '—'],
            [__('exam.fields.college'), $this->college->name],
            [__('exam.fields.staff_category'), $invigilator['staff_category'] ?? '—'],
            [__('exam.fields.invigilation_role'), $invigilator['invigilation_role'] ?? '—'],
            [],
            [
                __('exam.fields.exam_date'),
                __('exam.fields.exam_start_time'),
                __('exam.fields.hall_name'),
                __('exam.fields.hall_location'),
                __('exam.fields.role_in_hall'),
                __('exam.fields.status'),
                __('exam.fields.notes'),
            ],
        ];

        foreach ($invigilator['assignments'] ?? [] as $assignment) {
            $rows[] = [
                $assignment['exam_date'] ?? '',
                substr((string) ($assignment['start_time'] ?? ''), 0, 5),
                $assignment['hall_name'] ?? '',
                $assignment['hall_location'] ?? '',
                $assignment['role_label'] ?? '',
                $this->assignmentStatusLabel($assignment['assignment_status'] ?? null),
                $assignment['notes'] ?? '',
            ];
        }

        return $rows;
    }

    protected function assignmentStatusLabel(?string $status): string
    {
        if (blank($status)) {
            return '—';
        }

        return __("exam.invigilator_assignment_statuses.{$status}");
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

        return $this->truncateTitle($title !== '' ? $title : __('exam.fields.invigilator_name'), 31);
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
