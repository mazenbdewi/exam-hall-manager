<x-filament-panels::page>
    @php
        $readiness = $this->getReadinessData();
        $disabledReasons = $this->distributionDisabledReasons();
        $normalResult = $this->lastNormalDistributionResult;
        $fairResult = $this->lastFairDistributionResult;
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

        @if ($normalResult)
            @php
                $normalTone = match ($normalResult['status_type'] ?? 'failed') {
                    'success' => 'border-success-200 bg-success-50 text-success-900 dark:border-success-500/20 dark:bg-success-500/10 dark:text-success-100',
                    'partial' => 'border-warning-200 bg-warning-50 text-warning-900 dark:border-warning-500/20 dark:bg-warning-500/10 dark:text-warning-100',
                    default => 'border-danger-200 bg-danger-50 text-danger-900 dark:border-danger-500/20 dark:bg-danger-500/10 dark:text-danger-100',
                };
                $normalIcon = match ($normalResult['status_type'] ?? 'failed') {
                    'success' => 'heroicon-o-check-circle',
                    'partial' => 'heroicon-o-exclamation-triangle',
                    default => 'heroicon-o-x-circle',
                };
            @endphp

            <section class="rounded-lg border p-4 shadow-sm {{ $normalTone }}">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div class="flex gap-3">
                        <x-filament::icon :icon="$normalIcon" class="mt-0.5 h-6 w-6 shrink-0" />
                        <div>
                            <h2 class="text-base font-semibold">{{ $normalResult['title'] ?? 'نتيجة توزيع المراقبين' }}</h2>
                            <div class="mt-1 text-sm font-semibold">{{ $normalResult['status_label'] ?? 'فشل التوزيع' }}</div>
                            <p class="mt-2 max-w-4xl text-sm leading-6">{{ $normalResult['explanation'] ?? '' }}</p>
                            <div class="mt-2 text-xs opacity-80">
                                {{ __('exam.fields.period') }}: {{ $normalResult['period'] ?? '—' }}
                                @if (! empty($normalResult['executed_at']))
                                    <span class="mx-1">|</span>
                                    {{ __('exam.fields.executed_at') }}: {{ $normalResult['executed_at'] }}
                                @endif
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        @if (($normalResult['uncovered_count'] ?? 0) > 0)
                            <x-filament::button size="sm" color="warning" icon="heroicon-o-document-arrow-down" wire:click="exportShortagePdf">
                                تحميل تقرير المهام غير المغطاة PDF
                            </x-filament::button>
                        @endif
                        <x-filament::button size="sm" tag="a" :href="$this->reportsDashboardUrl()" color="gray" icon="heroicon-o-printer">
                            عرض صفحة التقارير
                        </x-filament::button>
                        <x-filament::button size="sm" color="gray" icon="heroicon-o-arrow-path" wire:click="runDistribution" wire:loading.attr="disabled" :disabled="! $this->canRunDistribution()">
                            إعادة التوزيع
                        </x-filament::button>
                    </div>
                </div>

                <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-md bg-white/70 p-3 dark:bg-white/10">
                        <div class="text-xs opacity-75">إجمالي المهام المطلوبة</div>
                        <div class="mt-1 text-lg font-semibold">{{ number_format((int) ($normalResult['total_required'] ?? 0)) }}</div>
                    </div>
                    <div class="rounded-md bg-white/70 p-3 dark:bg-white/10">
                        <div class="text-xs opacity-75">المهام التي تم إسنادها</div>
                        <div class="mt-1 text-lg font-semibold">{{ number_format((int) ($normalResult['assigned_count'] ?? 0)) }}</div>
                    </div>
                    <div class="rounded-md bg-white/70 p-3 dark:bg-white/10">
                        <div class="text-xs opacity-75">المهام غير المغطاة</div>
                        <div class="mt-1 text-lg font-semibold">{{ number_format((int) ($normalResult['uncovered_count'] ?? 0)) }}</div>
                    </div>
                    <div class="rounded-md bg-white/70 p-3 dark:bg-white/10">
                        <div class="text-xs opacity-75">نسبة التغطية</div>
                        <div class="mt-1 text-lg font-semibold">{{ rtrim(rtrim(number_format((float) ($normalResult['coverage_percentage'] ?? 0), 2), '0'), '.') }}%</div>
                    </div>
                </div>
            </section>
        @endif

        @if ($fairResult)
            @php
                $fairTone = ($fairResult['status_type'] ?? 'failed') === 'success'
                    ? 'border-info-200 bg-info-50 text-info-900 dark:border-info-500/20 dark:bg-info-500/10 dark:text-info-100'
                    : 'border-danger-200 bg-danger-50 text-danger-900 dark:border-danger-500/20 dark:bg-danger-500/10 dark:text-danger-100';
            @endphp

            <section class="rounded-lg border p-4 shadow-sm {{ $fairTone }}">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div class="flex gap-3">
                        <x-filament::icon icon="heroicon-o-scale" class="mt-0.5 h-6 w-6 shrink-0" />
                        <div>
                            <h2 class="text-base font-semibold">{{ $fairResult['title'] ?? 'نتيجة مسودة التوزيع العادل' }}</h2>
                            <div class="mt-1 text-sm font-semibold">{{ $fairResult['status_label'] ?? 'فشل إنشاء مسودة التوزيع العادل' }}</div>
                            <div class="mt-2 text-sm leading-6">
                                {{ __('exam.fair_draft.fields.draft_number') }} #{{ $fairResult['draft_id'] ?? '—' }}
                                <span class="mx-1">|</span>
                                {{ __('exam.fields.status') }}: {{ $fairResult['draft_status_label'] ?? '—' }}
                                <span class="mx-1">|</span>
                                {{ __('exam.fields.period') }}: {{ $fairResult['period'] ?? '—' }}
                            </div>
                            <div class="mt-2 text-xs opacity-80">
                                {{ __('exam.fields.created_at') }}: {{ $fairResult['created_at'] ?? '—' }}
                                <span class="mx-1">|</span>
                                {{ __('exam.fields.executed_by') }}: {{ $fairResult['created_by'] ?? '—' }}
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        @if (! empty($fairResult['draft_id']))
                            <x-filament::button size="sm" color="info" icon="heroicon-o-document-arrow-down" wire:click="exportFairBalancedDraftPdf({{ (int) $fairResult['draft_id'] }})">
                                تحميل تقرير مسودة التوزيع العادل PDF
                            </x-filament::button>
                        @endif
                        @if (($fairResult['draft_status'] ?? null) === 'draft' && ! empty($fairResult['draft_id']))
                            <x-filament::button size="sm" color="success" icon="heroicon-o-check" wire:click="approveFairBalancedDraft({{ (int) $fairResult['draft_id'] }})" wire:confirm="{{ __('exam.fair_draft.actions.approve') }}">
                                اعتماد وتثبيت التوزيع
                            </x-filament::button>
                            <x-filament::button size="sm" color="danger" icon="heroicon-o-x-mark" wire:click="cancelFairBalancedDraft({{ (int) $fairResult['draft_id'] }})" wire:confirm="{{ __('exam.fair_draft.actions.cancel') }}">
                                إلغاء المسودة
                            </x-filament::button>
                        @endif
                    </div>
                </div>

                <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-md bg-white/70 p-3 dark:bg-white/10">
                        <div class="text-xs opacity-75">{{ __('exam.fair_draft.fields.total_observers') }}</div>
                        <div class="mt-1 text-lg font-semibold">{{ number_format((int) ($fairResult['total_observers'] ?? 0)) }}</div>
                    </div>
                    <div class="rounded-md bg-white/70 p-3 dark:bg-white/10">
                        <div class="text-xs opacity-75">{{ __('exam.fair_draft.fields.total_duties') }}</div>
                        <div class="mt-1 text-lg font-semibold">{{ number_format((int) ($fairResult['total_duties'] ?? 0)) }}</div>
                    </div>
                    <div class="rounded-md bg-white/70 p-3 dark:bg-white/10">
                        <div class="text-xs opacity-75">{{ __('exam.fair_draft.fields.min_duties') }} / {{ __('exam.fair_draft.fields.max_duties') }}</div>
                        <div class="mt-1 text-lg font-semibold">{{ (int) ($fairResult['min_duties'] ?? 0) }} / {{ (int) ($fairResult['max_duties'] ?? 0) }}</div>
                    </div>
                    <div class="rounded-md bg-white/70 p-3 dark:bg-white/10">
                        <div class="text-xs opacity-75">{{ __('exam.fair_draft.fields.average_duties') }}</div>
                        <div class="mt-1 text-lg font-semibold">{{ rtrim(rtrim(number_format((float) ($fairResult['average_duties'] ?? 0), 2), '0'), '.') }}</div>
                    </div>
                    <div class="rounded-md bg-white/70 p-3 dark:bg-white/10">
                        <div class="text-xs opacity-75">{{ __('exam.fair_draft.fields.changed_observers') }}</div>
                        <div class="mt-1 text-lg font-semibold">{{ number_format((int) ($fairResult['changed_observers_count'] ?? 0)) }}</div>
                    </div>
                    <div class="rounded-md bg-white/70 p-3 dark:bg-white/10">
                        <div class="text-xs opacity-75">{{ __('exam.fair_draft.fields.relaxed_constraints_count') }}</div>
                        <div class="mt-1 text-lg font-semibold">{{ number_format((int) ($fairResult['relaxed_constraints_count'] ?? 0)) }}</div>
                    </div>
                </div>
            </section>
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
