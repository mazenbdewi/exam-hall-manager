<x-filament-panels::page>
    @php
        $readiness = $this->getReadinessData();
        $disabledReasons = $this->distributionDisabledReasons();
    @endphp

    <div dir="rtl" class="space-y-5 text-right">
        <div class="rounded-lg border border-info-200 bg-info-50 p-4 text-info-900 shadow-sm dark:border-info-500/20 dark:bg-info-500/10 dark:text-info-100">
            <div class="flex gap-3">
                <x-filament::icon icon="heroicon-o-information-circle" class="mt-0.5 h-6 w-6 shrink-0" />
                <p class="text-sm leading-6">
                    يجب تنفيذ توزيع الطلاب على القاعات الامتحانية أولًا، لأن توزيع المراقبين يعتمد على القاعات المستخدمة فعليًا بعد توزيع الطلاب.
                </p>
            </div>
        </div>

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
                            {{ $this->selectedCollegeName() }}
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
                    :disabled="! $this->canRunDistribution()"
                >
                    {{ __('exam.fair_draft.actions.fair_distribution') }}
                </x-filament::button>
            </div>
        </div>

        <div class="rounded-lg border p-4 shadow-sm {{ ($readiness['is_ready'] ?? false) ? 'border-success-200 bg-success-50 dark:border-success-500/20 dark:bg-success-500/10' : 'border-danger-200 bg-danger-50 dark:border-danger-500/20 dark:bg-danger-500/10' }}">
            <div class="flex items-start gap-3">
                <x-filament::icon :icon="($readiness['is_ready'] ?? false) ? 'heroicon-o-check-circle' : 'heroicon-o-exclamation-circle'" class="mt-0.5 h-6 w-6 shrink-0 {{ ($readiness['is_ready'] ?? false) ? 'text-success-600 dark:text-success-300' : 'text-danger-600 dark:text-danger-300' }}" />
                <div>
                    <h2 class="text-base font-semibold {{ ($readiness['is_ready'] ?? false) ? 'text-success-900 dark:text-success-200' : 'text-danger-900 dark:text-danger-200' }}">
                        {{ __('exam.readiness.title') }}
                    </h2>
                    <p class="mt-1 text-sm leading-6 {{ ($readiness['is_ready'] ?? false) ? 'text-success-800 dark:text-success-200' : 'text-danger-800 dark:text-danger-200' }}">
                        {{ ($readiness['is_ready'] ?? false) ? __('exam.readiness.ready_message') : ($readiness['blocking_message'] ?? __('exam.readiness.not_ready_message')) }}
                    </p>
                </div>
            </div>

            @if ($readiness['has_non_blocking_warnings'] ?? false)
                <div class="mt-4 rounded-md border border-warning-300 bg-warning-50 p-3 text-sm leading-6 text-warning-900 dark:border-warning-500/30 dark:bg-warning-500/10 dark:text-warning-100">
                    <div class="font-semibold">{{ __('exam.global_hall_distribution.success_with_warnings_title') }}</div>
                    <div class="mt-1">{{ $readiness['warning_message'] ?? __('exam.global_hall_distribution.success_with_warnings_body') }}</div>
                </div>
            @endif
        </div>

        @if ($this->hasManualAssignments())
            <div class="rounded-lg border border-warning-200 bg-warning-50 p-4 text-sm leading-6 text-warning-900 shadow-sm dark:border-warning-500/20 dark:bg-warning-500/10 dark:text-warning-200">
                {{ __('exam.warnings.manual_assignments_preserved') }}
            </div>
        @endif

        <div class="flex flex-wrap gap-2">
            <x-filament::button tag="a" :href="$this->reportsDashboardUrl()" color="gray" icon="heroicon-o-printer">
                عرض التقارير والطباعة
            </x-filament::button>
            <x-filament::button color="gray" icon="heroicon-o-document-arrow-down" wire:click="exportDutyIncreaseRecommendationsPdf">
                {{ __('exam.actions.export_invigilator_duty_increase_recommendations_pdf') }}
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
    </div>
</x-filament-panels::page>
