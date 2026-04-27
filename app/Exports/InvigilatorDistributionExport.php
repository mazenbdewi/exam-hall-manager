<?php

namespace App\Exports;

use App\Models\College;
use App\Services\InvigilatorDistributionService;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class InvigilatorDistributionExport implements WithMultipleSheets
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

        return [
            new InvigilatorDistributionArraySheet(
                __('exam.reports.hall_report'),
                [
                    __('exam.fields.exam_date'),
                    __('exam.fields.exam_start_time'),
                    __('exam.fields.hall_name'),
                    __('exam.fields.hall_location'),
                    __('exam.invigilation_roles.hall_head'),
                    __('exam.invigilation_roles.secretary'),
                    __('exam.invigilation_roles.regular'),
                    __('exam.invigilation_roles.reserve'),
                    __('exam.fields.phone_numbers'),
                ],
                $this->hallRows($summary),
            ),
            new InvigilatorDistributionArraySheet(
                __('exam.reports.personal_schedule'),
                [
                    __('exam.fields.invigilator_name'),
                    __('exam.fields.staff_category'),
                    __('exam.fields.invigilation_role'),
                    __('exam.fields.workload_reduction_percentage'),
                    __('exam.fields.exam_date'),
                    __('exam.fields.exam_start_time'),
                    __('exam.fields.hall_name'),
                    __('exam.fields.hall_location'),
                ],
                $this->personalRows($summary),
            ),
            new InvigilatorDistributionArraySheet(
                __('exam.reports.shortage_report'),
                [
                    __('exam.fields.exam_date'),
                    __('exam.fields.exam_start_time'),
                    __('exam.fields.hall_name'),
                    __('exam.fields.invigilation_role'),
                    __('exam.fields.required_count'),
                    __('exam.fields.assigned_count'),
                    __('exam.fields.shortage_count'),
                    __('exam.fields.reason'),
                ],
                $this->shortageRows($summary),
            ),
        ];
    }

    protected function hallRows(array $summary): array
    {
        return collect($summary['slots'])
            ->flatMap(fn (array $slot): array => collect($slot['halls'])->map(function (array $hall) use ($slot): array {
                $names = fn (string $role): string => collect($hall['assignments_by_role'][$role] ?? [])
                    ->map(fn (array $assignment): string => trim(($assignment['name'] ?? '').(filled($assignment['notes'] ?? null) ? ' - '.$assignment['notes'] : '')))
                    ->filter()
                    ->implode('، ');
                $roleCell = function (string $role) use ($hall, $names): string {
                    $roleNames = $names($role);
                    $required = (int) ($hall['required_roles'][$role] ?? 0);
                    $assigned = count($hall['assignments_by_role'][$role] ?? []);

                    if ($required > $assigned) {
                        $shortage = $hall['shortages_by_role'][$role] ?? [];
                        $shortageCount = (int) ($shortage['shortage_count'] ?? max(0, $required - $assigned));
                        $reason = $shortage['reason'] ?? __('exam.reports.required_role_shortage_reason');
                        $shortageText = __('exam.reports.has_shortage').': '.$shortageCount.' - '.__('exam.fields.reason').': '.$reason;

                        return filled($roleNames) ? $roleNames.' | '.$shortageText : '— '.$shortageText;
                    }

                    return $roleNames ?: '—';
                };
                $phones = collect($hall['assignments_by_role'])->flatten(1)->pluck('phone')->filter()->implode('، ');

                return [
                    $slot['exam_date'],
                    substr((string) $slot['start_time'], 0, 5),
                    $hall['name'],
                    $hall['location'],
                    $roleCell('hall_head'),
                    $roleCell('secretary'),
                    $roleCell('regular'),
                    $roleCell('reserve'),
                    $phones ?: '—',
                ];
            })->all())
            ->values()
            ->all();
    }

    protected function personalRows(array $summary): array
    {
        return collect($summary['slots'])
            ->flatMap(fn (array $slot): array => collect($slot['halls'])->flatMap(function (array $hall) use ($slot): array {
                return collect($hall['assignments_by_role'])->flatMap(function (array $assignments, string $role) use ($slot, $hall): array {
                    return collect($assignments)->map(fn (array $assignment): array => [
                        $assignment['name'],
                        $assignment['staff_category'],
                        __("exam.invigilation_roles.{$role}"),
                        ($assignment['workload_reduction_percentage'] ?? 0).'%',
                        $slot['exam_date'],
                        substr((string) $slot['start_time'], 0, 5),
                        $hall['name'],
                        $hall['location'],
                    ])->all();
                })->all();
            })->all())
            ->sortBy([0, 3, 4])
            ->values()
            ->all();
    }

    protected function shortageRows(array $summary): array
    {
        return collect($summary['shortages'])
            ->map(fn (array $shortage): array => [
                $shortage['exam_date'],
                $shortage['start_time'],
                $shortage['hall_name'],
                $shortage['invigilation_role'],
                $shortage['required_count'],
                $shortage['assigned_count'],
                $shortage['shortage_count'],
                $shortage['reason'],
            ])
            ->values()
            ->all();
    }
}
