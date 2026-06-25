<x-filament-panels::page>
    @php
        $run = $this->run;
        $summary = $run?->summary_json ?? [];
        $validation = $summary['validation'] ?? [];
        $unassignedCount = $this->savedUnassignedStudentsCount();
        $canExportUnassignedReports = $this->canExportUnassignedReports();
        $unassignedReportDisabledMessage = $this->unassignedReportDisabledMessage();
        $statusTone = match ($run?->status) {
            'success' => 'success',
            'partial' => 'warning',
            'failed' => 'danger',
            default => 'gray',
        };
        $cards = $run ? [
            __('exam.fields.college') => $run->college?->name,
            __('exam.fields.period') => $run->from_date?->format('Y-m-d') . ' - ' . $run->to_date?->format('Y-m-d'),
            __('exam.global_hall_distribution.summary.offerings_count') => $run->total_offerings,
            __('exam.global_hall_distribution.summary.slots_count') => $run->total_slots,
            __('exam.global_hall_distribution.summary.students_count') => $run->total_students,
            __('exam.global_hall_distribution.summary.assigned_students_count') => $run->distributed_students,
            __('exam.global_hall_distribution.summary.unassigned_students_count') => $run->unassigned_students,
            __('exam.global_hall_distribution.summary.used_halls_count') => $run->used_halls,
            __('exam.global_hall_distribution.summary.total_capacity') => $run->total_capacity,
            __('exam.global_hall_distribution.summary.capacity_shortage') => $run->capacity_shortage,
            __('exam.global_hall_distribution.summary.remaining_capacity') => $validation['remaining_capacity'] ?? 0,
            __('exam.global_hall_distribution.summary.separate_carry_students') => (bool) ($summary['separate_carry_students'] ?? false) ? 'نعم' : 'لا',
            __('exam.global_hall_distribution.summary.carry_students_count') => $summary['carry_students_count'] ?? 0,
            __('exam.global_hall_distribution.summary.regular_students_count') => $summary['regular_students_count'] ?? 0,
            __('exam.global_hall_distribution.summary.carry_halls_count') => $summary['carry_halls_count'] ?? 0,
            __('exam.global_hall_distribution.summary.regular_halls_count') => $summary['regular_halls_count'] ?? 0,
            __('exam.global_hall_distribution.summary.mixing_cases_count') => $summary['carry_regular_mixing_cases_count'] ?? 0,
            __('exam.fields.status') => $run->statusLabel(),
            __('exam.fields.executed_at') => $run->executed_at?->format('Y-m-d H:i'),
        ] : [];
        $problemSlots = collect($summary['unassigned_by_slot'] ?? [])
            ->filter(fn (array $slot): bool => (int) ($slot['unassigned_count'] ?? 0) > 0
                || (int) ($slot['capacity_shortage'] ?? $slot['shortage_count'] ?? 0) > 0
                || (int) ($slot['mixed_halls_count'] ?? 0) > 0)
            ->values();
        $problemSubjects = collect($summary['unassigned_by_subject'] ?? [])
            ->filter(fn (array $subject): bool => (int) ($subject['unassigned_count'] ?? 0) > 0)
            ->values();
        $problemIssues = $run?->issues
            ? $run->issues->filter(fn ($issue): bool => (int) $issue->affected_students_count > 0)->values()
            : collect();
        $slotSummaries = collect($summary['slots'] ?? []);
        $failureDetails = collect($this->failureDetails());
    @endphp

    <div dir="rtl" class="space-y-5 text-right">
        @if (! $run)
            <div class="rounded-lg border border-gray-200 bg-white p-4 text-sm text-gray-600 shadow-sm dark:border-white/10 dark:bg-gray-900 dark:text-gray-300">
                {{ __('exam.global_hall_distribution.no_previous_run') }}
            </div>
        @else
            <div class="rounded-lg border p-4 shadow-sm {{ $statusTone === 'success' ? 'border-success-200 bg-success-50 dark:border-success-500/20 dark:bg-success-500/10' : ($statusTone === 'warning' ? 'border-warning-200 bg-warning-50 dark:border-warning-500/20 dark:bg-warning-500/10' : 'border-danger-200 bg-danger-50 dark:border-danger-500/20 dark:bg-danger-500/10') }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold {{ $statusTone === 'success' ? 'text-success-900 dark:text-success-200' : ($statusTone === 'warning' ? 'text-warning-900 dark:text-warning-200' : 'text-danger-900 dark:text-danger-200') }}">
                            {{ $run->status === 'success' ? __('exam.global_hall_distribution.success_message') : ($run->status === 'partial' ? __('exam.global_hall_distribution.partial_message') : __('exam.global_hall_distribution.failed_message_detailed')) }}
                        </h2>
                        <p class="mt-1 text-sm {{ $statusTone === 'success' ? 'text-success-800 dark:text-success-200' : ($statusTone === 'warning' ? 'text-warning-800 dark:text-warning-200' : 'text-danger-800 dark:text-danger-200') }}">
                            {{ $run->status === 'success' && $unassignedCount === 0 ? __('exam.global_hall_distribution.no_issues_success_hint') : ($run->notes ?: __('exam.global_hall_distribution.results_hint')) }}
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <x-filament::button color="gray" icon="heroicon-o-document-arrow-down" wire:click="exportSummaryPdf">
                            {{ __('exam.actions.export_global_distribution_summary_pdf') }}
                        </x-filament::button>
                        <span title="{{ $canExportUnassignedReports ? '' : $unassignedReportDisabledMessage }}">
                            <x-filament::button color="warning" icon="heroicon-o-document-arrow-down" wire:click="exportUnassignedPdf" :disabled="! $canExportUnassignedReports">
                                {{ __('exam.actions.export_unassigned_students_pdf') }}
                                @if ($canExportUnassignedReports)
                                    ({{ $unassignedCount }})
                                @endif
                            </x-filament::button>
                        </span>
                        <span title="{{ $canExportUnassignedReports ? '' : $unassignedReportDisabledMessage }}">
                            <x-filament::button color="gray" icon="heroicon-o-table-cells" wire:click="exportUnassignedExcel" :disabled="! $canExportUnassignedReports">
                                {{ __('exam.actions.export_unassigned_students_excel') }}
                                @if ($canExportUnassignedReports)
                                    ({{ $unassignedCount }})
                                @endif
                            </x-filament::button>
                        </span>
                    </div>
                </div>
                @if (! $canExportUnassignedReports)
                    <p class="mt-3 text-sm text-success-800 dark:text-success-200">{{ $unassignedReportDisabledMessage }}</p>
                @endif
            </div>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($cards as $label => $value)
                    <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                        <div class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</div>
                        <div class="mt-2 text-xl font-semibold text-gray-950 dark:text-white">{{ $value }}</div>
                    </div>
                @endforeach
            </div>

            @if ((bool) ($summary['separate_carry_students'] ?? false))
                @php
                    $hasMixing = (int) ($summary['carry_regular_mixing_cases_count'] ?? 0) > 0;
                @endphp
                <div class="rounded-lg border p-4 shadow-sm {{ $hasMixing ? 'border-warning-200 bg-warning-50 dark:border-warning-500/20 dark:bg-warning-500/10' : 'border-success-200 bg-success-50 dark:border-success-500/20 dark:bg-success-500/10' }}">
                    <h3 class="font-semibold {{ $hasMixing ? 'text-warning-900 dark:text-warning-200' : 'text-success-900 dark:text-success-200' }}">
                        {{ __('exam.global_hall_distribution.distribution_settings_title') }}
                    </h3>
                    <p class="mt-2 text-sm {{ $hasMixing ? 'text-warning-800 dark:text-warning-200' : 'text-success-800 dark:text-success-200' }}">
                        {{ $summary['separation_status_message'] ?? __('exam.global_hall_distribution.carry_regular_separated_success') }}
                    </p>
                </div>
            @endif

            @if ($unassignedCount > 0)
                <div class="rounded-lg border border-danger-200 bg-danger-50 p-4 shadow-sm dark:border-danger-500/20 dark:bg-danger-500/10">
                    <h2 class="text-base font-semibold text-danger-900 dark:text-danger-200">{{ __('exam.global_hall_distribution.problem_title') }}</h2>
                    <p class="mt-1 text-sm text-danger-800 dark:text-danger-200">
                        {{ __('exam.global_hall_distribution.problem_message') }}
                        {{ __('exam.global_hall_distribution.summary.unassigned_students_count') }}: {{ $unassignedCount }}
                    </p>
                    <div class="mt-3 flex flex-wrap gap-2 text-sm">
                        @foreach (__('exam.global_hall_distribution.suggested_actions') as $action)
                            <span class="rounded-full bg-white px-3 py-1 text-danger-700 dark:bg-black/10 dark:text-danger-200">{{ $action }}</span>
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($failureDetails->isNotEmpty())
                <div class="rounded-lg border border-danger-200 bg-white p-4 shadow-sm dark:border-danger-500/20 dark:bg-gray-900">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="font-semibold text-gray-950 dark:text-white">{{ __('exam.global_hall_distribution.failure_details_title') }}</h3>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ __('exam.global_hall_distribution.failure_details_hint') }}</p>
                        </div>
                        <x-filament::button color="gray" icon="heroicon-o-building-office-2" tag="a" :href="$failureDetails->first()['halls_url'] ?? '#'">
                            {{ __('exam.global_hall_distribution.actions.open_halls') }}
                        </x-filament::button>
                    </div>

                    <div class="mt-4 overflow-x-auto">
                        <table class="w-full min-w-[1180px] divide-y divide-gray-200 text-sm dark:divide-white/10">
                            <thead>
                                <tr class="bg-gray-50 text-gray-600 dark:bg-white/5 dark:text-gray-300">
                                    <th class="px-3 py-2 text-right font-semibold">{{ __('exam.fields.subject') }}</th>
                                    <th class="px-3 py-2 text-right font-semibold">{{ __('exam.fields.college') }}</th>
                                    <th class="px-3 py-2 text-right font-semibold">{{ __('exam.fields.department') }}</th>
                                    <th class="px-3 py-2 text-right font-semibold">{{ __('exam.fields.students_count') }}</th>
                                    <th class="px-3 py-2 text-right font-semibold">{{ __('exam.fields.exam_date') }}</th>
                                    <th class="px-3 py-2 text-right font-semibold">{{ __('exam.fields.period') }}</th>
                                    <th class="px-3 py-2 text-right font-semibold">{{ __('exam.global_hall_distribution.required_hall_type') }}</th>
                                    <th class="px-3 py-2 text-right font-semibold">{{ __('exam.fields.available_halls') }}</th>
                                    <th class="px-3 py-2 text-right font-semibold">{{ __('exam.fields.available_capacity') }}</th>
                                    <th class="px-3 py-2 text-right font-semibold">{{ __('exam.global_hall_distribution.required_capacity') }}</th>
                                    <th class="px-3 py-2 text-right font-semibold">{{ __('exam.global_hall_distribution.reason_code') }}</th>
                                    <th class="px-3 py-2 text-right font-semibold">{{ __('exam.fields.reason') }}</th>
                                    <th class="px-3 py-2 text-right font-semibold">{{ __('exam.global_hall_distribution.suggested_action') }}</th>
                                    <th class="px-3 py-2 text-right font-semibold">{{ __('exam.fields.details') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                                @foreach ($failureDetails as $detail)
                                    <tr class="align-top text-gray-700 dark:text-gray-200">
                                        <td class="px-3 py-3 font-medium text-gray-950 dark:text-white">{{ $detail['subject_name'] ?? '—' }}</td>
                                        <td class="px-3 py-3">{{ $detail['college_name'] ?? '—' }}</td>
                                        <td class="px-3 py-3">{{ $detail['department_name'] ?? '—' }}</td>
                                        <td class="px-3 py-3">{{ $detail['students_count'] ?? 0 }}</td>
                                        <td class="px-3 py-3">{{ $detail['exam_date'] ?? '—' }}</td>
                                        <td class="px-3 py-3">{{ substr((string) ($detail['start_time'] ?? ''), 0, 5) ?: '—' }}</td>
                                        <td class="px-3 py-3">{{ $detail['required_hall_type'] ?? '—' }}</td>
                                        <td class="px-3 py-3">
                                            {{ $detail['available_halls_count'] ?? 0 }}
                                            @if ((int) ($detail['busy_halls_count'] ?? 0) > 0)
                                                <div class="mt-1 text-xs text-warning-700 dark:text-warning-300">
                                                    {{ __('exam.global_hall_distribution.busy_halls_summary', [
                                                        'busy' => $detail['busy_halls_count'] ?? 0,
                                                        'total' => $detail['total_suitable_halls_count'] ?? 0,
                                                    ]) }}
                                                </div>
                                            @endif
                                        </td>
                                        <td class="px-3 py-3">{{ $detail['available_capacity'] ?? 0 }}</td>
                                        <td class="px-3 py-3">
                                            {{ $detail['required_capacity'] ?? 0 }}
                                            @if ((int) ($detail['capacity_shortage'] ?? 0) > 0)
                                                <div class="mt-1 text-xs font-medium text-danger-700 dark:text-danger-300">
                                                    {{ __('exam.global_hall_distribution.capacity_shortage_sentence', [
                                                        'students' => $detail['students_count'] ?? 0,
                                                        'capacity' => $detail['available_capacity'] ?? 0,
                                                        'shortage' => $detail['capacity_shortage'] ?? 0,
                                                    ]) }}
                                                </div>
                                            @endif
                                        </td>
                                        <td class="px-3 py-3 font-mono text-xs">{{ $detail['reason_code'] ?? 'unknown_distribution_error' }}</td>
                                        <td class="px-3 py-3">{{ $detail['reason_message'] ?? '—' }}</td>
                                        <td class="px-3 py-3">{{ $detail['suggested_action'] ?? '—' }}</td>
                                        <td class="px-3 py-3">
                                            <div class="flex flex-wrap gap-2">
                                                @if (filled($detail['subject_url'] ?? null))
                                                    <x-filament::button size="xs" color="gray" icon="heroicon-o-pencil-square" tag="a" :href="$detail['subject_url']">
                                                        {{ __('exam.global_hall_distribution.actions.open_subject') }}
                                                    </x-filament::button>
                                                @endif
                                                @if (filled($detail['distribution_url'] ?? null))
                                                    <x-filament::button size="xs" color="warning" icon="heroicon-o-arrow-path" tag="a" :href="$detail['distribution_url']">
                                                        {{ __('exam.global_hall_distribution.actions.review_slot_distribution') }}
                                                    </x-filament::button>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <h3 class="mb-3 font-semibold text-gray-950 dark:text-white">{{ __('exam.global_hall_distribution.validation_summary_title') }}</h3>
                <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3 text-sm">
                    <div class="rounded-md bg-gray-50 p-3 dark:bg-white/5">{{ __('exam.global_hall_distribution.validation.expected_students') }}: {{ $validation['expected_students'] ?? $run->total_students }}</div>
                    <div class="rounded-md bg-gray-50 p-3 dark:bg-white/5">{{ __('exam.global_hall_distribution.validation.assigned_students') }}: {{ $validation['assigned_students'] ?? $run->distributed_students }}</div>
                    <div class="rounded-md bg-gray-50 p-3 dark:bg-white/5">{{ __('exam.global_hall_distribution.validation.unassigned_students') }}: {{ $validation['unassigned_students'] ?? $run->unassigned_students }}</div>
                    <div class="rounded-md bg-gray-50 p-3 dark:bg-white/5">{{ __('exam.global_hall_distribution.validation.used_hall_capacity') }}: {{ $validation['used_hall_capacity'] ?? 0 }}</div>
                    <div class="rounded-md bg-gray-50 p-3 dark:bg-white/5">{{ __('exam.global_hall_distribution.validation.remaining_capacity') }}: {{ $validation['remaining_capacity'] ?? 0 }}</div>
                    <div class="rounded-md bg-gray-50 p-3 dark:bg-white/5">{{ __('exam.global_hall_distribution.validation.data_source') }}: {{ $validation['data_source'] ?? '—' }}</div>
                </div>
            </div>

            @if ($slotSummaries->isNotEmpty())
                <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                    <h3 class="mb-3 font-semibold text-gray-950 dark:text-white">{{ __('exam.global_hall_distribution.slot_summary_title') }}</h3>
                    <div class="grid gap-2 md:grid-cols-2 xl:grid-cols-3">
                        @foreach ($slotSummaries as $slot)
                            @php
                                $slotUnassigned = (int) ($slot['unassigned_students_count'] ?? 0);
                                $slotShortage = (int) ($slot['capacity_shortage'] ?? 0);
                                $slotOk = $slotUnassigned === 0 && $slotShortage === 0;
                            @endphp
                            <div class="rounded-md border border-gray-100 bg-gray-50 p-3 text-sm dark:border-white/10 dark:bg-white/5">
                                <div class="font-medium text-gray-950 dark:text-white">
                                    {{ $slot['exam_date'] ?? '—' }} · {{ substr((string) ($slot['exam_start_time'] ?? ''), 0, 5) }}
                                </div>
                                <div class="mt-1 text-gray-600 dark:text-gray-300">
                                    {{ __('exam.global_hall_distribution.summary.students_count') }}: {{ $slot['students_count'] ?? 0 }}
                                    · {{ __('exam.global_hall_distribution.summary.used_halls_count') }}: {{ $slot['used_halls_count'] ?? 0 }}
                                </div>
                                <div class="mt-1 font-semibold {{ $slotOk ? 'text-success-700 dark:text-success-300' : 'text-warning-700 dark:text-warning-300' }}">
                                    {{ $slotOk ? __('exam.global_hall_distribution.slot_all_distributed') : ($slot['message'] ?? __('exam.global_hall_distribution.partial_message')) }}
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="grid gap-4 lg:grid-cols-3">
                <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                    <h3 class="mb-3 font-semibold text-gray-950 dark:text-white">{{ __('exam.global_hall_distribution.by_slot') }}</h3>
                    <div class="space-y-2 text-sm">
                        @forelse ($problemSlots as $slot)
                            <div class="rounded-md bg-gray-50 p-2 dark:bg-white/5">
                                {{ $slot['exam_date'] }} · {{ substr((string) $slot['start_time'], 0, 5) }}
                                <br>
                                {{ __('exam.fields.unassigned_students') }}: {{ $slot['unassigned_count'] ?? 0 }}
                                @if ((int) ($slot['mixed_halls_count'] ?? 0) > 0)
                                    <br>
                                    {{ __('exam.global_hall_distribution.summary.mixing_cases_count') }}: {{ $slot['mixed_halls_count'] }}
                                @endif
                                <br>
                                {{ $slot['reason'] ?? '—' }}
                            </div>
                        @empty
                            <div class="text-gray-500">{{ __('exam.global_hall_distribution.no_grouped_issues') }}</div>
                        @endforelse
                    </div>
                </div>

                <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                    <h3 class="mb-3 font-semibold text-gray-950 dark:text-white">{{ __('exam.global_hall_distribution.by_subject') }}</h3>
                    <div class="space-y-2 text-sm">
                        @forelse ($problemSubjects as $subject)
                            <div class="rounded-md bg-gray-50 p-2 dark:bg-white/5">
                                {{ $subject['subject_name'] ?? '—' }}
                                <br>
                                {{ $subject['exam_date'] }} · {{ substr((string) $subject['start_time'], 0, 5) }}
                                <br>
                                {{ __('exam.fields.unassigned_students') }}: {{ $subject['unassigned_count'] ?? 0 }}
                            </div>
                        @empty
                            <div class="text-gray-500">{{ __('exam.global_hall_distribution.no_grouped_issues') }}</div>
                        @endforelse
                    </div>
                </div>

                <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                    <h3 class="mb-3 font-semibold text-gray-950 dark:text-white">{{ __('exam.global_hall_distribution.by_reason') }}</h3>
                    <div class="space-y-2 text-sm">
                        @forelse ($problemIssues->groupBy('message') as $reason => $issues)
                            <div class="rounded-md bg-gray-50 p-2 dark:bg-white/5">
                                {{ $reason }}
                                <br>
                                {{ __('exam.global_hall_distribution.summary.unassigned_students_count') }}: {{ $issues->sum('affected_students_count') }}
                            </div>
                        @empty
                            <div class="text-gray-500">{{ __('exam.global_hall_distribution.no_grouped_issues') }}</div>
                        @endforelse
                    </div>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
