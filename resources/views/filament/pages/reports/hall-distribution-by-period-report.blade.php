<x-filament-panels::page>
    @php
        $report = $show_report ? $this->reportData() : null;
        $meta = $report['meta'] ?? [];
        $periods = $report['periods'] ?? [];
        $hasSavedDistribution = (bool) ($report['has_saved_distribution'] ?? false);
    @endphp

    <style>
        .hall-period-report {
            direction: rtl;
            text-align: right;
        }

        .report-print-area {
            background: #ffffff;
            color: #111111;
            direction: rtl;
        }

        .report-document-header {
            text-align: center;
        }

        .report-document-header .line {
            font-size: 15px;
            font-weight: 700;
            line-height: 1.7;
        }

        .report-title {
            border: 1px solid #111111;
            display: inline-block;
            font-size: 16px;
            font-weight: 700;
            margin-top: 8px;
            padding: 4px 18px;
        }

        .filters-summary {
            display: flex;
            flex-wrap: wrap;
            gap: 8px 18px;
            justify-content: center;
            margin-top: 10px;
            font-size: 12px;
            font-weight: 700;
        }

        .period-block {
            margin-top: 18px;
        }

        .period-title {
            font-size: 18px;
            font-weight: 700;
            margin: 18px 0 8px;
            text-align: center;
        }

        .distribution-table {
            border-collapse: collapse;
            table-layout: fixed;
            width: 100%;
        }

        .distribution-table th,
        .distribution-table td {
            border: 1px solid #000000;
            font-size: 10px;
            line-height: 1.25;
            padding: 3px 4px;
            text-align: center;
            vertical-align: middle;
        }

        .distribution-table th {
            background: #eeeeee;
            font-weight: 700;
        }

        .subject-cell {
            font-weight: 700;
            text-align: right !important;
            width: 160px;
        }

        .subject-meta {
            display: block;
            font-size: 9px;
            font-weight: 400;
            margin-top: 2px;
        }

        .total-cell {
            font-weight: 700;
            width: 45px;
        }

        .empty-report-message {
            border: 1px dashed #9ca3af;
            font-size: 14px;
            font-weight: 700;
            margin-top: 18px;
            padding: 18px;
            text-align: center;
        }

        @page {
            size: A4 landscape;
            margin: 8mm;
        }

        @media print {
            body {
                background: #ffffff !important;
                color: #000000 !important;
                direction: rtl;
            }

            .no-print,
            aside,
            nav,
            header,
            .fi-sidebar,
            .fi-topbar,
            .fi-header,
            .fi-breadcrumbs {
                display: none !important;
            }

            .fi-main,
            .fi-main-ctn,
            .fi-page,
            .fi-page-content {
                margin: 0 !important;
                max-width: none !important;
                padding: 0 !important;
                width: 100% !important;
            }

            .report-print-area {
                width: 100%;
            }

            table {
                border-collapse: collapse;
                table-layout: fixed;
                width: 100%;
            }

            th,
            td {
                border: 1px solid #000000;
                font-size: 10px;
                line-height: 1.25;
                padding: 3px 4px;
                text-align: center;
                vertical-align: middle;
            }

            th {
                background: #eeeeee !important;
                font-weight: 700;
            }

            .period-title {
                font-size: 18px;
                font-weight: 700;
                margin: 18px 0 8px;
                text-align: center;
            }

            .subject-cell {
                font-weight: 700;
                text-align: right !important;
                width: 160px;
            }

            .total-cell {
                font-weight: 700;
                width: 45px;
            }

            tr {
                page-break-inside: avoid;
            }

            .period-block {
                margin-bottom: 18px;
                page-break-inside: avoid;
            }
        }
    </style>

    <div dir="rtl" class="hall-period-report space-y-5 text-right">
        <section class="no-print rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div class="mb-4 flex items-center gap-2">
                <x-filament::icon icon="heroicon-o-adjustments-horizontal" class="h-5 w-5 text-primary-600 dark:text-primary-300" />
                <h2 class="text-base font-bold text-gray-950 dark:text-white">فلاتر التقرير</h2>
            </div>

            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                @if (\App\Support\ExamCollegeScope::isSuperAdmin())
                    <label class="space-y-1">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-200">الكلية</span>
                        <select wire:model.live="college_id" class="w-full rounded-md border-gray-300 dark:border-white/10 dark:bg-gray-800">
                            @foreach ($this->collegeOptions() as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif

                <label class="space-y-1">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">القسم</span>
                    <select wire:model.live="department_id" class="w-full rounded-md border-gray-300 dark:border-white/10 dark:bg-gray-800">
                        <option value="">كل الأقسام</option>
                        @foreach ($this->departmentOptions() as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="space-y-1">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">العام الدراسي</span>
                    <select wire:model.live="academic_year_id" class="w-full rounded-md border-gray-300 dark:border-white/10 dark:bg-gray-800">
                        <option value="">كل الأعوام</option>
                        @foreach ($this->academicYearOptions() as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="space-y-1">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">الفصل الدراسي</span>
                    <select wire:model.live="semester_id" class="w-full rounded-md border-gray-300 dark:border-white/10 dark:bg-gray-800">
                        <option value="">كل الفصول</option>
                        @foreach ($this->semesterOptions() as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="space-y-1">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">من تاريخ</span>
                    <input type="date" wire:model.live="date_from" class="w-full rounded-md border-gray-300 dark:border-white/10 dark:bg-gray-800">
                </label>

                <label class="space-y-1">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">إلى تاريخ</span>
                    <input type="date" wire:model.live="date_to" class="w-full rounded-md border-gray-300 dark:border-white/10 dark:bg-gray-800">
                </label>

                <label class="space-y-1 md:col-span-2">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">الفترة</span>
                    <select wire:model.live="exam_time_slot" class="w-full rounded-md border-gray-300 dark:border-white/10 dark:bg-gray-800">
                        <option value="">كل الفترات</option>
                        @foreach ($this->timeSlotOptions() as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            <div class="mt-4 flex flex-wrap gap-2">
                <x-filament::button icon="heroicon-o-eye" wire:click="showReport">
                    عرض التقرير
                </x-filament::button>
                <x-filament::button color="success" icon="heroicon-o-printer" type="button" onclick="window.print()">
                    طباعة
                </x-filament::button>
                <x-filament::button color="gray" icon="heroicon-o-arrow-path" wire:click="resetFilters">
                    إعادة ضبط الفلاتر
                </x-filament::button>
            </div>
        </section>

        <section class="report-print-area rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div class="report-document-header">
                <div class="line">{{ $meta['university_name'] ?? '' }}</div>
                <div class="line">{{ $meta['college_name'] ?? '' }}</div>
                <div class="line">{{ $meta['department_name'] ?? '' }}</div>
                <div class="report-title">تقرير توزيع أعداد الطلاب على القاعات حسب الفترة الامتحانية</div>
                <div class="filters-summary">
                    <span>العام الدراسي: {{ $meta['academic_year'] ?? '—' }}</span>
                    <span>الفصل الدراسي: {{ $meta['semester'] ?? '—' }}</span>
                    <span>الفترة من: {{ $meta['date_from'] ?? '—' }} إلى: {{ $meta['date_to'] ?? '—' }}</span>
                </div>
            </div>

            @if (! $show_report)
                <div class="empty-report-message">اختر الفلاتر ثم اضغط عرض التقرير.</div>
            @elseif (! $hasSavedDistribution)
                <div class="empty-report-message">لا توجد عملية توزيع محفوظة ضمن الفلاتر المحددة.</div>
            @elseif ($periods === [])
                <div class="empty-report-message">لا توجد بيانات توزيع طلاب لهذه الفترة.</div>
            @else
                @foreach ($periods as $period)
                    <div class="period-block">
                        <div class="period-title">{{ $period['title'] }}</div>

                        <div class="overflow-x-auto">
                            <table class="distribution-table">
                                <thead>
                                    <tr>
                                        <th class="subject-cell">اسم المادة</th>
                                        <th class="total-cell">العدد الكلي</th>
                                        @foreach ($period['halls'] as $hall)
                                            <th>{{ $hall['name'] }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($period['subjects'] as $subject)
                                        <tr>
                                            <td class="subject-cell">
                                                {{ $subject['name'] }}
                                                @if (filled($subject['department_name'] ?? null))
                                                    <span class="subject-meta">({{ $subject['department_name'] }})</span>
                                                @endif
                                            </td>
                                            <td class="total-cell">{{ $subject['total'] }}</td>
                                            @foreach ($period['halls'] as $hall)
                                                @php
                                                    $count = (int) ($subject['hall_counts'][$hall['id']] ?? 0);
                                                @endphp
                                                <td>{{ $count > 0 ? $count : '-' }}</td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endforeach
            @endif
        </section>
    </div>
</x-filament-panels::page>
