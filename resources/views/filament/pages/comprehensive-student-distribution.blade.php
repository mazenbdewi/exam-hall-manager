<x-filament-panels::page>
    @php
        $run = $this->latestDistributionRun();
        $resultUrl = $this->latestResultUrl();
        $printableSlots = $this->latestPrintableSlots();
        $summary = $run?->summary_json ?? [];
        $tone = match ($run?->status) {
            'success' => 'success',
            'partial' => 'warning',
            'failed' => 'danger',
            default => 'gray',
        };
        $badgeClasses = [
            'success' => 'bg-success-100 text-success-700 dark:bg-success-500/10 dark:text-success-300',
            'warning' => 'bg-warning-100 text-warning-700 dark:bg-warning-500/10 dark:text-warning-300',
            'danger' => 'bg-danger-100 text-danger-700 dark:bg-danger-500/10 dark:text-danger-300',
            'gray' => 'bg-gray-100 text-gray-700 dark:bg-white/10 dark:text-gray-300',
        ];
    @endphp

    <div dir="rtl" class="space-y-5 text-right">
        <section class="rounded-3xl border border-primary-200 bg-primary-50 p-5 shadow-sm dark:border-primary-500/20 dark:bg-primary-500/10">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <div class="flex items-center gap-3">
                        <div class="rounded-2xl bg-primary-100 p-3 text-primary-700 dark:bg-primary-500/15 dark:text-primary-300">
                            <x-filament::icon icon="heroicon-o-sparkles" class="h-6 w-6" />
                        </div>
                        <div>
                            <h2 class="text-xl font-bold text-primary-950 dark:text-primary-100">توزيع شامل للطلاب على القاعات</h2>
                            <p class="mt-1 text-sm leading-6 text-primary-800 dark:text-primary-200">
                                هذه الصفحة مخصصة لتشغيل التوزيع الكامل لكل البرامج الامتحانية ضمن فترة محددة، ثم متابعة النتائج وطباعة كشوف تفقد القاعات من البيانات المحفوظة.
                            </p>
                        </div>
                    </div>
                </div>

                <x-filament::button tag="a" :href="$this->examProgramsUrl()" color="gray" icon="heroicon-o-clipboard-document-list">
                    البرامج الامتحانية
                </x-filament::button>
            </div>
        </section>

        <section class="rounded-3xl border border-gray-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h3 class="text-lg font-bold text-gray-950 dark:text-white">{{ __('exam.global_hall_distribution.latest_title') }}</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">آخر عملية توزيع محفوظة في قاعدة البيانات، مع روابط النتائج والطباعة العملية للقاعات.</p>
                </div>

                @if ($resultUrl)
                    <x-filament::button tag="a" :href="$resultUrl" color="gray" icon="heroicon-o-document-chart-bar">
                        {{ __('exam.actions.view_problem_details') }}
                    </x-filament::button>
                @endif
            </div>

            @if ($run)
                <div class="grid gap-3 md:grid-cols-3 xl:grid-cols-6">
                    <div class="rounded-2xl bg-gray-50 p-4 dark:bg-white/5">
                        <div class="text-xs text-gray-500">{{ __('exam.fields.college') }}</div>
                        <div class="mt-1 font-bold text-gray-950 dark:text-white">{{ $run->college?->name }}</div>
                    </div>
                    <div class="rounded-2xl bg-gray-50 p-4 dark:bg-white/5">
                        <div class="text-xs text-gray-500">{{ __('exam.fields.period') }}</div>
                        <div class="mt-1 font-bold text-gray-950 dark:text-white">{{ $run->from_date?->format('Y-m-d') }} - {{ $run->to_date?->format('Y-m-d') }}</div>
                    </div>
                    <div class="rounded-2xl bg-gray-50 p-4 dark:bg-white/5">
                        <div class="text-xs text-gray-500">{{ __('exam.fields.status') }}</div>
                        <div class="mt-2">
                            <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $badgeClasses[$tone] ?? $badgeClasses['gray'] }}">
                                {{ $run->statusLabel() }}
                            </span>
                        </div>
                    </div>
                    <div class="rounded-2xl bg-gray-50 p-4 dark:bg-white/5">
                        <div class="text-xs text-gray-500">{{ __('exam.global_hall_distribution.summary.students_count') }}</div>
                        <div class="mt-1 text-xl font-bold text-gray-950 dark:text-white">{{ $run->total_students }}</div>
                    </div>
                    <div class="rounded-2xl bg-gray-50 p-4 dark:bg-white/5">
                        <div class="text-xs text-gray-500">{{ __('exam.global_hall_distribution.summary.assigned_students_count') }}</div>
                        <div class="mt-1 text-xl font-bold text-gray-950 dark:text-white">{{ $run->distributed_students }}</div>
                    </div>
                    <div class="rounded-2xl bg-gray-50 p-4 dark:bg-white/5">
                        <div class="text-xs text-gray-500">{{ __('exam.global_hall_distribution.summary.unassigned_students_count') }}</div>
                        <div class="mt-1 text-xl font-bold text-gray-950 dark:text-white">{{ $run->unassigned_students }}</div>
                    </div>
                </div>

                <div class="mt-4 grid gap-3 md:grid-cols-4">
                    <div class="rounded-2xl border border-gray-100 p-4 dark:border-white/10">
                        <div class="text-xs text-gray-500">{{ __('exam.global_hall_distribution.summary.offerings_count') }}</div>
                        <div class="mt-1 text-lg font-bold">{{ $run->total_offerings }}</div>
                    </div>
                    <div class="rounded-2xl border border-gray-100 p-4 dark:border-white/10">
                        <div class="text-xs text-gray-500">{{ __('exam.global_hall_distribution.summary.slots_count') }}</div>
                        <div class="mt-1 text-lg font-bold">{{ $run->total_slots }}</div>
                    </div>
                    <div class="rounded-2xl border border-gray-100 p-4 dark:border-white/10">
                        <div class="text-xs text-gray-500">{{ __('exam.global_hall_distribution.summary.used_halls_count') }}</div>
                        <div class="mt-1 text-lg font-bold">{{ $run->used_halls }}</div>
                    </div>
                    <div class="rounded-2xl border border-gray-100 p-4 dark:border-white/10">
                        <div class="text-xs text-gray-500">{{ __('exam.fields.executed_at') }}</div>
                        <div class="mt-1 text-sm font-bold">{{ $run->executed_at?->format('Y-m-d H:i') }}</div>
                    </div>
                </div>
            @else
                <div class="rounded-2xl border border-dashed border-gray-300 bg-gray-50 p-6 text-sm text-gray-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-300">
                    {{ __('exam.global_hall_distribution.no_previous_run') }}
                </div>
            @endif
        </section>

        @if ($run)
            <section class="rounded-3xl border border-gray-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="mb-4">
                    <h3 class="text-lg font-bold text-gray-950 dark:text-white">طباعة تفقد القاعات</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">روابط طباعة عملية لكل موعد موزع في آخر تشغيل محفوظ. كل رابط يطبع جميع قاعات ذلك الموعد مع أسماء الطلاب والمراقبين.</p>
                </div>

                @if ($printableSlots !== [])
                    <div class="grid gap-3 lg:grid-cols-2">
                        @foreach ($printableSlots as $slot)
                            <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <div class="font-bold text-gray-950 dark:text-white">
                                            {{ $slot['exam_date'] }} · {{ substr((string) $slot['exam_start_time'], 0, 5) }}
                                        </div>
                                        <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                            {{ __('exam.global_hall_distribution.summary.used_halls_count') }}: {{ $slot['used_halls_count'] }}
                                            · {{ __('exam.global_hall_distribution.summary.assigned_students_count') }}: {{ $slot['assigned_students_count'] }}
                                        </div>
                                    </div>

                                    <x-filament::button tag="a" :href="$slot['print_url']" target="_blank" rel="noopener" color="success" icon="heroicon-o-printer">
                                        طباعة تفقد كل القاعات
                                    </x-filament::button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="rounded-2xl border border-dashed border-gray-300 bg-gray-50 p-5 text-sm text-gray-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-300">
                        لا توجد قاعات موزعة في آخر تشغيل محفوظ يمكن طباعتها الآن.
                    </div>
                @endif
            </section>
        @endif
    </div>
</x-filament-panels::page>
