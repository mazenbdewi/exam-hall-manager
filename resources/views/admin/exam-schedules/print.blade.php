@php
    $isPdfDownload = (bool) ($isPdfDownload ?? false);
    $fixedProgram = $fixedProgram ?? null;
    $printMode = $printMode ?? 'fixed';
    $snapshot = $snapshot ?? [];
    $isDraft = $printMode === 'draft' || data_get($snapshot, 'meta.document_status') === 'draft';
    $universityName = filled($systemSetting?->university_name ?? null) ? $systemSetting->university_name : \App\Support\InstitutionSettings::make()->universityName();
    $universityLogo = $logoDataUri ?? $systemSetting?->logo_data_uri ?? null;
    $collegeName = $college?->name ?? '—';
    $departmentName = $department?->name ?? 'كل الأقسام';
    $semesterName = $semester?->name ?? '—';
    $academicYearName = $academicYear?->name ?? '—';
    $reportTitle = data_get($snapshot, 'meta.title') ?: ($fixedProgram?->title ?? 'برنامج امتحان '.$semesterName.' للعام الدراسي '.$academicYearName);
    $fixedAtLabel = filled(data_get($snapshot, 'meta.fixed_at')) ? substr((string) data_get($snapshot, 'meta.fixed_at'), 0, 10) : now(config('app.timezone'))->format('Y-m-d');
    $dateLabel = $fixedProgram ? 'تاريخ التثبيت' : 'تاريخ الطباعة';
    $levelLabel = function (?string $name): string {
        $name = trim((string) $name);

        if ($name === '') {
            return 'السنة';
        }

        return str_starts_with($name, 'السنة') ? $name : 'السنة '.$name;
    };
    $levelsCount = $levels->count();
    $levelWidth = max(13, (int) floor(83 / max(1, $levelsCount)));
@endphp

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $reportTitle }}</title>
    <style>
        @if (! $isPdfDownload && $regularFontDataUri)
            @font-face {
                font-family: 'OfficialExamArabic';
                src: url('{{ $regularFontDataUri }}') format('truetype');
                font-weight: 400;
                font-style: normal;
                font-display: swap;
            }
        @endif

        @if (! $isPdfDownload && $boldFontDataUri)
            @font-face {
                font-family: 'OfficialExamArabic';
                src: url('{{ $boldFontDataUri }}') format('truetype');
                font-weight: 700;
                font-style: normal;
                font-display: swap;
            }
        @endif

        @page {
            size: A4 landscape;
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
            direction: rtl;
            background: #eef0f3;
            color: #111111;
            font-family: {{ $isPdfDownload ? "'notosansarabic'" : "'OfficialExamArabic'" }}, 'DejaVu Sans', Tahoma, Arial, sans-serif;
            font-size: 11px;
            line-height: 1.45;
            text-align: right;
        }

        .screen-toolbar {
            background: #0f172a;
            color: #ffffff;
            padding: 14px 18px;
            position: sticky;
            top: 0;
            z-index: 10;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.22);
        }

        .toolbar-inner {
            align-items: end;
            display: grid;
            gap: 10px;
            grid-template-columns: repeat(6, minmax(130px, 1fr));
            margin: 0 auto;
            max-width: 1260px;
        }

        .toolbar-field label {
            color: #cbd5e1;
            display: block;
            font-size: 11px;
            margin-bottom: 4px;
        }

        .toolbar-field select,
        .toolbar-button,
        .toolbar-link {
            border: 1px solid rgba(255, 255, 255, 0.18);
            border-radius: 9px;
            font-family: inherit;
            font-size: 12px;
            min-height: 39px;
            padding: 7px 10px;
            width: 100%;
        }

        .toolbar-field select {
            background: #ffffff;
            color: #0f172a;
        }

        .toolbar-button,
        .toolbar-link {
            align-items: center;
            background: #ffffff;
            color: #0f172a;
            cursor: pointer;
            display: inline-flex;
            font-weight: 700;
            justify-content: center;
            text-decoration: none;
        }

        .toolbar-button.primary,
        .toolbar-link.primary {
            background: #0f766e;
            color: #ffffff;
        }

        .sheet {
            background: #ffffff;
            border: 1px solid #d1d5db;
            box-shadow: 0 18px 45px rgba(15, 23, 42, 0.16);
            margin: 16px auto 28px;
            min-height: 210mm;
            padding: 10mm;
            width: 297mm;
        }

        .official-frame {
            border: 1px solid #222222;
            box-shadow: inset 0 0 0 1px #8d8d8d;
            min-height: 190mm;
            padding: 7mm 7mm 6mm;
            position: relative;
        }

        .official-frame:before {
            border: 0;
            border-top: 1px solid #d1d5db;
            bottom: auto;
            content: '';
            left: 3mm;
            pointer-events: none;
            position: absolute;
            right: 3mm;
            top: 3mm;
        }

        .content {
            position: relative;
            z-index: 1;
        }

        .print-header {
            border-bottom: 1px solid #777777;
            margin-bottom: 6mm;
            padding-bottom: 3mm;
            position: relative;
            text-align: center;
        }

        .print-logo {
            height: 20mm;
            object-fit: contain;
            position: absolute;
            right: 0;
            top: 0;
            width: 20mm;
        }

        .print-header .university-name {
            font-size: 18.5px;
            font-weight: 700;
            line-height: 1.55;
        }

        .print-header .college-name,
        .print-header .department-name {
            font-size: 15px;
            font-weight: 700;
            line-height: 1.55;
            margin-top: 1px;
        }

        .print-header .program-title {
            background: #f1f1f1;
            border: 1px solid #111111;
            display: inline-block;
            font-size: 17px;
            font-weight: 700;
            line-height: 1.45;
            margin-top: 3.5mm;
            min-width: 68%;
            padding: 3px 24px 4px;
        }

        .draft-banner {
            background: #fff7ed;
            border: 2px solid #c2410c;
            color: #9a3412;
            display: inline-block;
            font-size: 18px;
            font-weight: 700;
            line-height: 1.4;
            margin-bottom: 3mm;
            padding: 3px 28px 4px;
        }

        .schedule-table {
            border: 1.5px solid #111111;
            border-collapse: collapse;
            font-size: 10.5px;
            table-layout: fixed;
            width: 100%;
        }

        .schedule-table th,
        .schedule-table td {
            border: 1px solid #222222;
            padding: 4px 5px;
            text-align: center;
            vertical-align: middle;
        }

        .schedule-table thead th {
            background: #d9d9d9;
            color: #111111;
            font-size: 11px;
            font-weight: 700;
            height: 27px;
            line-height: 1.35;
        }

        .schedule-table tbody td {
            height: 38px;
        }

        .day-column,
        .date-column {
            background: #f5f5f5;
            font-weight: 700;
            width: 8.5%;
        }

        .date-column {
            direction: ltr;
            font-size: 10px;
            font-variant-numeric: tabular-nums;
            unicode-bidi: embed;
        }

        .year-column {
            background: #d9d9d9;
        }

        .subject-entry {
            margin: 0 auto;
            padding: 1px 2px;
        }

        .subject-entry + .subject-entry {
            border-top: 1px solid #b7b7b7;
            margin-top: 4px;
            padding-top: 5px;
        }

        .subject-name {
            color: #000000;
            font-size: 11px;
            font-weight: 700;
            line-height: 1.35;
        }

        .exam-time {
            color: #111111;
            direction: ltr;
            font-size: 10px;
            font-variant-numeric: tabular-nums;
            font-weight: 700;
            line-height: 1.3;
            margin-top: 2px;
            unicode-bidi: embed;
        }

        .empty-cell {
            color: transparent;
        }

        .empty-state {
            color: #6b7280;
            font-size: 13px;
            font-weight: 700;
            height: 64px;
        }

        .notes-row {
            color: #444444;
            font-size: 9.5px;
            margin-top: 5mm;
            text-align: right;
        }

        .signature-table {
            border-collapse: collapse;
            margin-top: 13mm;
            table-layout: fixed;
            width: 100%;
        }

        .signature-table td {
            border: 0;
            text-align: center;
            vertical-align: top;
            width: 33.333%;
        }

        .signature-title {
            color: #111111;
            font-size: 12px;
            font-weight: 700;
        }

        .signature-line {
            border-bottom: 1px solid #111111;
            height: 15mm;
            margin: 4mm auto 0;
            width: 68%;
        }

        .signature-caption {
            color: #777777;
            font-size: 9px;
            margin-top: 2px;
        }

        .print-footer {
            border-top: 1px solid #a3a3a3;
            color: #555555;
            font-size: 9px;
            margin-top: 6mm;
            padding-top: 2mm;
            text-align: center;
        }

        @media (max-width: 1100px) {
            .toolbar-inner {
                grid-template-columns: repeat(2, minmax(140px, 1fr));
            }

            .sheet {
                width: 100%;
            }
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

            .official-frame {
                border: 0;
                box-shadow: none;
                min-height: auto;
                padding: 0;
            }

            .official-frame:before {
                display: none;
            }

            .print-header {
                margin-bottom: 5mm;
                padding-bottom: 2.5mm;
            }

            .schedule-table {
                width: 100%;
            }

            thead {
                display: table-header-group;
            }

            tfoot {
                display: table-footer-group;
            }

            .subject-entry,
            .signature-table,
            tr {
                break-inside: avoid;
                page-break-inside: avoid;
            }
        }

        @if ($isPdfDownload)
            .sheet {
                border: 0;
                box-shadow: none;
                margin: 0;
                min-height: auto;
                padding: 0;
                width: auto;
            }

            .official-frame {
                border: 0;
                box-shadow: none;
                min-height: auto;
                padding: 0;
            }

            .official-frame:before {
                display: none;
            }
        @endif
    </style>
</head>
<body>
    @if (! $isPdfDownload)
        <div class="screen-toolbar no-print">
            @if ($fixedProgram)
                <div class="toolbar-inner">
                    <a class="toolbar-link" href="{{ $fixedProgramsUrl }}">عرض البرامج المثبتة</a>
                    <button class="toolbar-button primary" type="button" onclick="window.print()">طباعة البرنامج المثبت</button>
                    <a class="toolbar-link" href="{{ $pdfUrl }}">تحميل PDF</a>
                </div>
            @else
            <form class="toolbar-inner" method="GET" action="{{ route('filament.adminpanel.exam-schedules.print') }}">
                @if ($isDraft)
                    <input type="hidden" name="source" value="draft">
                @endif

                @if (\App\Support\ExamCollegeScope::isSuperAdmin())
                    <div class="toolbar-field">
                        <label for="college_id">الكلية</label>
                        <select id="college_id" name="college_id">
                            @foreach ($filterOptions['colleges'] as $id => $name)
                                <option value="{{ $id }}" @selected((int) $filters['college_id'] === (int) $id)>{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                @else
                    <input type="hidden" name="college_id" value="{{ $filters['college_id'] }}">
                @endif

                <div class="toolbar-field">
                    <label for="department_id">القسم</label>
                    <select id="department_id" name="department_id">
                        <option value="">كل الأقسام</option>
                        @foreach ($filterOptions['departments'] as $id => $name)
                            <option value="{{ $id }}" @selected((int) $filters['department_id'] === (int) $id)>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="toolbar-field">
                    <label for="academic_year_id">العام الدراسي</label>
                    <select id="academic_year_id" name="academic_year_id">
                        @foreach ($filterOptions['academicYears'] as $id => $name)
                            <option value="{{ $id }}" @selected((int) $filters['academic_year_id'] === (int) $id)>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="toolbar-field">
                    <label for="semester_id">الفصل الدراسي</label>
                    <select id="semester_id" name="semester_id">
                        @foreach ($filterOptions['semesters'] as $id => $name)
                            <option value="{{ $id }}" @selected((int) $filters['semester_id'] === (int) $id)>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>

                <button class="toolbar-button" type="submit">تحديث المعاينة</button>
                <button class="toolbar-button primary" type="button" onclick="window.print()">{{ $isDraft ? 'طباعة مسودة البرنامج' : 'طباعة البرنامج المثبت' }}</button>
                <a class="toolbar-link" href="{{ $pdfUrl }}">تحميل PDF</a>
            </form>
            @endif
        </div>
    @endif

    <main class="sheet">
        <section class="official-frame">
            <div class="content">
                <header class="print-header">
                    @if ($universityLogo)
                        <img src="{{ $universityLogo }}" alt="{{ $universityName }}" class="print-logo">
                    @endif
                    <div class="university-name">{{ $universityName }}</div>
                    <div class="college-name">{{ $collegeName }}</div>
                    <div class="department-name">{{ $departmentName }}</div>
                    @if ($isDraft)
                        <div class="draft-banner">مسودة البرنامج</div>
                    @endif
                    <div class="program-title">{{ $reportTitle }}</div>
                </header>

                <table class="schedule-table">
                    <thead>
                        <tr>
                            <th class="day-column">اليوم</th>
                            <th class="date-column">التاريخ</th>
                            @foreach ($levels as $level)
                                <th class="year-column" style="width: {{ $levelWidth }}%;">{{ $levelLabel($level->name) }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr>
                                <td class="day-column">{{ $row['day'] }}</td>
                                <td class="date-column">{{ $row['date'] }}</td>
                                @foreach ($levels as $level)
                                    @php
                                        $cellEntries = $row['cells'][$level->id] ?? collect();
                                    @endphp
                                    <td>
                                        @forelse ($cellEntries as $entry)
                                            <div class="subject-entry">
                                                <div class="subject-name">{{ $entry['subject'] }}</div>
                                                @if (filled($entry['time']))
                                                    <div class="exam-time">{{ $entry['time'] }}</div>
                                                @endif
                                            </div>
                                        @empty
                                            <span class="empty-cell">—</span>
                                        @endforelse
                                    </td>
                                @endforeach
                            </tr>
                        @empty
                            <tr>
                                <td class="empty-state" colspan="{{ 2 + $levelsCount }}">
                                    {{ $isDraft ? 'لا توجد مواد امتحانية ضمن مسودة البرنامج والفلاتر المحددة.' : 'لا توجد مواد امتحانية معتمدة ضمن الفلاتر المحددة.' }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>

                <div class="notes-row">
                    {{ $isDraft ? 'ملاحظة: هذه الوثيقة مسودة برنامج امتحاني للمراجعة قبل التثبيت والاعتماد، ولا تمثل نسخة رسمية نهائية.' : 'ملاحظة: تعرض هذه الوثيقة برنامج الامتحان الرسمي للمواد المعتمدة فقط، ولا تمثل تقرير توزيع الطلاب على القاعات.' }}
                </div>

                <table class="signature-table">
                    <tr>
                        <td>
                            <div class="signature-title">رئيس القسم</div>
                            <div class="signature-line"></div>
                            <div class="signature-caption">التوقيع والختم</div>
                        </td>
                        <td>
                            <div class="signature-title">رئيس الدائرة الامتحانية</div>
                            <div class="signature-line"></div>
                            <div class="signature-caption">التوقيع والختم</div>
                        </td>
                        <td>
                            <div class="signature-title">عميد الكلية</div>
                            <div class="signature-line"></div>
                            <div class="signature-caption">التوقيع والختم</div>
                        </td>
                    </tr>
                </table>

                <div class="print-footer">
                    {{ $universityName }} - {{ $collegeName }} - {{ $departmentName }}
                </div>
            </div>
        </section>
    </main>
</body>
</html>
