<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>{{ __('exam.fair_draft.report_title') }}</title>
    <style>
        body { font-family: 'notosansarabic', sans-serif; font-size: 10px; color: #111827; direction: rtl; text-align: right; }
        @include('pdf.partials.report-styles')
        .card { border: 1px solid #dbe3ea; padding: 10px; margin-bottom: 12px; background: #ffffff; }
        .title { font-size: 14px; font-weight: bold; margin-bottom: 8px; }
        .status { display: inline-block; padding: 6px 12px; border: 1px solid #0f172a; font-size: 18px; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #dbe3ea; padding: 6px 7px; vertical-align: top; }
        th { background: #eef2f7; font-weight: bold; color: #0f172a; }
    </style>
</head>
<body>
    @include('pdf.partials.report-header', [
        'universityName' => $systemSetting->university_name,
        'universityLogo' => $logoDataUri,
        'facultyName' => $draft->college?->name,
        'reportTitle' => __('exam.fair_draft.report_title'),
        'reportSubtitle' => __('exam.fair_draft.statuses.'.$draft->status),
        'dateRange' => __('exam.fields.period').': '.($draft->exam_date_from?->format('Y-m-d') ?? '—').' - '.($draft->exam_date_to?->format('Y-m-d') ?? '—'),
    ])

    @php($summary = $draft->summary_json ?? [])

    <div class="card">
        <div class="status">{{ __('exam.fair_draft.statuses.'.$draft->status) }}</div>
        <table style="margin-top: 10px;">
            <tbody>
                <tr>
                    <th>{{ __('exam.fair_draft.fields.draft_number') }}</th>
                    <td>{{ $draft->getKey() }}</td>
                    <th>{{ __('exam.fields.created_at') }}</th>
                    <td>{{ $draft->created_at?->format('Y-m-d H:i') }}</td>
                </tr>
                <tr>
                    <th>{{ __('exam.fields.executed_by') }}</th>
                    <td>{{ $draft->creator?->name ?? '—' }}</td>
                    <th>{{ __('exam.fair_draft.fields.approved_by') }}</th>
                    <td>{{ $draft->approver?->name ?? '—' }}</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="card">
        <div class="title">{{ __('exam.fair_draft.summary') }}</div>
        <table>
            <tbody>
                <tr>
                    <th>{{ __('exam.fair_draft.fields.total_observers') }}</th>
                    <td>{{ $summary['total_observers'] ?? 0 }}</td>
                    <th>{{ __('exam.fair_draft.fields.total_duties') }}</th>
                    <td>{{ $summary['total_duties'] ?? 0 }}</td>
                </tr>
                <tr>
                    <th>{{ __('exam.fair_draft.fields.min_duties') }}</th>
                    <td>{{ $summary['min_duties'] ?? 0 }}</td>
                    <th>{{ __('exam.fair_draft.fields.max_duties') }}</th>
                    <td>{{ $summary['max_duties'] ?? 0 }}</td>
                </tr>
                <tr>
                    <th>{{ __('exam.fair_draft.fields.average_duties') }}</th>
                    <td>{{ $summary['average_duties'] ?? 0 }}</td>
                    <th>{{ __('exam.fair_draft.fields.changed_observers') }}</th>
                    <td>{{ $summary['changed_observers_count'] ?? 0 }}</td>
                </tr>
                <tr>
                    <th>{{ __('exam.fair_draft.fields.increased_observers') }}</th>
                    <td>{{ $summary['increased_observers_count'] ?? 0 }}</td>
                    <th>{{ __('exam.fair_draft.fields.decreased_observers') }}</th>
                    <td>{{ $summary['decreased_observers_count'] ?? 0 }}</td>
                </tr>
                <tr>
                    <th>{{ __('exam.fair_draft.fields.relaxed_constraints_count') }}</th>
                    <td>{{ $summary['relaxed_constraints_count'] ?? 0 }}</td>
                    <th>{{ __('exam.fields.missing_assignments_count') }}</th>
                    <td>{{ $summary['uncovered_duties'] ?? 0 }}</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="card">
        <div class="title">{{ __('exam.fair_draft.assignment_details') }}</div>
        <table>
            <thead>
                <tr>
                    <th>{{ __('exam.fields.invigilator_name') }}</th>
                    <th>{{ __('exam.fields.invigilation_role') }}</th>
                    <th>{{ __('exam.fields.exam_date') }}</th>
                    <th>{{ __('exam.fields.exam_start_time') }}</th>
                    <th>{{ __('exam.fields.hall_name') }}</th>
                    <th>{{ __('exam.fair_draft.fields.current_duties') }}</th>
                    <th>{{ __('exam.fair_draft.fields.proposed_duties') }}</th>
                    <th>{{ __('exam.fair_draft.fields.difference') }}</th>
                    <th>{{ __('exam.fair_draft.fields.relaxed_constraints') }}</th>
                    <th>{{ __('exam.fields.reason') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($draft->assignments as $assignment)
                    <tr>
                        <td>{{ $assignment->invigilator?->name }}</td>
                        <td>{{ $assignment->invigilation_role?->label() }}</td>
                        <td>{{ $assignment->exam_date?->format('Y-m-d') }}</td>
                        <td>{{ substr((string) $assignment->start_time, 0, 5) }}</td>
                        <td>{{ $assignment->examHall?->name }}</td>
                        <td>{{ $assignment->current_duties_count }}</td>
                        <td>{{ $assignment->proposed_duties_count }}</td>
                        <td>{{ $assignment->difference }}</td>
                        <td>{{ collect($assignment->relaxed_constraints_json ?? [])->implode('، ') ?: '—' }}</td>
                        <td>{{ $assignment->reason }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</body>
</html>
