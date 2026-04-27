<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>كشف الطلاب غير الموزعين</title>
    <style>
        body {
            font-family: 'notosansarabic', sans-serif;
            font-size: 11px;
            color: #111827;
            direction: rtl;
            text-align: right;
            unicode-bidi: embed;
        }
        @include('pdf.partials.report-styles')
        .card {
            border: 1px solid #d1d5db;
            border-radius: 10px;
            padding: 12px;
            margin-bottom: 14px;
            background: #ffffff;
        }
        .students-table,
        .subjects-table {
            width: 100%;
            border-collapse: collapse;
        }
        .title {
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 4px;
        }
        .subtitle,
        .muted {
            color: #4b5563;
        }
        .stats {
            width: 100%;
            margin-bottom: 14px;
        }
        .stats td {
            width: 33.33%;
            padding: 0 0 8px 8px;
            vertical-align: top;
        }
        .stat-box {
            border: 1px solid #fecaca;
            border-radius: 10px;
            padding: 10px;
            background: #fef2f2;
        }
        .stat-label {
            color: #991b1b;
            font-size: 10px;
            margin-bottom: 4px;
        }
        .stat-value {
            color: #991b1b;
            font-size: 18px;
            font-weight: bold;
        }
        .section-title {
            font-size: 15px;
            font-weight: bold;
            margin: 0 0 10px;
        }
        .students-table th,
        .students-table td,
        .subjects-table th,
        .subjects-table td {
            border: 1px solid #d1d5db;
            padding: 7px 8px;
            text-align: right;
            vertical-align: top;
        }
        .students-table th,
        .subjects-table th {
            background: #f3f4f6;
            font-weight: bold;
        }
        .ltr {
            direction: ltr;
            text-align: left;
            unicode-bidi: embed;
            font-family: sans-serif;
        }
        .student-name {
            line-height: 1.7;
        }
    </style>
</head>
<body>
    @php
        $unassignedStudents = $summary['unassigned_students'] ?? [];
        $unassignedBySubject = $summary['unassigned_summary_by_subject'] ?? [];
        $examDateTime = __('exam.fields.exam_date').': '.$summary['exam_date'].' | '.__('exam.fields.exam_start_time').': '.substr((string) $summary['exam_start_time'], 0, 5);
    @endphp

    @include('pdf.partials.report-header', [
        'universityName' => $systemSetting->university_name,
        'universityLogo' => $logoDataUri,
        'facultyName' => $summary['context']['college_name'] ?? '—',
        'reportTitle' => 'تقرير الطلاب غير الموزعين',
        'reportSubtitle' => 'قائمة الحالات التي تحتاج إلى معالجة قبل اعتماد التوزيع',
        'dateRange' => $examDateTime,
    ])

    <table class="stats">
        <tr>
            <td>
                <div class="stat-box">
                    <div class="stat-label">{{ __('exam.fields.unassigned_students') }}</div>
                    <div class="stat-value">{{ count($unassignedStudents) }}</div>
                </div>
            </td>
            <td>
                <div class="stat-box">
                    <div class="stat-label">{{ __('exam.fields.total_students') }}</div>
                    <div class="stat-value">{{ $summary['total_students_count'] }}</div>
                </div>
            </td>
            <td>
                <div class="stat-box">
                    <div class="stat-label">العجز في المقاعد</div>
                    <div class="stat-value">{{ $summary['capacity_shortage'] }}</div>
                </div>
            </td>
        </tr>
    </table>

    @if (! empty($unassignedBySubject))
        <div class="card">
            <div class="section-title">المواد المتأثرة</div>
            <table class="subjects-table">
                <thead>
                    <tr>
                        <th>{{ __('exam.fields.subject') }}</th>
                        <th>{{ __('exam.fields.unassigned_students') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($unassignedBySubject as $subject)
                        <tr>
                            <td>{{ $subject['subject_name'] }}</td>
                            <td>{{ $subject['students_count'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="card">
        <div class="section-title">قائمة الطلاب غير الموزعين</div>
        <table class="students-table">
            <thead>
                <tr>
                    <th>{{ __('exam.fields.student_number') }}</th>
                    <th>{{ __('exam.fields.full_name') }}</th>
                    <th>{{ __('exam.fields.subject') }}</th>
                    <th>{{ __('exam.fields.exam_date') }}</th>
                    <th>{{ __('exam.fields.exam_start_time') }}</th>
                    <th>نوع الطالب</th>
                    <th>سبب عدم التوزيع</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($unassignedStudents as $student)
                    <tr>
                        <td class="ltr">{{ $student['student_number'] }}</td>
                        <td class="student-name">{{ $student['full_name'] }}</td>
                        <td>{{ $student['subject_name'] }}</td>
                        <td class="ltr">{{ $summary['exam_date'] }}</td>
                        <td class="ltr">{{ substr((string) $summary['exam_start_time'], 0, 5) }}</td>
                        <td>{{ $student['student_type_label'] }}</td>
                        <td>{{ $student['reason'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</body>
</html>
