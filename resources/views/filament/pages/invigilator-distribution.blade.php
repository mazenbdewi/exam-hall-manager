<x-filament-panels::page>
    @php
        $summary = $this->getSummaryData();
        $readiness = $this->getReadinessData();
        $disabledReasons = $this->distributionDisabledReasons();
        $cards = [
            __('exam.fields.total_invigilators') => $summary['total_invigilators'] ?? 0,
            __('exam.fields.available_invigilators') => $summary['available_invigilators'] ?? 0,
            __('exam.fields.reduced_invigilators_count') => $summary['reduced_invigilators_count'] ?? 0,
            __('exam.fields.exempt_invigilators_count') => $summary['exempt_invigilators_count'] ?? 0,
            __('exam.fields.used_halls') => $summary['halls_count'] ?? 0,
            __('exam.fields.required_count') => $summary['required_count'] ?? 0,
            __('exam.fields.assigned_count') => $summary['assigned_count'] ?? 0,
            __('exam.fields.missing_assignments_count') => $summary['shortage_count'] ?? 0,
            __('exam.fields.days_count') => $summary['days_count'] ?? 0,
            __('exam.fields.slots_count') => $summary['slots_count'] ?? 0,
        ];
    @endphp

    <div dir="rtl" class="space-y-5 text-right">
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div class="mb-4 grid gap-2 text-sm text-gray-600 dark:text-gray-300 md:grid-cols-5">
                <div><span class="font-semibold text-gray-950 dark:text-white">{{ __('exam.workflow.step_1') }}</span></div>
                <div><span class="font-semibold text-gray-950 dark:text-white">{{ __('exam.workflow.step_2') }}</span></div>
                <div><span class="font-semibold text-gray-950 dark:text-white">{{ __('exam.workflow.step_3') }}</span></div>
                <div><span class="font-semibold text-gray-950 dark:text-white">{{ __('exam.workflow.step_4') }}</span></div>
                <div><span class="font-semibold text-gray-950 dark:text-white">{{ __('exam.workflow.step_5') }}</span></div>
            </div>

            <div class="grid items-end gap-3 md:grid-cols-2 xl:grid-cols-5">
                @if (\App\Support\ExamCollegeScope::isSuperAdmin())
                    <label class="space-y-1">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('exam.fields.college') }}</span>
                        <select wire:model.live="college_id" class="w-full rounded-md border-gray-300 dark:border-white/10 dark:bg-gray-800">
                            @foreach ($this->collegeOptions() as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </label>
                @else
                    <div class="space-y-1">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('exam.fields.college') }}</span>
                        <div class="rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-white/10 dark:bg-white/5 dark:text-white">
                            {{ $summary['college']->name ?? '—' }}
                        </div>
                    </div>
                @endif

                <label class="space-y-1">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('exam.fields.from_date') }}</span>
                    <input type="date" wire:model.live="from_date" class="w-full rounded-md border-gray-300 dark:border-white/10 dark:bg-gray-800">
                </label>
                <label class="space-y-1">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('exam.fields.to_date') }}</span>
                    <input type="date" wire:model.live="to_date" class="w-full rounded-md border-gray-300 dark:border-white/10 dark:bg-gray-800">
                </label>
                @if ($this->hasExistingDistribution())
                    <x-filament::button
                        icon="heroicon-o-arrow-path"
                        wire:click="runDistribution"
                        wire:confirm="{{ __('exam.confirmations.rerun_invigilator_distribution') }}"
                        wire:loading.attr="disabled"
                        :disabled="! $this->canRunDistribution()"
                    >
                        {{ __('exam.actions.distribution') }}
                    </x-filament::button>
                @else
                    <x-filament::button
                        icon="heroicon-o-play"
                        wire:click="runDistribution"
                        wire:loading.attr="disabled"
                        :disabled="! $this->canRunDistribution()"
                    >
                        {{ __('exam.actions.distribution') }}
                    </x-filament::button>
                @endif
                <x-filament::button
                    color="info"
                    icon="heroicon-o-scale"
                    wire:click="createFairBalancedDraft"
                    wire:loading.attr="disabled"
                    :disabled="! ($readiness['is_ready'] ?? false)"
                >
                    {{ __('exam.fair_draft.actions.fair_distribution') }}
                </x-filament::button>
            </div>
        </div>

        <div class="rounded-lg border p-4 shadow-sm {{ ($readiness['is_ready'] ?? false) ? 'border-success-200 bg-success-50 dark:border-success-500/20 dark:bg-success-500/10' : 'border-danger-200 bg-danger-50 dark:border-danger-500/20 dark:bg-danger-500/10' }}">
            <div class="mb-3 flex items-start gap-3">
                <x-filament::icon :icon="($readiness['is_ready'] ?? false) ? 'heroicon-o-check-circle' : 'heroicon-o-exclamation-circle'" class="mt-0.5 h-6 w-6 shrink-0 {{ ($readiness['is_ready'] ?? false) ? 'text-success-600 dark:text-success-300' : 'text-danger-600 dark:text-danger-300' }}" />
                <div>
                    <h2 class="text-base font-semibold {{ ($readiness['is_ready'] ?? false) ? 'text-success-900 dark:text-success-200' : 'text-danger-900 dark:text-danger-200' }}">
                        {{ __('exam.readiness.title') }}
                    </h2>
                    <p class="mt-1 text-sm {{ ($readiness['is_ready'] ?? false) ? 'text-success-800 dark:text-success-200' : 'text-danger-800 dark:text-danger-200' }}">
                        {{ ($readiness['is_ready'] ?? false) ? __('exam.readiness.ready_message') : ($readiness['blocking_message'] ?? __('exam.readiness.not_ready_message')) }}
                    </p>
                </div>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ([
                    __('exam.readiness.offerings_count') => $readiness['offerings_count'] ?? 0,
                    __('exam.readiness.slots_count') => $readiness['slots_count'] ?? 0,
                    __('exam.readiness.distributed_slots_count') => $readiness['distributed_slots_count'] ?? 0,
                    __('exam.readiness.used_halls_count') => $readiness['used_halls_count'] ?? 0,
                    __('exam.readiness.halls_needing_invigilators_count') => $readiness['halls_needing_invigilators_count'] ?? 0,
                    __('exam.readiness.assigned_students_count') => $readiness['assigned_students_count'] ?? 0,
                    __('exam.readiness.unassigned_students_count') => $readiness['unassigned_students_count'] ?? 0,
                    __('exam.readiness.incomplete_slots_count') => $readiness['incomplete_slots_count'] ?? 0,
                ] as $label => $value)
                    <div class="rounded-md bg-white/70 p-3 dark:bg-black/10">
                        <div class="text-xs text-gray-600 dark:text-gray-300">{{ $label }}</div>
                        <div class="mt-1 text-xl font-semibold text-gray-950 dark:text-white">{{ $value }}</div>
                    </div>
                @endforeach
            </div>

            @if (! empty($readiness['incomplete_slots'] ?? []))
                <div class="mt-4 rounded-md border border-danger-200 bg-white/70 p-3 text-sm text-danger-900 dark:border-danger-500/20 dark:bg-black/10 dark:text-danger-200">
                    <div class="font-semibold">{{ __('exam.readiness.incomplete_slots_title') }}</div>
                    <div class="mt-2 space-y-1">
                        @foreach (array_slice($readiness['incomplete_slots'], 0, 6) as $slot)
                            <div>
                                {{ $slot['exam_date'] }} · {{ substr((string) $slot['start_time'], 0, 5) }}
                                - {{ __('exam.fields.unassigned_students') }}: {{ $slot['unassigned_students_count'] }}
                                @if (! empty($slot['incomplete_offerings'] ?? []))
                                    <span class="text-danger-700 dark:text-danger-200">
                                        ({{ collect($slot['incomplete_offerings'])->pluck('subject_name')->filter()->implode('، ') }})
                                    </span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-2">{{ __('exam.readiness.fix_helper') }}</div>
                </div>
            @endif

            @if ($readiness['has_non_blocking_warnings'] ?? false)
                <div class="mt-4 rounded-md border border-warning-300 bg-warning-50 p-3 text-sm leading-6 text-warning-900 dark:border-warning-500/30 dark:bg-warning-500/10 dark:text-warning-100">
                    <div class="font-semibold">{{ __('exam.global_hall_distribution.success_with_warnings_title') }}</div>
                    <div class="mt-1">{{ $readiness['warning_message'] ?? __('exam.global_hall_distribution.success_with_warnings_body') }}</div>
                </div>
            @endif
        </div>

        <div class="rounded-lg border border-warning-200 bg-warning-50 p-4 text-warning-900 shadow-sm dark:border-warning-500/20 dark:bg-warning-500/10 dark:text-warning-200">
            <div class="flex gap-3">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="mt-0.5 h-6 w-6 shrink-0" />
                <div class="space-y-3">
                    <div>
                        <h2 class="text-base font-semibold">{{ __('exam.warnings.before_invigilator_distribution_title') }}</h2>
                        <p class="mt-1 text-sm leading-6">{{ __('exam.warnings.before_invigilator_distribution_message') }}</p>
                    </div>
                    @if ($this->hasManualAssignments())
                        <div class="rounded-md border border-warning-300 bg-white/60 p-3 text-sm dark:border-warning-500/30 dark:bg-black/10">
                            {{ __('exam.warnings.manual_assignments_preserved') }}
                        </div>
                    @endif
                    <label class="flex items-start gap-2 text-sm font-medium">
                        <input type="checkbox" wire:model.live="readiness_confirmed" class="mt-1 rounded border-warning-400 text-primary-600">
                        <span>{{ __('exam.fields.invigilator_readiness_confirmation') }}</span>
                    </label>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap gap-2">
            <x-filament::button tag="a" :href="$this->reportsDashboardUrl()" color="gray" icon="heroicon-o-printer">
                عرض التقارير والطباعة
            </x-filament::button>
        </div>

        @if (! empty($disabledReasons) && ! $this->canRunDistribution())
            <div class="rounded-lg border border-gray-200 bg-white p-4 text-sm text-gray-700 shadow-sm dark:border-white/10 dark:bg-gray-900 dark:text-gray-200">
                <div class="mb-2 font-semibold text-gray-950 dark:text-white">{{ __('exam.readiness.disabled_reasons_title') }}</div>
                <div class="flex flex-wrap gap-2">
                    @foreach ($disabledReasons as $reason)
                        <span class="rounded-full bg-gray-100 px-3 py-1 dark:bg-white/10">{{ $reason }}</span>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($cards as $label => $value)
                <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                    <div class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</div>
                    <div class="mt-2 text-2xl font-semibold text-gray-950 dark:text-white">{{ $value }}</div>
                </div>
            @endforeach
        </div>

        @php($fairDrafts = $this->getFairBalancedDraftsData())
        <div class="rounded-lg border border-info-200 bg-white p-4 shadow-sm dark:border-info-500/20 dark:bg-gray-900">
            <div class="mb-3">
                <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('exam.fair_draft.saved_drafts') }}</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('exam.fair_draft.saved_drafts_hint') }}</p>
            </div>
            <div class="space-y-3">
                @forelse ($fairDrafts as $draft)
                    @php($draftSummary = $draft['summary'] ?? [])
                    <div class="rounded-md border border-gray-200 p-3 dark:border-white/10">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div class="font-semibold text-gray-950 dark:text-white">
                                    {{ __('exam.fair_draft.fields.draft_number') }} #{{ $draft['id'] }}
                                    <span class="rounded-full bg-info-50 px-2 py-1 text-xs text-info-700 dark:bg-info-500/10 dark:text-info-200">{{ $draft['status_label'] }}</span>
                                </div>
                                <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                    {{ $draft['created_at'] }} · {{ $draft['period'] }} · {{ $draft['created_by'] }}
                                </div>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <x-filament::button size="sm" color="gray" icon="heroicon-o-document-arrow-down" wire:click="exportFairBalancedDraftPdf({{ $draft['id'] }})">
                                    {{ __('exam.fair_draft.actions.download_pdf') }}
                                </x-filament::button>
                                <x-filament::button size="sm" color="gray" icon="heroicon-o-arrow-down-tray" wire:click="exportFairBalancedDraftExcel({{ $draft['id'] }})">
                                    {{ __('exam.fair_draft.actions.download_excel') }}
                                </x-filament::button>
                                @if ($draft['status'] === 'draft')
                                    <x-filament::button size="sm" color="success" icon="heroicon-o-check" wire:click="approveFairBalancedDraft({{ $draft['id'] }})" wire:confirm="{{ __('exam.fair_draft.confirmations.approve') }}">
                                        {{ __('exam.fair_draft.actions.approve') }}
                                    </x-filament::button>
                                    <x-filament::button size="sm" color="danger" icon="heroicon-o-x-mark" wire:click="cancelFairBalancedDraft({{ $draft['id'] }})" wire:confirm="{{ __('exam.fair_draft.confirmations.cancel') }}">
                                        {{ __('exam.fair_draft.actions.cancel') }}
                                    </x-filament::button>
                                @endif
                            </div>
                        </div>
                        <div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-6">
                            @foreach ([
                                __('exam.fair_draft.fields.min_duties') => $draftSummary['min_duties'] ?? 0,
                                __('exam.fair_draft.fields.max_duties') => $draftSummary['max_duties'] ?? 0,
                                __('exam.fair_draft.fields.average_duties') => $draftSummary['average_duties'] ?? 0,
                                __('exam.fair_draft.fields.changed_observers') => $draftSummary['changed_observers_count'] ?? 0,
                                __('exam.fair_draft.fields.relaxed_constraints_count') => $draftSummary['relaxed_constraints_count'] ?? 0,
                                __('exam.fields.missing_assignments_count') => $draftSummary['uncovered_duties'] ?? 0,
                            ] as $label => $value)
                                <div class="rounded-md bg-gray-50 p-2 dark:bg-white/5">
                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</div>
                                    <div class="mt-1 font-semibold text-gray-950 dark:text-white">{{ $value }}</div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <div class="text-sm text-gray-500">{{ __('exam.fair_draft.no_drafts') }}</div>
                @endforelse
            </div>
        </div>

        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('exam.reports.shortage_summary_by_role') }}</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('exam.reports.shortage_metrics_hint') }}</p>
            <div class="mt-3 overflow-x-auto">
                <table class="w-full min-w-[720px] text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-gray-700 dark:border-white/10 dark:text-gray-200">
                            <th class="px-3 py-2 text-right">{{ __('exam.fields.invigilation_role') }}</th>
                            <th class="px-3 py-2 text-right">{{ __('exam.fields.required_count') }}</th>
                            <th class="px-3 py-2 text-right">{{ __('exam.fields.assigned_count') }}</th>
                            <th class="px-3 py-2 text-right">{{ __('exam.fields.missing_assignments_count') }}</th>
                            <th class="px-3 py-2 text-right">{{ __('exam.fields.recommended_additional_observers_count') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach (($summary['shortage_by_role'] ?? []) as $roleShortage)
                            <tr class="border-b border-gray-100 last:border-0 dark:border-white/5 {{ ($roleShortage['shortage_count'] ?? 0) > 0 ? 'text-danger-700 dark:text-danger-300' : '' }}">
                                <td class="px-3 py-2">{{ $roleShortage['role_label'] }}</td>
                                <td class="px-3 py-2">{{ $roleShortage['required_count'] ?? 0 }}</td>
                                <td class="px-3 py-2">{{ $roleShortage['assigned_count'] ?? 0 }}</td>
                                <td class="px-3 py-2 font-semibold">{{ $roleShortage['shortage_count'] ?? 0 }}</td>
                                <td class="px-3 py-2 font-semibold">{{ $roleShortage['recommended_additional_observers_count'] ?? 0 }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('exam.sections.problem_diagnosis') }}</h2>
            <div class="mt-3 space-y-2">
                @forelse ($summary['diagnosis'] ?? [] as $item)
                    <div class="rounded-md border p-3 text-sm {{ ($item['tone'] ?? 'gray') === 'danger' ? 'border-danger-200 bg-danger-50 text-danger-700 dark:border-danger-500/20 dark:bg-danger-500/10 dark:text-danger-300' : (($item['tone'] ?? 'gray') === 'success' ? 'border-success-200 bg-success-50 text-success-700 dark:border-success-500/20 dark:bg-success-500/10 dark:text-success-300' : 'border-gray-200 bg-gray-50 text-gray-700 dark:border-white/10 dark:bg-white/5 dark:text-gray-200') }}">
                        {{ $item['message'] }}
                    </div>
                @empty
                    <div class="text-sm text-gray-500">{{ __('exam.diagnosis.no_hall_distribution_results') }}</div>
                @endforelse
            </div>
        </div>

        @php($dutyIncreaseReport = $summary['duty_increase_recommendations'] ?? [])
        @if ((int) ($dutyIncreaseReport['total_uncovered_duties'] ?? 0) > 0)
            <div class="rounded-lg border border-primary-200 bg-white p-4 shadow-sm dark:border-primary-500/20 dark:bg-gray-900">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('exam.reports.observer_duty_increase_recommendation_report') }}</h2>
                    <x-filament::button size="sm" color="primary" icon="heroicon-o-document-arrow-down" wire:click="exportDutyIncreaseRecommendationsPdf">
                        {{ __('exam.actions.export_invigilator_duty_increase_recommendations_pdf') }}
                    </x-filament::button>
                </div>
                <div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                    @foreach ([
                        __('exam.fields.missing_assignments_count') => $dutyIncreaseReport['total_uncovered_duties'] ?? 0,
                        __('exam.reports.duties_coverable_by_current_observer_limit_increase') => $dutyIncreaseReport['coverable_by_limit_increase'] ?? 0,
                        __('exam.reports.duties_requiring_new_observers') => $dutyIncreaseReport['requires_new_observers'] ?? 0,
                        __('exam.reports.recommended_observers_to_increase') => $dutyIncreaseReport['recommended_observers_count'] ?? 0,
                        __('exam.reports.max_suggested_increase_per_observer') => $dutyIncreaseReport['max_suggested_increase_per_observer'] ?? 0,
                    ] as $label => $value)
                        <div class="rounded-md border border-gray-200 p-3 dark:border-white/10">
                            <div class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</div>
                            <div class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $value }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @php($shortagePagination = $this->getPaginatedShortagesData())
        @if ((int) ($shortagePagination['total'] ?? 0) > 0)
            <div class="rounded-lg border border-warning-200 bg-warning-50 p-4 shadow-sm dark:border-warning-500/20 dark:bg-warning-500/10">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h2 class="text-base font-semibold text-warning-900 dark:text-warning-200">{{ __('exam.reports.invigilator_shortage_report_title') }}</h2>
                        <p class="mt-1 text-sm text-warning-800 dark:text-warning-100">
                            {{ __('exam.pagination.showing_rows', ['from' => $shortagePagination['from'] ?? 0, 'to' => $shortagePagination['to'] ?? 0, 'total' => $shortagePagination['total'] ?? 0]) }}
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <label class="flex items-center gap-2 text-sm text-warning-900 dark:text-warning-100">
                            <span>{{ __('exam.pagination.rows_per_page') }}</span>
                            <select wire:model.live="shortage_per_page" class="rounded-md border-warning-300 bg-white text-sm text-gray-900 dark:border-warning-500/30 dark:bg-gray-900 dark:text-white">
                                @foreach (($shortagePagination['per_page_options'] ?? [10, 25, 50, 100]) as $pageSize)
                                    <option value="{{ $pageSize }}">{{ __('exam.pagination.show_rows', ['count' => $pageSize]) }}</option>
                                @endforeach
                            </select>
                        </label>
                        <x-filament::button size="sm" color="warning" icon="heroicon-o-document-arrow-down" wire:click="exportShortagePdf">
                            {{ __('exam.actions.export_invigilator_shortage_pdf') }}
                        </x-filament::button>
                    </div>
                </div>

                @if (! empty($summary['shortage_by_slot'] ?? []))
                    <div class="mb-3 grid gap-2 md:grid-cols-2 xl:grid-cols-3">
                        @foreach (array_slice($summary['shortage_by_slot'], 0, 6) as $slotShortage)
                            <div class="rounded-md border border-warning-200 bg-white/70 p-3 text-sm dark:border-warning-500/20 dark:bg-black/10">
                                <div class="font-medium text-warning-950 dark:text-warning-100">
                                    {{ $slotShortage['exam_date'] }} · {{ $slotShortage['start_time'] }}
                                </div>
                                <div class="mt-1 text-warning-800 dark:text-warning-100">
                                    {{ __('exam.fields.missing_assignments_count') }}: {{ $slotShortage['shortage_count'] }}
                                </div>
                            </div>
                        @endforeach
                        @if (count($summary['shortage_by_slot']) > 6)
                            <div class="rounded-md border border-warning-200 bg-white/70 p-3 text-sm font-medium text-warning-900 dark:border-warning-500/20 dark:bg-black/10 dark:text-warning-100">
                                {{ __('exam.pagination.more_items', ['count' => count($summary['shortage_by_slot']) - 6]) }}
                            </div>
                        @endif
                    </div>
                @endif

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[980px] text-sm">
                        <thead>
                            <tr class="border-b border-warning-200 text-warning-900 dark:border-warning-500/20 dark:text-warning-200">
                                <th class="px-3 py-2 text-right">{{ __('exam.fields.exam_date') }}</th>
                                <th class="px-3 py-2 text-right">{{ __('exam.fields.exam_start_time') }}</th>
                                <th class="px-3 py-2 text-right">{{ __('exam.fields.college') }}</th>
                                <th class="px-3 py-2 text-right">{{ __('exam.fields.hall_name') }}</th>
                                <th class="px-3 py-2 text-right">{{ __('exam.fields.invigilation_role') }}</th>
                                <th class="px-3 py-2 text-right">{{ __('exam.fields.required_count') }}</th>
                                <th class="px-3 py-2 text-right">{{ __('exam.fields.assigned_count') }}</th>
                                <th class="px-3 py-2 text-right">{{ __('exam.fields.missing_assignments_count') }}</th>
                                <th class="px-3 py-2 text-right">{{ __('exam.fields.reason') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach (($shortagePagination['data'] ?? []) as $shortage)
                                <tr class="border-b border-warning-100 last:border-0 dark:border-warning-500/10">
                                    <td class="px-3 py-2">{{ $shortage['exam_date'] }}</td>
                                    <td class="px-3 py-2">{{ $shortage['start_time'] }}</td>
                                    <td class="px-3 py-2">{{ $shortage['college_name'] ?? ($summary['college']->name ?? '-') }}</td>
                                    <td class="px-3 py-2">{{ $shortage['hall_name'] }}</td>
                                    <td class="px-3 py-2">{{ $shortage['invigilation_role'] }}</td>
                                    <td class="px-3 py-2">{{ $shortage['required_count'] }}</td>
                                    <td class="px-3 py-2">{{ $shortage['assigned_count'] }}</td>
                                    <td class="px-3 py-2 font-semibold">{{ $shortage['shortage_count'] }}</td>
                                    <td class="px-3 py-2">{{ $shortage['reason'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 flex flex-wrap items-center justify-between gap-2 text-sm text-warning-900 dark:text-warning-100">
                    <div>
                        {{ __('exam.pagination.current_page', ['page' => $shortagePagination['current_page'] ?? 1, 'last' => $shortagePagination['last_page'] ?? 1]) }}
                    </div>
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            wire:click="previousShortagePage"
                            @disabled(($shortagePagination['current_page'] ?? 1) <= 1)
                            class="rounded-md border border-warning-300 bg-white px-3 py-1.5 font-medium disabled:cursor-not-allowed disabled:opacity-50 dark:border-warning-500/30 dark:bg-gray-900"
                        >
                            {{ __('exam.pagination.previous') }}
                        </button>
                        <button
                            type="button"
                            wire:click="nextShortagePage"
                            @disabled(($shortagePagination['current_page'] ?? 1) >= ($shortagePagination['last_page'] ?? 1))
                            class="rounded-md border border-warning-300 bg-white px-3 py-1.5 font-medium disabled:cursor-not-allowed disabled:opacity-50 dark:border-warning-500/30 dark:bg-gray-900"
                        >
                            {{ __('exam.pagination.next') }}
                        </button>
                    </div>
                </div>
            </div>
        @endif

    </div>
</x-filament-panels::page>
