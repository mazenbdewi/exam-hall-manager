@php
    $statusClasses = [
        'green' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
        'orange' => 'border-amber-200 bg-amber-50 text-amber-700',
        'gray' => 'border-gray-200 bg-gray-50 text-gray-700',
    ];

    $cardAccentClasses = [
        'green' => 'border-r-emerald-500',
        'orange' => 'border-r-amber-500',
        'gray' => 'border-r-gray-300',
    ];
@endphp

<section class="mx-auto flex w-full max-w-6xl flex-col gap-6 px-4 py-8 sm:px-6 lg:px-8">
    <div class="mx-auto w-full max-w-3xl text-center print:hidden">
        <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-3xl bg-blue-700 text-white shadow-lg shadow-blue-700/20">
            <svg class="h-8 w-8" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M8 6h8M8 10h8M8 14h5" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                <path d="M7 3.5h10A1.5 1.5 0 0 1 18.5 5v14A1.5 1.5 0 0 1 17 20.5H7A1.5 1.5 0 0 1 5.5 19V5A1.5 1.5 0 0 1 7 3.5Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round" />
                <path d="M15.5 17.5l2 2 3.5-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </div>
        <p class="mt-5 text-sm font-bold text-blue-700">خدمة المراقبين</p>
        <h1 class="mt-2 text-3xl font-black leading-10 text-slate-950 sm:text-4xl">
            استعلام المراقب عن جدول المراقبة
        </h1>
        <p class="mx-auto mt-3 max-w-2xl text-sm leading-7 text-slate-600 sm:text-base">
            أدخل رقم هاتفك لمعرفة المراقبات المسندة إليك حسب الفترة المسموح بعرضها.
        </p>
    </div>

    <form wire:submit.prevent="search" class="mx-auto w-full max-w-3xl rounded-3xl border border-white/80 bg-white/95 p-5 shadow-xl shadow-blue-950/10 transition sm:p-6 print:hidden">
        <label for="invigilator-phone" class="block text-base font-black text-slate-950">رقم الهاتف</label>
        <div class="mt-3 flex flex-col gap-3 sm:flex-row">
            <input
                id="invigilator-phone"
                type="tel"
                inputmode="tel"
                autocomplete="off"
                maxlength="30"
                wire:model.defer="phone"
                placeholder="مثال: 0912345678"
                class="min-h-14 w-full rounded-2xl border border-slate-300 bg-white px-4 text-lg font-bold text-slate-950 outline-none transition placeholder:text-slate-400 focus:border-blue-600 focus:ring-4 focus:ring-blue-100"
            >

            <button type="submit" class="inline-flex min-h-14 items-center justify-center rounded-2xl bg-blue-700 px-6 text-base font-black text-white shadow-lg shadow-blue-700/20 transition hover:-translate-y-0.5 hover:bg-blue-800 disabled:cursor-wait disabled:translate-y-0 disabled:opacity-75 sm:min-w-64" wire:loading.attr="disabled" wire:target="search">
                <span wire:loading.remove wire:target="search">بحث عن مراقباتي</span>
                <span wire:loading wire:target="search" class="inline-flex items-center gap-2">
                    <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M12 3a9 9 0 1 1-8.05 4.97" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                    </svg>
                    جاري البحث...
                </span>
            </button>
        </div>

        @error('phone')
            <p class="mt-3 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold text-red-700">{{ $message }}</p>
        @enderror

        @if ($searched)
            <button type="button" wire:click="resetSearch" class="mt-4 inline-flex min-h-11 items-center rounded-xl border border-slate-200 bg-white px-4 text-sm font-bold text-slate-700 transition hover:border-blue-200 hover:bg-blue-50 hover:text-blue-800">
                بحث جديد
            </button>
        @endif
    </form>

    @if ($message)
        <div class="mx-auto w-full max-w-3xl rounded-3xl border border-amber-200 bg-amber-50 p-5 text-base font-bold leading-7 text-amber-900 shadow-sm">
            {{ $message }}

            @if ($searched && $invigilator && $assignments === [])
                <p class="mt-2 text-sm leading-7 text-amber-800">
                    قد تظهر المراقبة قبل موعدها حسب إعدادات الكلية.
                </p>
            @endif
        </div>
    @endif

    @if ($invigilator)
        <section class="rounded-3xl border border-white/80 bg-white/95 p-5 shadow-lg shadow-blue-950/5 sm:p-6 print:border-slate-300 print:shadow-none">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="text-sm font-bold text-blue-700">بيانات المراقب</p>
                    <h2 class="mt-1 text-2xl font-black text-slate-950">{{ $invigilator['name'] }}</h2>
                </div>

                <button type="button" onclick="window.print()" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 transition hover:bg-slate-100 print:hidden">
                    طباعة النتيجة
                </button>
            </div>

            <dl class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-2xl bg-slate-50 px-4 py-3">
                    <dt class="text-xs font-bold text-slate-500">اسم المراقب</dt>
                    <dd class="mt-1 text-base font-black text-slate-950">{{ $invigilator['name'] }}</dd>
                </div>
                <div class="rounded-2xl bg-slate-50 px-4 py-3">
                    <dt class="text-xs font-bold text-slate-500">نوع الكادر</dt>
                    <dd class="mt-1 text-base font-black text-slate-950">{{ $invigilator['staff_category'] }}</dd>
                </div>
                <div class="rounded-2xl bg-slate-50 px-4 py-3">
                    <dt class="text-xs font-bold text-slate-500">نوع المراقبة</dt>
                    <dd class="mt-1 text-base font-black text-slate-950">{{ $invigilator['invigilation_role'] }}</dd>
                </div>
                <div class="rounded-2xl bg-slate-50 px-4 py-3">
                    <dt class="text-xs font-bold text-slate-500">الكلية</dt>
                    <dd class="mt-1 text-base font-black text-slate-950">{{ $invigilator['college'] }}</dd>
                </div>
            </dl>
        </section>
    @endif

    @if ($assignments !== [])
        <section>
            <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="text-sm font-bold text-blue-700">مراقباتي</p>
                    <h2 class="mt-1 text-2xl font-black text-slate-950">المراقبات المتاحة للعرض</h2>
                </div>
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                @foreach ($assignments as $assignment)
                    @php
                        $tone = $assignment['status_tone'] ?? 'gray';
                        $statusClass = $statusClasses[$tone] ?? $statusClasses['gray'];
                        $accentClass = $cardAccentClasses[$tone] ?? $cardAccentClasses['gray'];
                    @endphp

                    <article class="rounded-3xl border border-white/80 border-r-4 {{ $accentClass }} bg-white/95 p-5 shadow-lg shadow-blue-950/5 transition hover:-translate-y-1 hover:shadow-xl hover:shadow-blue-950/10 print:break-inside-avoid print:shadow-none">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <p class="text-sm font-bold text-slate-500">القاعة</p>
                                <h3 class="mt-1 text-2xl font-black leading-8 text-slate-950">{{ $assignment['hall'] }}</h3>
                            </div>

                            <span class="inline-flex w-fit rounded-full border px-3 py-1 text-sm font-black {{ $statusClass }}">
                                {{ $assignment['status_label'] }}
                            </span>
                        </div>

                        <dl class="mt-5 grid gap-3 sm:grid-cols-2">
                            <div class="rounded-2xl bg-slate-50 px-4 py-3">
                                <dt class="text-xs font-bold text-slate-500">التاريخ</dt>
                                <dd class="mt-1 text-lg font-black text-slate-950">{{ $assignment['date'] }}</dd>
                            </div>
                            <div class="rounded-2xl bg-slate-50 px-4 py-3">
                                <dt class="text-xs font-bold text-slate-500">الوقت</dt>
                                <dd class="mt-1 text-lg font-black text-slate-950">{{ $assignment['time'] }}</dd>
                            </div>
                            <div class="rounded-2xl border border-blue-200 bg-blue-50 px-4 py-3">
                                <dt class="text-xs font-bold text-blue-700">موقع القاعة</dt>
                                <dd class="mt-1 text-lg font-black text-blue-950">{{ $assignment['location'] }}</dd>
                            </div>
                            <div class="rounded-2xl border border-indigo-200 bg-indigo-50 px-4 py-3">
                                <dt class="text-xs font-bold text-indigo-700">الدور في القاعة</dt>
                                <dd class="mt-1 text-lg font-black text-indigo-950">{{ $assignment['role'] }}</dd>
                            </div>
                            <div class="rounded-2xl bg-slate-50 px-4 py-3 sm:col-span-2">
                                <dt class="text-xs font-bold text-slate-500">الحالة</dt>
                                <dd class="mt-1 text-lg font-black text-slate-950">{{ $assignment['status_label'] }}</dd>
                            </div>
                        </dl>
                    </article>
                @endforeach
            </div>
        </section>

        <p class="pb-6 text-center text-sm font-medium leading-6 text-slate-500 print:pb-0">
            قد تظهر المراقبة قبل موعدها بمدة تحددها الكلية من لوحة التحكم.
        </p>
    @endif
</section>
