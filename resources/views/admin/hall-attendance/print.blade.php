@php
    $studentsPerColumn = 20;
    $studentsPerPage = $studentsPerColumn * 2;
    $printedPages = 0;
@endphp

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $printTitle }}</title>
    <style>
        @if ($regularFontDataUri)
            @font-face {
                font-family: 'HallAttendanceArabic';
                src: url('{{ $regularFontDataUri }}') format('truetype');
                font-weight: 400;
                font-style: normal;
                font-display: swap;
            }
        @endif

        @if ($boldFontDataUri)
            @font-face {
                font-family: 'HallAttendanceArabic';
                src: url('{{ $boldFontDataUri }}') format('truetype');
                font-weight: 700;
                font-style: normal;
                font-display: swap;
            }
        @endif

        @page {
            size: A4 portrait;
            margin: 10mm;
        }

        * {
            box-sizing: border-box;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        html,
        body {
            margin: 0;
            padding: 0;
        }

        body {
            background: #eceff3;
            color: #111111;
            direction: rtl;
            font-family: 'HallAttendanceArabic', 'DejaVu Sans', Tahoma, Arial, sans-serif;
            font-size: 11px;
            line-height: 1.4;
            text-align: right;
        }

        .screen-toolbar {
            align-items: center;
            background: #111827;
            box-shadow: 0 8px 22px rgba(17, 24, 39, 0.2);
            color: #ffffff;
            display: flex;
            gap: 10px;
            justify-content: center;
            padding: 12px;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .toolbar-button {
            background: #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 8px;
            color: #111827;
            cursor: pointer;
            font-family: inherit;
            font-size: 12px;
            font-weight: 700;
            min-width: 160px;
            padding: 8px 14px;
        }

        .toolbar-button.primary {
            background: #0f766e;
            color: #ffffff;
        }

        .sheet {
            background: #ffffff;
            border: 1px solid #d1d5db;
            box-shadow: 0 18px 38px rgba(17, 24, 39, 0.14);
            margin: 14px auto 22px;
            min-height: 297mm;
            padding: 9mm;
            width: 210mm;
        }

        .page-break {
            page-break-before: always;
        }

        .print-header {
            border-bottom: 2px solid #111111;
            padding-bottom: 4mm;
            text-align: center;
        }

        .university-name {
            font-size: 17px;
            font-weight: 700;
            line-height: 1.55;
        }

        .college-name,
        .department-name {
            font-size: 13px;
            font-weight: 700;
            line-height: 1.5;
        }

        .document-title {
            background: #eeeeee;
            border: 1px solid #111111;
            display: inline-block;
            font-size: 15px;
            font-weight: 700;
            margin-top: 3mm;
            min-width: 55%;
            padding: 3px 18px;
        }

        .info-table,
        .supervisors-table,
        .attendance-table {
            border-collapse: collapse;
            width: 100%;
        }

        .info-table {
            border: 1px solid #111111;
            margin-top: 4mm;
            table-layout: fixed;
        }

        .info-table th,
        .info-table td {
            border: 1px solid #222222;
            padding: 4px 6px;
            vertical-align: middle;
        }

        .info-table th {
            background: #e5e5e5;
            font-weight: 700;
            text-align: center;
            width: 16%;
            white-space: nowrap;
        }

        .info-table td {
            font-weight: 700;
            text-align: center;
        }

        .section-title {
            background: #f0f0f0;
            border: 1px solid #111111;
            border-bottom: 0;
            font-size: 12px;
            font-weight: 700;
            margin-top: 4mm;
            padding: 4px 7px;
            text-align: right;
        }

        .supervisors-table th,
        .supervisors-table td,
        .attendance-table th,
        .attendance-table td {
            border: 1px solid #222222;
            padding: 3px 5px;
            text-align: center;
            vertical-align: middle;
        }

        .supervisors-table th,
        .attendance-table th {
            background: #d9d9d9;
            font-weight: 700;
        }

        .supervisors-table td {
            height: 24px;
        }

        .signature-cell {
            width: 22%;
        }

        .student-columns {
            direction: rtl;
            display: grid;
            gap: 5mm;
            grid-template-columns: 1fr 1fr;
            margin-top: 4mm;
        }

        .student-columns.single {
            display: block;
        }

        .attendance-table {
            font-size: 10px;
            table-layout: fixed;
        }

        .attendance-table th,
        .attendance-table td {
            height: 22px;
            padding: 2px 4px;
        }

        .seat-column {
            width: 13%;
        }

        .number-column {
            direction: ltr;
            font-size: 9.5px;
            unicode-bidi: embed;
            width: 25%;
        }

        .name-column {
            text-align: right;
            width: 44%;
        }

        .attendance-column {
            width: 18%;
        }

        .attendance-cell {
            background: #ffffff;
        }

        .page-footer {
            border-top: 1px solid #999999;
            color: #444444;
            font-size: 9px;
            margin-top: 5mm;
            padding-top: 2mm;
            text-align: center;
        }

        .page-counter {
            color: #555555;
            font-size: 9px;
            margin-top: 2mm;
            text-align: left;
        }

        .empty-state {
            border: 1px dashed #999999;
            color: #666666;
            font-weight: 700;
            margin-top: 4mm;
            padding: 12mm;
            text-align: center;
        }

        @media print {
            html,
            body {
                background: #ffffff;
                margin: 0;
                padding: 0;
            }

            body {
                direction: rtl;
                font-size: 10.5px;
            }

            .no-print,
            .screen-toolbar {
                display: none !important;
            }

            .sheet {
                border: 0;
                box-shadow: none;
                margin: 0;
                min-height: auto;
                padding: 0;
                width: auto;
            }

            table {
                border-collapse: collapse;
                width: 100%;
            }

            tr,
            .supervisors-table,
            .attendance-table {
                break-inside: avoid;
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body>
    <div class="screen-toolbar no-print">
        <button class="toolbar-button primary" type="button" onclick="window.print()">طباعة تفقد القاعة</button>
        <button class="toolbar-button" type="button" onclick="window.close()">إغلاق</button>
    </div>

    @forelse ($sheets as $sheet)
        @php
            $students = collect($sheet['students']);
            $studentPages = $students->isEmpty()
                ? collect([collect()])
                : $students->chunk($studentsPerPage);
        @endphp

        @foreach ($studentPages as $pageIndex => $pageStudents)
            @php
                $rightStudents = collect($pageStudents)->take($studentsPerColumn)->values();
                $leftStudents = collect($pageStudents)->slice($studentsPerColumn)->values();
            @endphp

            <main class="sheet {{ $printedPages > 0 ? 'page-break' : '' }}">
                <header class="print-header">
                    <div class="university-name">{{ $sheet['university_name'] }}</div>
                    <div class="college-name">{{ $sheet['college_name'] }}</div>
                    <div class="department-name">{{ $sheet['department_name'] }}</div>
                    <div class="document-title">كشف تفقد القاعة الامتحانية</div>
                </header>

                <table class="info-table">
                    <tr>
                        <th>اليوم والتاريخ</th>
                        <td colspan="3">{{ $sheet['day_date'] }}</td>
                        <th>الفترة</th>
                        <td>{{ $sheet['period'] }}</td>
                    </tr>
                    <tr>
                        <th>المادة</th>
                        <td colspan="5">{{ $sheet['subject_summary'] }}</td>
                    </tr>
                    <tr>
                        <th>القاعة</th>
                        <td colspan="3">{{ $sheet['hall_name'] }}</td>
                        <th>عدد الطلاب</th>
                        <td>{{ $sheet['students_count'] }}</td>
                    </tr>
                </table>

                @if ($pageIndex === 0)
                    <div class="section-title">رئيس القاعة وأمين السر والمراقبون</div>
                    <table class="supervisors-table">
                        <thead>
                            <tr>
                                <th>الاسم</th>
                                <th>المهمة</th>
                                <th>ملاحظات</th>
                                <th class="signature-cell">التوقيع</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($sheet['supervisors'] as $supervisor)
                                <tr>
                                    <td>{{ $supervisor['name'] }}</td>
                                    <td>{{ $supervisor['role'] }}</td>
                                    <td>{{ $supervisor['notes'] }}</td>
                                    <td></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif

                <div class="student-columns {{ $leftStudents->isEmpty() ? 'single' : '' }}">
                    <table class="attendance-table">
                        <thead>
                            <tr>
                                <th class="seat-column">المقعد</th>
                                <th class="number-column">الرقم الجامعي</th>
                                <th class="name-column">اسم الطالب</th>
                                <th class="attendance-column">الحضور</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($rightStudents as $student)
                                <tr>
                                    <td>{{ $student['seat_number'] }}</td>
                                    <td class="number-column">{{ $student['student_number'] }}</td>
                                    <td class="name-column">{{ $student['full_name'] }}</td>
                                    <td class="attendance-cell"></td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4">لا يوجد طلاب موزعون في هذه القاعة.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>

                    @if ($leftStudents->isNotEmpty())
                        <table class="attendance-table">
                            <thead>
                                <tr>
                                    <th class="seat-column">المقعد</th>
                                    <th class="number-column">الرقم الجامعي</th>
                                    <th class="name-column">اسم الطالب</th>
                                    <th class="attendance-column">الحضور</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($leftStudents as $student)
                                    <tr>
                                        <td>{{ $student['seat_number'] }}</td>
                                        <td class="number-column">{{ $student['student_number'] }}</td>
                                        <td class="name-column">{{ $student['full_name'] }}</td>
                                        <td class="attendance-cell"></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>

                <div class="page-footer">
                    {{ $sheet['university_name'] }} - {{ $sheet['college_name'] }} - {{ $sheet['hall_name'] }}
                </div>

                @if ($studentPages->count() > 1)
                    <div class="page-counter">صفحة {{ $pageIndex + 1 }} من {{ $studentPages->count() }}</div>
                @endif
            </main>

            @php
                $printedPages++;
            @endphp
        @endforeach
    @empty
        <main class="sheet">
            <div class="empty-state">لا توجد قاعات موزعة للطباعة.</div>
        </main>
    @endforelse
</body>
</html>
