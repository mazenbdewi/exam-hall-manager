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
            __('exam.fields.shortage_count') => $summary['shortage_count'] ?? 0,
            __('exam.fields.days_count') => $summary['days_count'] ?? 0,
            __('exam.fields.slots_count') => $summary['slots_count'] ?? 0,
        ];
        $tabClasses = fn (string $tab): string => $active_tab === $tab
            ? 'border-primary-500 bg-primary-50 text-primary-700 dark:border-primary-400 dark:bg-primary-500/10 dark:text-primary-300'
            : 'border-gray-200 bg-white text-gray-600 hover:bg-gray-50 dark:border-white/10 dark:bg-gray-900 dark:text-gray-300 dark:hover:bg-white/5';
    @endphp

    <div dir="rtl" class="space-y-5 text-right">
        <div class="rounded-lg border border-primary-200 bg-primary-50 p-3 text-sm font-semibold text-primary-700 shadow-sm dark:border-primary-500/20 dark:bg-primary-500/10 dark:text-primary-300">
            صفحة توزيع المراقبين تم تحميلها
        </div>

        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div class="mb-4 grid gap-2 text-sm text-gray-600 dark:text-gray-300 md:grid-cols-5">
                <div><span class="font-semibold text-gray-950 dark:text-white">{{ __('exam.workflow.step_1') }}</span></div>
                <div><span class="font-semibold text-gray-950 dark:text-white">{{ __('exam.workflow.step_2') }}</span></div>
                <div><span class="font-semibold text-gray-950 dark:text-white">{{ __('exam.workflow.step_3') }}</span></div>
                <div><span class="font-semibold text-gray-950 dark:text-white">{{ __('exam.workflow.step_4') }}</span></div>
                <div><span class="font-semibold text-gray-950 dark:text-white">{{ __('exam.workflow.step_5') }}</span></div>
            </div>

            <div class="grid gap-3 md:grid-cols-3">
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
            @if ($this->hasExistingDistribution())
                <x-filament::button
                    icon="heroicon-o-arrow-path"
                    wire:click="runDistribution"
                    wire:confirm="{{ __('exam.confirmations.rerun_invigilator_distribution') }}"
                    wire:loading.attr="disabled"
                    :disabled="! $this->canRunDistribution()"
                >
                    {{ $this->distributionButtonLabel() }}
                </x-filament::button>
            @else
                <x-filament::button
                    icon="heroicon-o-play"
                    wire:click="runDistribution"
                    wire:loading.attr="disabled"
                    :disabled="! $this->canRunDistribution()"
                >
                    {{ $this->distributionButtonLabel() }}
                </x-filament::button>
            @endif

            @if (($summary['has_assignments'] ?? false) || ($summary['shortage_count'] ?? 0) > 0)
                <x-filament::button color="gray" icon="heroicon-o-user" wire:click="exportPdfByInvigilator">
                    {{ __('exam.actions.export_invigilator_pdf_by_invigilator') }}
                </x-filament::button>
                <x-filament::button color="gray" icon="heroicon-o-building-office-2" wire:click="exportPdfByHall">
                    {{ __('exam.actions.export_invigilator_pdf_by_hall') }}
                </x-filament::button>
                <x-filament::button color="gray" icon="heroicon-o-calendar-days" wire:click="exportPdfByDay">
                    {{ __('exam.actions.export_invigilator_pdf_by_day') }}
                </x-filament::button>
                <x-filament::button color="warning" icon="heroicon-o-exclamation-triangle" wire:click="exportShortagePdf">
                    {{ __('exam.actions.export_invigilator_shortage_pdf') }}
                </x-filament::button>
            @endif
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

        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('exam.reports.shortage_summary_by_role') }}</h2>
            <div class="mt-3 overflow-x-auto">
                <table class="w-full min-w-[560px] text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-gray-700 dark:border-white/10 dark:text-gray-200">
                            <th class="px-3 py-2 text-right">{{ __('exam.fields.invigilation_role') }}</th>
                            <th class="px-3 py-2 text-right">{{ __('exam.fields.required_count') }}</th>
                            <th class="px-3 py-2 text-right">{{ __('exam.fields.assigned_count') }}</th>
                            <th class="px-3 py-2 text-right">{{ __('exam.fields.shortage_count') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach (($summary['shortage_by_role'] ?? []) as $roleShortage)
                            <tr class="border-b border-gray-100 last:border-0 dark:border-white/5 {{ ($roleShortage['shortage_count'] ?? 0) > 0 ? 'text-danger-700 dark:text-danger-300' : '' }}">
                                <td class="px-3 py-2">{{ $roleShortage['role_label'] }}</td>
                                <td class="px-3 py-2">{{ $roleShortage['required_count'] ?? 0 }}</td>
                                <td class="px-3 py-2">{{ $roleShortage['assigned_count'] ?? 0 }}</td>
                                <td class="px-3 py-2 font-semibold">{{ $roleShortage['shortage_count'] ?? 0 }}</td>
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

        @if (! empty($summary['shortages'] ?? []))
            <div class="rounded-lg border border-warning-200 bg-warning-50 p-4 shadow-sm dark:border-warning-500/20 dark:bg-warning-500/10">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-base font-semibold text-warning-900 dark:text-warning-200">{{ __('exam.sections.invigilator_shortage') }}</h2>
                    <x-filament::button size="sm" color="warning" icon="heroicon-o-document-arrow-down" wire:click="exportShortagePdf">
                        {{ __('exam.actions.export_invigilator_shortage_pdf') }}
                    </x-filament::button>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[760px] text-sm">
                        <thead>
                            <tr class="border-b border-warning-200 text-warning-900 dark:border-warning-500/20 dark:text-warning-200">
                                <th class="px-3 py-2 text-right">{{ __('exam.fields.exam_date') }}</th>
                                <th class="px-3 py-2 text-right">{{ __('exam.fields.exam_start_time') }}</th>
                                <th class="px-3 py-2 text-right">{{ __('exam.fields.hall_name') }}</th>
                                <th class="px-3 py-2 text-right">{{ __('exam.fields.hall_type') }}</th>
                                <th class="px-3 py-2 text-right">{{ __('exam.fields.invigilation_role') }}</th>
                                <th class="px-3 py-2 text-right">{{ __('exam.fields.required_count') }}</th>
                                <th class="px-3 py-2 text-right">{{ __('exam.fields.assigned_count') }}</th>
                                <th class="px-3 py-2 text-right">{{ __('exam.fields.shortage_count') }}</th>
                                <th class="px-3 py-2 text-right">{{ __('exam.fields.reason') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($summary['shortages'] as $shortage)
                                <tr class="border-b border-warning-100 last:border-0 dark:border-warning-500/10">
                                    <td class="px-3 py-2">{{ $shortage['exam_date'] }}</td>
                                    <td class="px-3 py-2">{{ $shortage['start_time'] }}</td>
                                    <td class="px-3 py-2">{{ $shortage['hall_name'] }}</td>
                                    <td class="px-3 py-2">{{ $shortage['hall_type_label'] ?? '-' }}</td>
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
            </div>
        @endif

        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div class="mb-4 flex flex-wrap gap-2">
                <button type="button" wire:click="$set('active_tab', 'day')" class="rounded-md border px-3 py-2 text-sm font-medium {{ $tabClasses('day') }}">{{ __('exam.tabs.by_day') }}</button>
                <button type="button" wire:click="$set('active_tab', 'hall')" class="rounded-md border px-3 py-2 text-sm font-medium {{ $tabClasses('hall') }}">{{ __('exam.tabs.by_hall') }}</button>
                <button type="button" wire:click="$set('active_tab', 'invigilator')" class="rounded-md border px-3 py-2 text-sm font-medium {{ $tabClasses('invigilator') }}">{{ __('exam.tabs.by_invigilator') }}</button>
            </div>

            @if ($active_tab === 'invigilator')
                <div class="grid gap-3 lg:grid-cols-2">
                    @forelse ($summary['by_invigilator'] ?? [] as $invigilator)
                        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div>
                                    <div class="font-semibold text-gray-950 dark:text-white">{{ $invigilator['name'] }}</div>
                                    <div class="text-sm text-gray-500">
                                        {{ $invigilator['staff_category'] }} · {{ $invigilator['invigilation_role'] }}
                                        · {{ __('exam.fields.workload_reduction_percentage_short') }}: {{ $invigilator['workload_reduction_percentage'] ?? 0 }}%
                                    </div>
                                </div>
                                <span class="rounded-full bg-gray-100 px-2 py-1 text-xs text-gray-700 dark:bg-white/10 dark:text-gray-200">{{ $invigilator['assignments_count'] }}</span>
                            </div>
                            <div class="mt-3 space-y-2 text-sm">
                                @foreach ($invigilator['assignments'] as $assignment)
                                    <div class="rounded-md bg-gray-50 p-2 dark:bg-white/5">
                                        {{ $assignment['exam_date'] }} · {{ substr((string) $assignment['start_time'], 0, 5) }}
                                        <span class="text-gray-500">|</span>
                                        {{ $assignment['hall_name'] }} - {{ $assignment['hall_location'] }}
                                        <span class="text-gray-500">|</span>
                                        {{ $assignment['role_label'] }}
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @empty
                        <div class="text-sm text-gray-500">{{ __('exam.helpers.no_invigilator_assignments') }}</div>
                    @endforelse
                </div>
            @elseif ($active_tab === 'hall')
                <div class="space-y-4">
                    @forelse ($summary['slots'] ?? [] as $slot)
                        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                            <h3 class="font-semibold text-gray-950 dark:text-white">{{ $slot['exam_date'] }} · {{ substr((string) $slot['start_time'], 0, 5) }}</h3>
                            <div class="mt-3 grid gap-3 lg:grid-cols-2">
                                @foreach ($slot['halls'] as $hall)
                                    @include('filament.pages.partials.invigilator-hall-card', ['hall' => $hall])
                                @endforeach
                            </div>
                        </div>
                    @empty
                        <div class="text-sm text-gray-500">{{ __('exam.helpers.no_invigilator_assignments') }}</div>
                    @endforelse
                </div>
            @else
                <div class="space-y-4">
                    @forelse ($summary['by_day'] ?? [] as $day)
                        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <h3 class="font-semibold text-gray-950 dark:text-white">{{ $day['exam_date'] }}</h3>
                                <div class="text-sm text-gray-500">
                                    {{ __('exam.fields.slots_count') }}: {{ $day['slots_count'] }}
                                    · {{ __('exam.fields.used_halls') }}: {{ $day['halls_count'] }}
                                    · {{ __('exam.fields.required_count') }}: {{ $day['required_count'] }}
                                    · {{ __('exam.fields.assigned_count') }}: {{ $day['assigned_count'] }}
                                    · {{ __('exam.fields.shortage_count') }}: {{ $day['shortage_count'] }}
                                </div>
                            </div>
                            <div class="mt-3 space-y-3">
                                @foreach ($day['slots'] as $slot)
                                    <div class="rounded-md bg-gray-50 p-3 dark:bg-white/5">
                                        <div class="mb-2 font-medium text-gray-900 dark:text-white">{{ substr((string) $slot['start_time'], 0, 5) }}</div>
                                        <div class="grid gap-3 lg:grid-cols-2">
                                            @foreach ($slot['halls'] as $hall)
                                                @include('filament.pages.partials.invigilator-hall-card', ['hall' => $hall])
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @empty
                        <div class="text-sm text-gray-500">{{ __('exam.helpers.no_invigilator_assignments') }}</div>
                    @endforelse
                </div>
            @endif
        </div>
    </div>
</x-filament-panels::page>
