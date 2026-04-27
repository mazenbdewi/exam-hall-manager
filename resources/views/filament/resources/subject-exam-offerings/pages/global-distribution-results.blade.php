<x-filament-panels::page>
    @php
        $run = $this->run;
        $summary = $run?->summary_json ?? [];
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
            __('exam.fields.status') => $run->statusLabel(),
            __('exam.fields.executed_at') => $run->executed_at?->format('Y-m-d H:i'),
        ] : [];
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
                            {{ $run->status === 'success' ? __('exam.global_hall_distribution.success_message') : ($run->status === 'partial' ? __('exam.global_hall_distribution.partial_message') : __('exam.global_hall_distribution.failed_message')) }}
                        </h2>
                        <p class="mt-1 text-sm {{ $statusTone === 'success' ? 'text-success-800 dark:text-success-200' : ($statusTone === 'warning' ? 'text-warning-800 dark:text-warning-200' : 'text-danger-800 dark:text-danger-200') }}">
                            {{ $run->notes ?: __('exam.global_hall_distribution.results_hint') }}
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <x-filament::button color="gray" icon="heroicon-o-document-arrow-down" wire:click="exportSummaryPdf">
                            {{ __('exam.actions.export_global_distribution_summary_pdf') }}
                        </x-filament::button>
                        <x-filament::button color="warning" icon="heroicon-o-document-arrow-down" wire:click="exportUnassignedPdf">
                            {{ __('exam.actions.export_unassigned_students_pdf') }}
                        </x-filament::button>
                        <x-filament::button color="gray" icon="heroicon-o-table-cells" wire:click="exportUnassignedExcel">
                            {{ __('exam.actions.export_unassigned_students_excel') }}
                        </x-filament::button>
                    </div>
                </div>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($cards as $label => $value)
                    <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                        <div class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</div>
                        <div class="mt-2 text-xl font-semibold text-gray-950 dark:text-white">{{ $value }}</div>
                    </div>
                @endforeach
            </div>

            @if ($run->unassigned_students > 0)
                <div class="rounded-lg border border-danger-200 bg-danger-50 p-4 shadow-sm dark:border-danger-500/20 dark:bg-danger-500/10">
                    <h2 class="text-base font-semibold text-danger-900 dark:text-danger-200">{{ __('exam.global_hall_distribution.problem_title') }}</h2>
                    <p class="mt-1 text-sm text-danger-800 dark:text-danger-200">{{ __('exam.global_hall_distribution.problem_message') }}</p>
                    <div class="mt-3 flex flex-wrap gap-2 text-sm">
                        @foreach (__('exam.global_hall_distribution.suggested_actions') as $action)
                            <span class="rounded-full bg-white px-3 py-1 text-danger-700 dark:bg-black/10 dark:text-danger-200">{{ $action }}</span>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="grid gap-4 lg:grid-cols-3">
                <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                    <h3 class="mb-3 font-semibold text-gray-950 dark:text-white">{{ __('exam.global_hall_distribution.by_slot') }}</h3>
                    <div class="space-y-2 text-sm">
                        @forelse ($summary['unassigned_by_slot'] ?? [] as $slot)
                            <div class="rounded-md bg-gray-50 p-2 dark:bg-white/5">
                                {{ $slot['exam_date'] }} · {{ substr((string) $slot['start_time'], 0, 5) }}
                                <br>
                                {{ __('exam.fields.unassigned_students') }}: {{ $slot['unassigned_count'] ?? 0 }}
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
                        @forelse ($summary['unassigned_by_subject'] ?? [] as $subject)
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
                        @forelse ($run->issues->groupBy('message') as $reason => $issues)
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
