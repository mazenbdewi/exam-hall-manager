<?php

namespace Database\Seeders\Support;

final class DemoSeedData
{
    public static function colleges(): array
    {
        return [
            ['code' => 'HMK', 'name' => 'الهمك'],
            ['code' => 'CIV', 'name' => 'الهندسة المدنية'],
            ['code' => 'ARC', 'name' => 'الهندسة المعمارية'],
        ];
    }

    public static function departments(): array
    {
        return [
            'HMK' => [
                ['code' => 'PET', 'name' => 'هندسة بترول'],
                ['code' => 'TEL', 'name' => 'الاتصالات'],
                ['code' => 'MEC', 'name' => 'الميكاترونكس'],
            ],
            'CIV' => [
                ['code' => 'STR', 'name' => 'الإنشاءات'],
                ['code' => 'WAT', 'name' => 'الموارد المائية'],
                ['code' => 'MNG', 'name' => 'الإدارة الهندسية والإنشاء'],
            ],
            'ARC' => [
                ['code' => 'DES', 'name' => 'التصميم المعماري'],
                ['code' => 'URB', 'name' => 'التخطيط العمراني'],
                ['code' => 'TEC', 'name' => 'التقنيات المعمارية'],
            ],
        ];
    }

    public static function studyLevels(): array
    {
        return [
            1 => 'الأولى',
            2 => 'الثانية',
            3 => 'الثالثة',
            4 => 'الرابعة',
            5 => 'الخامسة',
        ];
    }

    public static function semesters(): array
    {
        return [
            1 => 'الفصل الأول',
            2 => 'الفصل الثاني',
            3 => 'الفصل الصيفي',
        ];
    }

    public static function academicYears(): array
    {
        return [
            ['name' => '2025-2026', 'is_current' => false],
            ['name' => '2026-2027', 'is_current' => true],
        ];
    }

    public static function users(): array
    {
        return [
            [
                'name' => 'مدير النظام',
                'email' => 'admin@admin.com',
                'password' => 'admin',
                'role' => 'super_admin',
                'college_code' => null,
            ],
            [
                'name' => 'مدير كلية الهمك',
                'email' => 'hmk-admin@admin.com',
                'password' => 'admin',
                'role' => 'admin',
                'college_code' => 'HMK',
            ],
            [
                'name' => 'مدير كلية الهندسة المدنية',
                'email' => 'eng-admin@admin.com',
                'password' => 'admin',
                'role' => 'admin',
                'college_code' => 'CIV',
            ],
            [
                'name' => 'مدير كلية الهندسة المعمارية',
                'email' => 'arc-admin@admin.com',
                'password' => 'admin',
                'role' => 'admin',
                'college_code' => 'ARC',
            ],
        ];
    }

    public static function subjects(): array
    {
        return [
            ['code' => 'HMK-MATH-101', 'name' => 'رياضيات هندسية 1', 'college_code' => 'HMK', 'department_code' => 'TEL', 'study_level' => 1],
            ['code' => 'HMK-PHYS-101', 'name' => 'فيزياء هندسية', 'college_code' => 'HMK', 'department_code' => 'TEL', 'study_level' => 1],
            ['code' => 'HMK-PROG-101', 'name' => 'برمجة 1', 'college_code' => 'HMK', 'department_code' => 'MEC', 'study_level' => 1],
            ['code' => 'HMK-CIRC-201', 'name' => 'دوائر كهربائية', 'college_code' => 'HMK', 'department_code' => 'TEL', 'study_level' => 2],
            ['code' => 'HMK-OIL-201', 'name' => 'مقدمة في هندسة النفط', 'college_code' => 'HMK', 'department_code' => 'PET', 'study_level' => 2],
            ['code' => 'HMK-SIGN-301', 'name' => 'إشارات ونظم', 'college_code' => 'HMK', 'department_code' => 'TEL', 'study_level' => 3],
            ['code' => 'HMK-DIGI-301', 'name' => 'إلكترونيات رقمية', 'college_code' => 'HMK', 'department_code' => 'TEL', 'study_level' => 3],
            ['code' => 'HMK-CONT-401', 'name' => 'تحكم آلي', 'college_code' => 'HMK', 'department_code' => 'MEC', 'study_level' => 4],
            ['code' => 'HMK-NET-401', 'name' => 'شبكات حاسوب صناعية', 'college_code' => 'HMK', 'department_code' => 'MEC', 'study_level' => 4],

            ['code' => 'CIV-MECH-101', 'name' => 'ميكانيك هندسي', 'college_code' => 'CIV', 'department_code' => 'STR', 'study_level' => 1],
            ['code' => 'CIV-SURV-101', 'name' => 'مساحة هندسية', 'college_code' => 'CIV', 'department_code' => 'WAT', 'study_level' => 1],
            ['code' => 'CIV-MATS-201', 'name' => 'مقاومة مواد', 'college_code' => 'CIV', 'department_code' => 'STR', 'study_level' => 2],
            ['code' => 'CIV-HYDR-301', 'name' => 'هيدروليك', 'college_code' => 'CIV', 'department_code' => 'WAT', 'study_level' => 3],
            ['code' => 'CIV-ANLY-301', 'name' => 'تحليل إنشائي 1', 'college_code' => 'CIV', 'department_code' => 'STR', 'study_level' => 3],
            ['code' => 'CIV-ROAD-401', 'name' => 'هندسة طرق', 'college_code' => 'CIV', 'department_code' => 'MNG', 'study_level' => 4],
            ['code' => 'CIV-CONC-401', 'name' => 'خرسانة مسلحة 1', 'college_code' => 'CIV', 'department_code' => 'STR', 'study_level' => 4],

            ['code' => 'ARC-DRAW-101', 'name' => 'رسم معماري 1', 'college_code' => 'ARC', 'department_code' => 'DES', 'study_level' => 1],
            ['code' => 'ARC-HIST-201', 'name' => 'تاريخ العمارة', 'college_code' => 'ARC', 'department_code' => 'DES', 'study_level' => 2],
            ['code' => 'ARC-MATL-201', 'name' => 'مواد بناء معمارية', 'college_code' => 'ARC', 'department_code' => 'TEC', 'study_level' => 2],
            ['code' => 'ARC-DES3-301', 'name' => 'تصميم معماري 3', 'college_code' => 'ARC', 'department_code' => 'DES', 'study_level' => 3],
            ['code' => 'ARC-LGHT-301', 'name' => 'إنارة معمارية', 'college_code' => 'ARC', 'department_code' => 'TEC', 'study_level' => 3],
            ['code' => 'ARC-URBN-401', 'name' => 'تخطيط عمراني', 'college_code' => 'ARC', 'department_code' => 'URB', 'study_level' => 4],
            ['code' => 'ARC-BIM-401', 'name' => 'نمذجة معلومات البناء', 'college_code' => 'ARC', 'department_code' => 'TEC', 'study_level' => 4],
        ];
    }

    public static function examHalls(): array
    {
        return [
            ['college_code' => 'HMK', 'name' => 'مدرج A', 'location' => 'المبنى الرئيسي - الطابق الأرضي', 'capacity' => 120, 'priority' => 'high', 'is_active' => true],
            ['college_code' => 'HMK', 'name' => 'القاعة 1', 'location' => 'المبنى الرئيسي - الطابق الأول', 'capacity' => 70, 'priority' => 'high', 'is_active' => true],
            ['college_code' => 'HMK', 'name' => 'القاعة 2', 'location' => 'المبنى الرئيسي - الطابق الثاني', 'capacity' => 60, 'priority' => 'medium', 'is_active' => true],
            ['college_code' => 'HMK', 'name' => 'قاعة الحاسوب 1', 'location' => 'مخبر الحاسوب - جناح B', 'capacity' => 40, 'priority' => 'low', 'is_active' => false],

            ['college_code' => 'CIV', 'name' => 'مدرج B', 'location' => 'مبنى المدنية - المدرج الغربي', 'capacity' => 200, 'priority' => 'high', 'is_active' => true],
            ['college_code' => 'CIV', 'name' => 'القاعة 3', 'location' => 'مبنى المدنية - الطابق الأول', 'capacity' => 100, 'priority' => 'medium', 'is_active' => true],
            ['college_code' => 'CIV', 'name' => 'القاعة 4', 'location' => 'مبنى المدنية - الطابق الثاني', 'capacity' => 80, 'priority' => 'medium', 'is_active' => true],
            ['college_code' => 'CIV', 'name' => 'قاعة الرسم 1', 'location' => 'مبنى المدنية - جناح الرسم', 'capacity' => 40, 'priority' => 'low', 'is_active' => true],

            ['college_code' => 'ARC', 'name' => 'مدرج العمارة', 'location' => 'مبنى العمارة - القاعة الكبرى', 'capacity' => 120, 'priority' => 'high', 'is_active' => true],
            ['college_code' => 'ARC', 'name' => 'مرسم 1', 'location' => 'مبنى العمارة - المرسم الشرقي', 'capacity' => 60, 'priority' => 'high', 'is_active' => true],
            ['college_code' => 'ARC', 'name' => 'مرسم 2', 'location' => 'مبنى العمارة - المرسم الغربي', 'capacity' => 80, 'priority' => 'medium', 'is_active' => true],
            ['college_code' => 'ARC', 'name' => 'قاعة النمذجة', 'location' => 'مبنى العمارة - مخبر النمذجة', 'capacity' => 40, 'priority' => 'low', 'is_active' => true],
        ];
    }

    public static function offeringSpecifications(): array
    {
        return [
            [
                'key' => 'HMK-2026-04-25-09-00-CIRC',
                'subject_code' => 'HMK-CIRC-201',
                'academic_year' => '2026-2027',
                'semester' => 'الفصل الثاني',
                'exam_date' => '2026-04-25',
                'exam_start_time' => '09:00:00',
                'status' => 'ready',
                'notes' => 'سيناريو توزيع كامل - مادة كبيرة تقود القاعة الأولى وحدها.',
                'regular_students' => 115,
                'carry_students' => 15,
            ],
            [
                'key' => 'HMK-2026-04-25-09-00-DIGI',
                'subject_code' => 'HMK-DIGI-301',
                'academic_year' => '2026-2027',
                'semester' => 'الفصل الثاني',
                'exam_date' => '2026-04-25',
                'exam_start_time' => '09:00:00',
                'status' => 'ready',
                'notes' => 'سيناريو توزيع كامل - ستختلط مع مادتين أخريين في نفس الجلسة.',
                'regular_students' => 42,
                'carry_students' => 8,
            ],
            [
                'key' => 'HMK-2026-04-25-09-00-PROG',
                'subject_code' => 'HMK-PROG-101',
                'academic_year' => '2026-2027',
                'semester' => 'الفصل الثاني',
                'exam_date' => '2026-04-25',
                'exam_start_time' => '09:00:00',
                'status' => 'ready',
                'notes' => 'سيناريو توزيع كامل - مادة متوسطة تسمح باختبار خلط ثلاث مواد في قاعة واحدة.',
                'regular_students' => 34,
                'carry_students' => 6,
            ],
            [
                'key' => 'HMK-2026-04-25-09-00-OIL',
                'subject_code' => 'HMK-OIL-201',
                'academic_year' => '2026-2027',
                'semester' => 'الفصل الثاني',
                'exam_date' => '2026-04-25',
                'exam_start_time' => '09:00:00',
                'status' => 'ready',
                'notes' => 'سيناريو توزيع كامل - مادة صغيرة تكمل توزيع القاعة الأخيرة.',
                'regular_students' => 24,
                'carry_students' => 6,
            ],

            [
                'key' => 'HMK-2026-04-25-13-00-MATH',
                'subject_code' => 'HMK-MATH-101',
                'academic_year' => '2026-2027',
                'semester' => 'الفصل الثاني',
                'exam_date' => '2026-04-25',
                'exam_start_time' => '13:00:00',
                'status' => 'ready',
                'notes' => 'سيناريو نقص سعات - أكبر مادة في الجلسة.',
                'regular_students' => 78,
                'carry_students' => 12,
            ],
            [
                'key' => 'HMK-2026-04-25-13-00-PHYS',
                'subject_code' => 'HMK-PHYS-101',
                'academic_year' => '2026-2027',
                'semester' => 'الفصل الثاني',
                'exam_date' => '2026-04-25',
                'exam_start_time' => '13:00:00',
                'status' => 'ready',
                'notes' => 'سيناريو نقص سعات - ستختلط مع أكثر من مادة.',
                'regular_students' => 60,
                'carry_students' => 10,
            ],
            [
                'key' => 'HMK-2026-04-25-13-00-CONT',
                'subject_code' => 'HMK-CONT-401',
                'academic_year' => '2026-2027',
                'semester' => 'الفصل الثاني',
                'exam_date' => '2026-04-25',
                'exam_start_time' => '13:00:00',
                'status' => 'ready',
                'notes' => 'سيناريو نقص سعات - مادة متوسطة لاختبار خلط 3 مواد.',
                'regular_students' => 38,
                'carry_students' => 7,
            ],
            [
                'key' => 'HMK-2026-04-25-13-00-NET',
                'subject_code' => 'HMK-NET-401',
                'academic_year' => '2026-2027',
                'semester' => 'الفصل الثاني',
                'exam_date' => '2026-04-25',
                'exam_start_time' => '13:00:00',
                'status' => 'ready',
                'notes' => 'سيناريو نقص سعات - مادة صغيرة مساعدة.',
                'regular_students' => 24,
                'carry_students' => 6,
            ],
            [
                'key' => 'HMK-2026-04-25-13-00-SIGN',
                'subject_code' => 'HMK-SIGN-301',
                'academic_year' => '2026-2027',
                'semester' => 'الفصل الثاني',
                'exam_date' => '2026-04-25',
                'exam_start_time' => '13:00:00',
                'status' => 'ready',
                'notes' => 'سيناريو نقص سعات - ستبقى منها حالات غير موزعة لاختبار التحذيرات.',
                'regular_students' => 20,
                'carry_students' => 5,
            ],

            [
                'key' => 'HMK-2026-04-27-09-00-CIRC',
                'subject_code' => 'HMK-CIRC-201',
                'academic_year' => '2025-2026',
                'semester' => 'الفصل الصيفي',
                'exam_date' => '2026-04-27',
                'exam_start_time' => '09:00:00',
                'status' => 'ready',
                'notes' => 'جلسة إضافية لاختبار فصل المواعيد المختلفة.',
                'regular_students' => 52,
                'carry_students' => 8,
            ],
            [
                'key' => 'HMK-2026-04-27-09-00-DIGI',
                'subject_code' => 'HMK-DIGI-301',
                'academic_year' => '2025-2026',
                'semester' => 'الفصل الصيفي',
                'exam_date' => '2026-04-27',
                'exam_start_time' => '09:00:00',
                'status' => 'ready',
                'notes' => 'جلسة إضافية لاختبار فصل المواعيد المختلفة.',
                'regular_students' => 38,
                'carry_students' => 7,
            ],

            [
                'key' => 'CIV-2026-04-26-09-00-MECH',
                'subject_code' => 'CIV-MECH-101',
                'academic_year' => '2026-2027',
                'semester' => 'الفصل الثاني',
                'exam_date' => '2026-04-26',
                'exam_start_time' => '09:00:00',
                'status' => 'ready',
                'notes' => 'جلسة مدنية رئيسية لاختبار التوزيع ضمن كلية أخرى.',
                'regular_students' => 66,
                'carry_students' => 9,
            ],
            [
                'key' => 'CIV-2026-04-26-09-00-MATS',
                'subject_code' => 'CIV-MATS-201',
                'academic_year' => '2026-2027',
                'semester' => 'الفصل الثاني',
                'exam_date' => '2026-04-26',
                'exam_start_time' => '09:00:00',
                'status' => 'ready',
                'notes' => 'جلسة مدنية رئيسية.',
                'regular_students' => 51,
                'carry_students' => 9,
            ],
            [
                'key' => 'CIV-2026-04-26-09-00-HYDR',
                'subject_code' => 'CIV-HYDR-301',
                'academic_year' => '2026-2027',
                'semester' => 'الفصل الثاني',
                'exam_date' => '2026-04-26',
                'exam_start_time' => '09:00:00',
                'status' => 'ready',
                'notes' => 'جلسة مدنية رئيسية.',
                'regular_students' => 47,
                'carry_students' => 8,
            ],
            [
                'key' => 'CIV-2026-04-26-09-00-ROAD',
                'subject_code' => 'CIV-ROAD-401',
                'academic_year' => '2026-2027',
                'semester' => 'الفصل الثاني',
                'exam_date' => '2026-04-26',
                'exam_start_time' => '09:00:00',
                'status' => 'ready',
                'notes' => 'جلسة مدنية رئيسية.',
                'regular_students' => 33,
                'carry_students' => 7,
            ],
            [
                'key' => 'CIV-2026-04-28-12-00-SURV',
                'subject_code' => 'CIV-SURV-101',
                'academic_year' => '2025-2026',
                'semester' => 'الفصل الصيفي',
                'exam_date' => '2026-04-28',
                'exam_start_time' => '12:00:00',
                'status' => 'ready',
                'notes' => 'جلسة منفصلة لاختبار اختلاف الوقت.',
                'regular_students' => 44,
                'carry_students' => 6,
            ],
            [
                'key' => 'CIV-2026-04-28-12-00-CONC',
                'subject_code' => 'CIV-CONC-401',
                'academic_year' => '2025-2026',
                'semester' => 'الفصل الصيفي',
                'exam_date' => '2026-04-28',
                'exam_start_time' => '12:00:00',
                'status' => 'ready',
                'notes' => 'جلسة منفصلة لاختبار اختلاف الوقت.',
                'regular_students' => 58,
                'carry_students' => 7,
            ],

            [
                'key' => 'ARC-2026-04-26-13-00-DRAW',
                'subject_code' => 'ARC-DRAW-101',
                'academic_year' => '2026-2027',
                'semester' => 'الفصل الثاني',
                'exam_date' => '2026-04-26',
                'exam_start_time' => '13:00:00',
                'status' => 'ready',
                'notes' => 'جلسة معمارية لاختبار التوزيع ضمن الكلية الثالثة.',
                'regular_students' => 36,
                'carry_students' => 9,
            ],
            [
                'key' => 'ARC-2026-04-26-13-00-MATL',
                'subject_code' => 'ARC-MATL-201',
                'academic_year' => '2026-2027',
                'semester' => 'الفصل الثاني',
                'exam_date' => '2026-04-26',
                'exam_start_time' => '13:00:00',
                'status' => 'ready',
                'notes' => 'جلسة معمارية لاختبار التوزيع ضمن الكلية الثالثة.',
                'regular_students' => 29,
                'carry_students' => 6,
            ],
            [
                'key' => 'ARC-2026-04-26-13-00-DES3',
                'subject_code' => 'ARC-DES3-301',
                'academic_year' => '2026-2027',
                'semester' => 'الفصل الثاني',
                'exam_date' => '2026-04-26',
                'exam_start_time' => '13:00:00',
                'status' => 'ready',
                'notes' => 'جلسة معمارية لاختبار التوزيع ضمن الكلية الثالثة.',
                'regular_students' => 24,
                'carry_students' => 4,
            ],
            [
                'key' => 'ARC-2026-04-29-09-00-URBN',
                'subject_code' => 'ARC-URBN-401',
                'academic_year' => '2025-2026',
                'semester' => 'الفصل الصيفي',
                'exam_date' => '2026-04-29',
                'exam_start_time' => '09:00:00',
                'status' => 'ready',
                'notes' => 'جلسة مختلفة التاريخ لاختبار الفصل بين الأيام.',
                'regular_students' => 41,
                'carry_students' => 7,
            ],
            [
                'key' => 'ARC-2026-04-29-09-00-BIM',
                'subject_code' => 'ARC-BIM-401',
                'academic_year' => '2025-2026',
                'semester' => 'الفصل الصيفي',
                'exam_date' => '2026-04-29',
                'exam_start_time' => '09:00:00',
                'status' => 'ready',
                'notes' => 'جلسة مختلفة التاريخ لاختبار الفصل بين الأيام.',
                'regular_students' => 28,
                'carry_students' => 7,
            ],
        ];
    }
}
