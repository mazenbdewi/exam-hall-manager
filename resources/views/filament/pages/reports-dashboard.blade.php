<x-filament-panels::page>
    @php
        $fixedProgramOptions = $this->fixedProgramOptions();
        $attendanceSlotOptions = $this->attendanceSlotOptions();
        $hallAssignmentOptions = $this->hallAssignmentOptions();
        $hallAttendancePrintUrl = $this->hallAttendancePrintUrl();
        $singleHallAttendancePrintUrl = $this->singleHallAttendancePrintUrl();
        $cardClasses = 'rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900';
        $cardHeaderClasses = 'flex items-start gap-3';
        $iconBoxClasses = 'rounded-lg bg-primary-50 p-2 text-primary-700 dark:bg-primary-500/10 dark:text-primary-300';
        $descriptionClasses = 'mt-1 text-sm leading-6 text-gray-500 dark:text-gray-400';
    @endphp

    <div dir="rtl" class="space-y-5 text-right">
        <section class="rounded-lg border border-primary-200 bg-primary-50 p-4 dark:border-primary-500/20 dark:bg-primary-500/10">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="text-lg font-bold text-primary-950 dark:text-primary-100">التقارير والطباعة</h2>
                    <p class="mt-1 text-sm leading-6 text-primary-800 dark:text-primary-200">
                        هذا المكان يجمع الطباعة والتقارير في نقطة واحدة بعد تجهيز البيانات وتوزيع الطلاب والمراقبين.
                    </p>
                </div>

                @if (\App\Support\ExamCollegeScope::isSuperAdmin())
                    <label class="w-full max-w-sm space-y-1">
                        <span class="text-sm font-medium text-primary-900 dark:text-primary-100">الكلية</span>
                        <select wire:model.live="college_id" class="w-full rounded-md border-primary-200 bg-white dark:border-white/10 dark:bg-gray-900">
                            @foreach ($this->collegeOptions() as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif
            </div>
        </section>

        <section class="grid gap-4 xl:grid-cols-2">
            <div class="{{ $cardClasses }}">
                <div class="{{ $cardHeaderClasses }}">
                    <div class="{{ $iconBoxClasses }}">
                        <x-filament::icon icon="heroicon-o-calendar-days" class="h-5 w-5" />
                    </div>
                    <div>
                        <h3 class="font-bold text-gray-950 dark:text-white">طباعة برنامج الامتحان</h3>
                        <p class="{{ $descriptionClasses }}">طباعة البرنامج الامتحاني الرسمي حسب الكلية والقسم والفصل من آخر نسخة مثبتة مناسبة.</p>
                    </div>
                </div>

                <div class="mt-4 space-y-3">
                    <label class="space-y-1">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-200">النسخة المثبتة</span>
                        <select wire:model.live="fixed_exam_program_id" class="w-full rounded-md border-gray-300 dark:border-white/10 dark:bg-gray-800">
                            <option value="">آخر نسخة متاحة</option>
                            @foreach ($fixedProgramOptions as $id => $label)
                                <option value="{{ $id }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>

                    <div class="flex flex-wrap gap-2">
                        <x-filament::button tag="a" :href="$this->examSchedulePrintUrl()" target="_blank" rel="noopener" icon="heroicon-o-printer">
                            فتح
                        </x-filament::button>
                        <x-filament::button tag="a" :href="$this->fixedProgramsUrl()" color="gray" icon="heroicon-o-document-check">
                            طباعة البرامج الامتحانية المثبتة
                        </x-filament::button>
                    </div>
                </div>
            </div>

            <div class="{{ $cardClasses }}">
                <div class="{{ $cardHeaderClasses }}">
                    <div class="{{ $iconBoxClasses }}">
                        <x-filament::icon icon="heroicon-o-clipboard-document-check" class="h-5 w-5" />
                    </div>
                    <div>
                        <h3 class="font-bold text-gray-950 dark:text-white">طباعة تفقد القاعات</h3>
                        <p class="{{ $descriptionClasses }}">طباعة كشوف تفقد القاعات مع الطلاب والمراقبين، أو طباعة قاعة محددة عند الحاجة.</p>
                    </div>
                </div>

                <div class="mt-4 grid gap-3 md:grid-cols-2">
                    <label class="space-y-1">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-200">الموعد</span>
                        <select wire:model.live="attendance_slot" class="w-full rounded-md border-gray-300 dark:border-white/10 dark:bg-gray-800">
                            <option value="">اختر موعدًا</option>
                            @foreach ($attendanceSlotOptions as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="space-y-1">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-200">القاعة</span>
                        <select wire:model.live="hall_assignment_id" class="w-full rounded-md border-gray-300 dark:border-white/10 dark:bg-gray-800">
                            <option value="">أول قاعة في الموعد</option>
                            @foreach ($hallAssignmentOptions as $id => $label)
                                <option value="{{ $id }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>

                <div class="mt-4 flex flex-wrap gap-2">
                    <x-filament::button tag="a" :href="$hallAttendancePrintUrl ?: '#'" target="_blank" rel="noopener" icon="heroicon-o-printer" :disabled="! $hallAttendancePrintUrl">
                        طباعة تفقد القاعات
                    </x-filament::button>
                    <x-filament::button tag="a" :href="$singleHallAttendancePrintUrl ?: '#'" target="_blank" rel="noopener" color="gray" icon="heroicon-o-document-text" :disabled="! $singleHallAttendancePrintUrl">
                        طباعة تفقد قاعة محددة
                    </x-filament::button>
                </div>
            </div>

            <div class="{{ $cardClasses }}">
                <div class="{{ $cardHeaderClasses }}">
                    <div class="{{ $iconBoxClasses }}">
                        <x-filament::icon icon="heroicon-o-chart-bar-square" class="h-5 w-5" />
                    </div>
                    <div>
                        <h3 class="font-bold text-gray-950 dark:text-white">تقرير توزيع الطلاب</h3>
                        <p class="{{ $descriptionClasses }}">مراجعة آخر توزيع شامل للطلاب على القاعات، مع ملخص النتائج وسجل التنفيذ.</p>
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap gap-2">
                    <x-filament::button tag="a" :href="$this->studentDistributionResultsUrl()" icon="heroicon-o-eye">
                        عرض التقرير
                    </x-filament::button>
                    <x-filament::button color="gray" icon="heroicon-o-document-arrow-down" wire:click="exportLatestStudentDistributionSummaryPdf">
                        تقرير توزيع الطلاب
                    </x-filament::button>
                    <x-filament::button tag="a" :href="$this->studentDistributionResultsUrl()" color="gray" icon="heroicon-o-clock">
                        سجل نتائج التوزيع
                    </x-filament::button>
                </div>
            </div>

            <div class="{{ $cardClasses }}">
                <div class="{{ $cardHeaderClasses }}">
                    <div class="{{ $iconBoxClasses }}">
                        <x-filament::icon icon="heroicon-o-table-cells" class="h-5 w-5" />
                    </div>
                    <div>
                        <h3 class="font-bold text-gray-950 dark:text-white">توزيع أعداد الطلاب على القاعات</h3>
                        <p class="{{ $descriptionClasses }}">يعرض أعداد الطلاب لكل مادة داخل كل قاعة حسب التاريخ والفترة الامتحانية.</p>
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap gap-2">
                    <x-filament::button tag="a" :href="$this->hallDistributionByPeriodReportUrl()" icon="heroicon-o-eye">
                        فتح التقرير
                    </x-filament::button>
                </div>
            </div>

            <div class="{{ $cardClasses }}">
                <div class="{{ $cardHeaderClasses }}">
                    <div class="{{ $iconBoxClasses }}">
                        <x-filament::icon icon="heroicon-o-document-text" class="h-5 w-5" />
                    </div>
                    <div>
                        <h3 class="font-bold text-gray-950 dark:text-white">كشف توزيع الطلاب على القاعات حسب المادة والفترة</h3>
                        <p class="{{ $descriptionClasses }}">يعرض لكل طالب رقمه الجامعي واسمه ورقم جلوسه المولد والقاعة حسب المادة والفترة.</p>
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap gap-2">
                    <x-filament::button tag="a" :href="$this->studentHallAssignmentReportUrl()" icon="heroicon-o-eye">
                        فتح التقرير
                    </x-filament::button>
                </div>
            </div>

            <div class="{{ $cardClasses }}">
                <div class="{{ $cardHeaderClasses }}">
                    <div class="{{ $iconBoxClasses }}">
                        <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5" />
                    </div>
                    <div>
                        <h3 class="font-bold text-gray-950 dark:text-white">تقرير الطلاب غير الموزعين</h3>
                        <p class="{{ $descriptionClasses }}">عرض الطلاب الذين لم يتم توزيعهم على القاعات في آخر تشغيل محفوظ.</p>
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap gap-2">
                    <x-filament::button color="warning" icon="heroicon-o-document-arrow-down" wire:click="exportLatestUnassignedStudentsPdf">
                        عرض التقرير
                    </x-filament::button>
                    <x-filament::button tag="a" :href="$this->studentDistributionResultsUrl()" color="gray" icon="heroicon-o-eye">
                        مراجعة النتيجة
                    </x-filament::button>
                </div>
            </div>

            <div class="{{ $cardClasses }}">
                <div class="{{ $cardHeaderClasses }}">
                    <div class="{{ $iconBoxClasses }}">
                        <x-filament::icon icon="heroicon-o-user-group" class="h-5 w-5" />
                    </div>
                    <div>
                        <h3 class="font-bold text-gray-950 dark:text-white">تقرير توزيع المراقبين</h3>
                        <p class="{{ $descriptionClasses }}">مراجعة توزيع المراقبين على القاعات حسب القاعة أو اليوم أو المراقب.</p>
                    </div>
                </div>

                <div class="mt-4 grid gap-3 md:grid-cols-2">
                    <label class="space-y-1">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-200">من تاريخ</span>
                        <input type="date" wire:model.live="from_date" class="w-full rounded-md border-gray-300 dark:border-white/10 dark:bg-gray-800">
                    </label>
                    <label class="space-y-1">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-200">إلى تاريخ</span>
                        <input type="date" wire:model.live="to_date" class="w-full rounded-md border-gray-300 dark:border-white/10 dark:bg-gray-800">
                    </label>
                </div>

                <div class="mt-4 flex flex-wrap gap-2">
                    <x-filament::button tag="a" :href="$this->invigilatorDistributionUrl()" icon="heroicon-o-eye">
                        عرض التقرير
                    </x-filament::button>
                    <x-filament::button color="gray" icon="heroicon-o-building-office-2" wire:click="exportInvigilatorPdfByHall">
                        حسب القاعات
                    </x-filament::button>
                    <x-filament::button color="gray" icon="heroicon-o-calendar-days" wire:click="exportInvigilatorPdfByDay">
                        حسب اليوم
                    </x-filament::button>
                    <x-filament::button color="gray" icon="heroicon-o-user" wire:click="exportInvigilatorPdfByInvigilator">
                        حسب المراقب
                    </x-filament::button>
                </div>
            </div>

            <div class="{{ $cardClasses }}">
                <div class="{{ $cardHeaderClasses }}">
                    <div class="{{ $iconBoxClasses }}">
                        <x-filament::icon icon="heroicon-o-magnifying-glass" class="h-5 w-5" />
                    </div>
                    <div>
                        <h3 class="font-bold text-gray-950 dark:text-white">الاستعلام</h3>
                        <p class="{{ $descriptionClasses }}">روابط الاستعلام العامة للطلاب والمراقبين عند الحاجة إلى تجربة ما يظهر للمستخدم النهائي.</p>
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap gap-2">
                    <x-filament::button tag="a" :href="$this->publicStudentLookupUrl()" target="_blank" rel="noopener" color="gray" icon="heroicon-o-academic-cap">
                        استعلام الطلاب
                    </x-filament::button>
                    <x-filament::button tag="a" :href="$this->publicInvigilatorLookupUrl()" target="_blank" rel="noopener" color="gray" icon="heroicon-o-user">
                        استعلام المراقبين
                    </x-filament::button>
                </div>
            </div>
        </section>
    </div>
</x-filament-panels::page>
