@extends('layouts.public', ['title' => 'الرئيسية'])

@section('content')
    @php
        $settings = null;

        try {
            $settings = \App\Models\SystemSetting::query()->first();
        } catch (\Throwable) {
            $settings = null;
        }

        $universityName = filled($settings?->university_name)
            ? $settings->university_name
            : 'جامعة اللاذقية';

        $universityLogoUrl = filled($settings?->university_logo)
            ? \Illuminate\Support\Facades\Storage::disk('public')->url($settings->university_logo)
            : null;

        $features = [
            ['label' => 'استعلام سريع', 'text' => 'يعرض نتيجة الطالب مباشرة بعد إدخال الرقم الامتحاني.'],
            ['label' => 'معلومات دقيقة', 'text' => 'المادة والتاريخ والوقت والقاعة وموقعها في مكان واحد.'],
            ['label' => 'مناسب للجوال', 'text' => 'واجهة بطاقات واضحة وسهلة القراءة على الشاشات الصغيرة.'],
            ['label' => 'تحديثات مباشرة', 'text' => 'تظهر النتائج بعد تنفيذ توزيع القاعات واعتمادها.'],
        ];

        $steps = [
            'أدخل الرقم الامتحاني',
            'اضغط بحث',
            'اعرف القاعة والوقت والموقع',
        ];
    @endphp

    <div class="mx-auto w-full max-w-6xl px-4 py-8 sm:px-6 lg:px-8">
        <section class="grid gap-8 py-8 lg:grid-cols-[minmax(0,1fr)_24rem] lg:items-center lg:py-14">
            <div>
                <div class="inline-flex items-center gap-3 rounded-2xl border border-blue-100 bg-white/80 px-4 py-3 shadow-sm shadow-blue-950/5">
                    @if ($universityLogoUrl)
                        <img src="{{ $universityLogoUrl }}" alt="{{ $universityName }}" class="h-12 w-12 rounded-xl object-contain">
                    @else
                        <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-blue-700 text-xl font-bold text-white">
                            {{ mb_substr($universityName, 0, 1) }}
                        </span>
                    @endif

                    <span>
                        <span class="block text-sm font-bold text-blue-700">{{ $universityName }}</span>
                        <span class="block text-xs font-semibold text-slate-500">خدمة الاستعلام الامتحاني</span>
                    </span>
                </div>

                <h1 class="mt-6 max-w-3xl text-4xl font-black leading-[1.25] text-slate-950 sm:text-5xl">
                    نظام توزيع القاعات الامتحانية
                </h1>

                <p class="mt-5 max-w-2xl text-base leading-8 text-slate-600 sm:text-lg">
                    منصة إلكترونية تساعد الطلاب على معرفة القاعة الامتحانية، التاريخ، الوقت، وموقع القاعة بسهولة.
                </p>

                <div class="mt-8 flex flex-col gap-3 sm:flex-row sm:items-center">
                    <a href="{{ route('students.lookup') }}" class="inline-flex min-h-12 items-center justify-center rounded-2xl bg-blue-700 px-7 py-3 text-base font-bold text-white shadow-lg shadow-blue-700/20 transition hover:-translate-y-0.5 hover:bg-blue-800">
                        استعلام الطلاب الآن
                    </a>
                    <p class="text-sm leading-6 text-slate-500">
                        أدخل رقمك الامتحاني واحصل على النتيجة خلال ثوانٍ.
                    </p>
                </div>
            </div>

            <div class="rounded-3xl border border-white/80 bg-white/90 p-5 shadow-xl shadow-blue-950/10">
                <div class="rounded-2xl bg-blue-700 p-5 text-white">
                    <p class="text-sm font-semibold text-blue-100">نتيجة الطالب</p>
                    <p class="mt-3 text-2xl font-black">كل تفاصيل الامتحان في بطاقة واحدة</p>
                    <p class="mt-3 text-sm leading-6 text-blue-100">
                        القاعة، الموقع، وقت الامتحان، وحالة التوزيع تظهر بتنسيق واضح وسريع القراءة.
                    </p>
                </div>

                <div class="mt-4 grid gap-3">
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                        <p class="text-xs font-bold text-slate-500">المادة</p>
                        <p class="mt-1 text-lg font-black text-slate-950">مثال: دوائر كهربائية</p>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4">
                            <p class="text-xs font-bold text-emerald-700">الحالة</p>
                            <p class="mt-1 text-sm font-black text-emerald-800">امتحان اليوم</p>
                        </div>
                        <div class="rounded-2xl border border-blue-200 bg-blue-50 p-4">
                            <p class="text-xs font-bold text-blue-700">القاعة</p>
                            <p class="mt-1 text-sm font-black text-blue-900">القاعة 1</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="grid gap-4 py-6 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($features as $feature)
                <article class="rounded-3xl border border-white/80 bg-white/90 p-5 shadow-sm shadow-blue-950/5 transition hover:-translate-y-1 hover:shadow-lg hover:shadow-blue-950/10">
                    <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-blue-50 text-blue-700">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M5 12.5l4 4L19 6.5" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </div>
                    <h2 class="mt-4 text-lg font-black text-slate-950">{{ $feature['label'] }}</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">{{ $feature['text'] }}</p>
                </article>
            @endforeach
        </section>

        <section class="grid gap-5 py-6 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start">
            <div class="rounded-3xl border border-white/80 bg-white/90 p-5 shadow-sm shadow-blue-950/5 sm:p-6">
                <p class="text-sm font-bold text-blue-700">خطوات الاستعلام</p>
                <h2 class="mt-2 text-2xl font-black text-slate-950">ثلاث خطوات بسيطة للوصول إلى القاعة</h2>

                <div class="mt-6 grid gap-3 sm:grid-cols-3">
                    @foreach ($steps as $index => $step)
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-700 text-sm font-black text-white">
                                {{ $index + 1 }}
                            </div>
                            <p class="mt-4 text-base font-black text-slate-950">{{ $step }}</p>
                        </div>
                    @endforeach
                </div>
            </div>

            <aside class="rounded-3xl border border-amber-200 bg-amber-50 p-5 text-amber-900 shadow-sm">
                <p class="text-sm font-black">تنبيه</p>
                <p class="mt-2 text-sm leading-7">
                    هذه الصفحة مخصصة للاستعلام فقط. في حال وجود خطأ يرجى مراجعة شؤون الطلاب.
                </p>
            </aside>
        </section>
    </div>
@endsection
