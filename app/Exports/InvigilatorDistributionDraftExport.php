<?php

namespace App\Exports;

use App\Models\InvigilatorDistributionDraft;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class InvigilatorDistributionDraftExport implements WithMultipleSheets
{
    public function __construct(
        protected InvigilatorDistributionDraft $draft,
    ) {}

    public function sheets(): array
    {
        $this->draft->loadMissing(['college', 'creator', 'approver', 'assignments.invigilator', 'assignments.examHall']);
        $summary = $this->draft->summary_json ?? [];

        return [
            new InvigilatorDistributionArraySheet(
                __('exam.fair_draft.report_title'),
                [
                    __('exam.fields.status'),
                    __('exam.fields.created_at'),
                    __('exam.fields.college'),
                    __('exam.fields.from_date'),
                    __('exam.fields.to_date'),
                    __('exam.fair_draft.fields.total_duties'),
                    __('exam.fair_draft.fields.min_duties'),
                    __('exam.fair_draft.fields.max_duties'),
                    __('exam.fair_draft.fields.average_duties'),
                    __('exam.fair_draft.fields.changed_observers'),
                    __('exam.fair_draft.fields.relaxed_constraints_count'),
                ],
                [[
                    __('exam.fair_draft.statuses.'.$this->draft->status),
                    $this->draft->created_at?->format('Y-m-d H:i'),
                    $this->draft->college?->name,
                    $this->draft->exam_date_from?->format('Y-m-d'),
                    $this->draft->exam_date_to?->format('Y-m-d'),
                    $summary['total_duties'] ?? 0,
                    $summary['min_duties'] ?? 0,
                    $summary['max_duties'] ?? 0,
                    $summary['average_duties'] ?? 0,
                    $summary['changed_observers_count'] ?? 0,
                    $summary['relaxed_constraints_count'] ?? 0,
                ]],
            ),
            new InvigilatorDistributionArraySheet(
                __('exam.fair_draft.assignment_details'),
                [
                    __('exam.fields.invigilator_name'),
                    __('exam.fields.invigilation_role'),
                    __('exam.fields.exam_date'),
                    __('exam.fields.exam_start_time'),
                    __('exam.fields.hall_name'),
                    __('exam.fair_draft.fields.current_duties'),
                    __('exam.fair_draft.fields.proposed_duties'),
                    __('exam.fair_draft.fields.difference'),
                    __('exam.fair_draft.fields.relaxed_constraints'),
                    __('exam.fields.reason'),
                ],
                $this->draft->assignments
                    ->map(fn ($assignment): array => [
                        $assignment->invigilator?->name,
                        $assignment->invigilation_role?->label(),
                        $assignment->exam_date?->format('Y-m-d'),
                        substr((string) $assignment->start_time, 0, 5),
                        $assignment->examHall?->name,
                        $assignment->current_duties_count,
                        $assignment->proposed_duties_count,
                        $assignment->difference,
                        collect($assignment->relaxed_constraints_json ?? [])->implode('، '),
                        $assignment->reason,
                    ])
                    ->values()
                    ->all(),
            ),
        ];
    }
}
