<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>تقرير الموظفين</title>
    <style>
        body { font-family: 'notosansarabic', sans-serif; font-size: 10px; color: #111827; direction: rtl; text-align: right; }
        @include('pdf.partials.report-styles')
        .card { border: 1px solid #dbe3ea; padding: 10px; margin-bottom: 12px; background: #ffffff; }
        .grid { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        .grid td { border: 1px solid #dbe3ea; padding: 9px; width: 20%; vertical-align: top; }
        .metric-label { color: #475569; font-size: 9px; }
        .metric-value { font-size: 17px; font-weight: bold; color: #0f172a; margin-top: 4px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #dbe3ea; padding: 6px 7px; vertical-align: top; }
        th { background: #eef2f7; font-weight: bold; color: #0f172a; }
        .section-title { font-size: 14px; font-weight: bold; margin: 0 0 8px; }
    </style>
</head>
<body>
    @include('pdf.partials.report-header', [
        'universityName' => $systemSetting->university_name,
        'universityLogo' => $logoDataUri,
        'facultyName' => $report['college']->name,
        'reportTitle' => 'تقرير الموظفين',
        'reportSubtitle' => 'ترتيب الموظفين ونسبة إنجاز عمل الكلية',
        'dateRange' => $reportDateRange ?? __('exam.fields.period').': —',
    ])

    <table class="grid">
        <tr>
            <td>
                <div class="metric-label">نسبة إنجاز الكلية</div>
                <div class="metric-value">{{ number_format($report['completion_percentage'] ?? 0, 1) }}%</div>
            </td>
            <td>
                <div class="metric-label">المهام المنجزة</div>
                <div class="metric-value">{{ number_format($report['assigned_tasks_count'] ?? 0) }}</div>
            </td>
            <td>
                <div class="metric-label">المهام المطلوبة</div>
                <div class="metric-value">{{ number_format($report['required_tasks_count'] ?? 0) }}</div>
            </td>
            <td>
                <div class="metric-label">النقص</div>
                <div class="metric-value">{{ number_format($report['shortage_count'] ?? 0) }}</div>
            </td>
            <td>
                <div class="metric-label">الموظفون الفعالون</div>
                <div class="metric-value">{{ number_format($report['active_employees'] ?? 0) }}</div>
            </td>
        </tr>
    </table>

    <div class="card">
        <div class="section-title">ترتيب الموظفين حسب إنجاز العمل</div>
        <table>
            <thead>
                <tr>
                    <th>الترتيب</th>
                    <th>الموظف</th>
                    <th>نوع الكادر</th>
                    <th>نوع المراقبة</th>
                    <th>المهام</th>
                    <th>آلي</th>
                    <th>يدوي</th>
                    <th>نسبة من عمل الكلية</th>
                    <th>استخدام الحد الشخصي</th>
                    <th>الأيام</th>
                    <th>الحالة</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($report['employees'] as $employee)
                    <tr>
                        <td>#{{ $employee['rank'] }}</td>
                        <td>{{ $employee['name'] }}</td>
                        <td>{{ $employee['staff_category'] ?: '—' }}</td>
                        <td>{{ $employee['invigilation_role'] ?: '—' }}</td>
                        <td>{{ $employee['tasks_count'] }}</td>
                        <td>{{ $employee['assigned_count'] }}</td>
                        <td>{{ $employee['manual_count'] }}</td>
                        <td>{{ number_format($employee['contribution_percentage'], 1) }}%</td>
                        <td>{{ number_format($employee['capacity_usage_percentage'], 1) }}%</td>
                        <td>{{ $employee['days_count'] }}</td>
                        <td>{{ $employee['is_active'] ? 'فعال' : 'غير فعال' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="11" class="muted">لا توجد بيانات موظفين ضمن الكلية والفترة المحددة.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="card">
        <div class="section-title">ملخص حالات المهام</div>
        <table>
            <thead>
                <tr>
                    <th>الحالة</th>
                    <th>العدد</th>
                </tr>
            </thead>
            <tbody>
                @foreach (($report['status_counts'] ?? []) as $status)
                    <tr>
                        <td>{{ $status['label'] }}</td>
                        <td>{{ number_format($status['count']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</body>
</html>
