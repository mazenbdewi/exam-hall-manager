<x-filament-panels::page>
    <div
        x-data="{}"
        x-load-css="[@js(\Filament\Support\Facades\FilamentAsset::getStyleHref('filament-spatie-backup-styles', package: 'filament-spatie-backup'))]"
    >
        <div class="fsb-flex fsb-flex-col fsb-gap-y-8">
            <x-filament::section>
                <x-slot name="heading">
                    جدولة النسخ الاحتياطي
                </x-slot>

                <div class="space-y-6" dir="rtl">
                    <div class="grid gap-4 text-sm md:grid-cols-2 xl:grid-cols-4">
                        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                            <div class="text-gray-500 dark:text-gray-400">الحالة</div>
                            <div class="mt-1 font-semibold text-success-600 dark:text-success-400">مفعلة</div>
                        </div>

                        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                            <div class="text-gray-500 dark:text-gray-400">النوع</div>
                            <div class="mt-1 font-semibold">قاعدة البيانات فقط</div>
                        </div>

                        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                            <div class="text-gray-500 dark:text-gray-400">التكرار</div>
                            <div class="mt-1 font-semibold">يوميًا</div>
                        </div>

                        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                            <div class="text-gray-500 dark:text-gray-400">وقت التشغيل</div>
                            <div class="mt-1 font-semibold">{{ $this->getDatabaseBackupTimeLabel() }}</div>
                        </div>

                        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                            <div class="text-gray-500 dark:text-gray-400">المنطقة الزمنية</div>
                            <div class="mt-1 font-semibold">Asia/Damascus</div>
                        </div>

                        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                            <div class="text-gray-500 dark:text-gray-400">التنظيف التلقائي</div>
                            <div class="mt-1 font-semibold text-success-600 dark:text-success-400">مفعّل</div>
                        </div>

                        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                            <div class="text-gray-500 dark:text-gray-400">آخر تشغيل</div>
                            <div class="mt-1 font-semibold">{{ $this->getLastBackupRunLabel() }}</div>
                        </div>

                        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                            <div class="text-gray-500 dark:text-gray-400">التشغيل القادم</div>
                            <div class="mt-1 font-semibold">{{ $this->getNextBackupRunLabel() }}</div>
                        </div>
                    </div>

                    <form wire:submit="saveBackupScheduleTime" class="space-y-4">
                        {{ $this->form }}

                        <x-filament::button type="submit" color="primary" icon="heroicon-o-check-circle">
                            حفظ وقت الجدولة
                        </x-filament::button>
                    </form>
                </div>
            </x-filament::section>

            @if ($this->shouldDisplayStatusListRecords())
                <div class="fsb-mb-10">
                    @livewire(ShuvroRoy\FilamentSpatieLaravelBackup\Components\BackupDestinationStatusListRecords::class)
                </div>
            @endif

            <div>
                @livewire(ShuvroRoy\FilamentSpatieLaravelBackup\Components\BackupDestinationListRecords::class)
            </div>
        </div>
    </div>
</x-filament-panels::page>
