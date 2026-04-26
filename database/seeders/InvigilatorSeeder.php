<?php

namespace Database\Seeders;

use App\Enums\ExamHallType;
use App\Enums\InvigilationRole;
use App\Enums\InvigilatorDayPreference;
use App\Enums\InvigilatorDistributionPattern;
use App\Enums\StaffCategory;
use App\Models\College;
use App\Models\Invigilator;
use App\Models\InvigilatorDistributionSetting;
use App\Models\InvigilatorHallRequirement;
use Illuminate\Database\Seeder;

class InvigilatorSeeder extends Seeder
{
    public function run(): void
    {
        College::query()
            ->orderBy('id')
            ->get()
            ->each(function (College $college, int $collegeIndex): void {
                $this->seedSettings($college);
                $this->seedHallRequirements($college);
                $this->seedInvigilators($college, $collegeIndex + 1);
            });
    }

    protected function seedSettings(College $college): void
    {
        InvigilatorDistributionSetting::query()->updateOrCreate(
            ['college_id' => $college->id],
            [
                'default_max_assignments_per_invigilator' => 4,
                'allow_multiple_assignments_per_day' => false,
                'max_assignments_per_day' => 1,
                'distribution_pattern' => InvigilatorDistributionPattern::Balanced->value,
                'day_preference' => InvigilatorDayPreference::Balanced->value,
            ],
        );
    }

    protected function seedHallRequirements(College $college): void
    {
        $requirements = [
            ExamHallType::Amphitheater->value => [1, 1, 4, 1],
            ExamHallType::Large->value => [1, 1, 2, 0],
            ExamHallType::Small->value => [1, 0, 1, 0],
        ];

        foreach ($requirements as $hallType => [$heads, $secretaries, $regulars, $reserves]) {
            InvigilatorHallRequirement::query()->updateOrCreate(
                [
                    'college_id' => $college->id,
                    'hall_type' => $hallType,
                ],
                [
                    'hall_head_count' => $heads,
                    'secretary_count' => $secretaries,
                    'regular_count' => $regulars,
                    'reserve_count' => $reserves,
                ],
            );
        }
    }

    protected function seedInvigilators(College $college, int $collegeNumber): void
    {
        $rows = [
            ...$this->roleRows(InvigilationRole::HallHead, StaffCategory::Doctor, 5, [
                'د. أحمد العلي',
                'د. محمد الخطيب',
                'د. سامر الحسن',
                'د. رنا مصطفى',
                'د. لينا سليمان',
            ]),
            ...$this->roleRows(InvigilationRole::Secretary, StaffCategory::AdminEmployee, 5, [
                'أ. خالد محمود',
                'أ. رامي يوسف',
                'أ. مازن عيسى',
                'أ. ناديا منصور',
                'أ. هالة ديب',
            ]),
            ...$this->roleRows(InvigilationRole::Regular, StaffCategory::AdminEmployee, 10, [
                'أ. سارة إبراهيم',
                'أ. لين حسن',
                'أ. نور أحمد',
                'أ. يامن صالح',
                'أ. ميساء خليل',
                'أ. علي حمود',
                'أ. ريم عثمان',
                'أ. فادي عباس',
                'أ. هبة ناصر',
                'أ. كنان مراد',
            ]),
            ...$this->roleRows(InvigilationRole::Regular, StaffCategory::MasterStudent, 10, [
                'م. سارة إبراهيم',
                'م. لين حسن',
                'م. نور أحمد',
                'م. عمر شاهين',
                'م. دانا يوسف',
                'م. تيم حسن',
                'م. رشا خضور',
                'م. وسام علي',
                'م. غنى ديب',
                'م. يارا سليمان',
            ]),
            ...$this->roleRows(InvigilationRole::Reserve, StaffCategory::AdminEmployee, 5, [
                'أ. محمود حيدر',
                'أ. باسل كنعان',
                'أ. بيان خليل',
                'أ. نسرين حمزة',
                'أ. غسان فارس',
            ]),
        ];

        foreach ($rows as $index => $row) {
            $phone = $this->phoneFor($collegeNumber, $index + 1);

            Invigilator::query()->updateOrCreate(
                [
                    'college_id' => $college->id,
                    'phone' => $phone,
                ],
                [
                    'name' => $row['name'],
                    'staff_category' => $row['staff_category']->value,
                    'invigilation_role' => $row['invigilation_role']->value,
                    'max_assignments' => $row['invigilation_role'] === InvigilationRole::Reserve ? 2 : null,
                    'max_assignments_per_day' => 1,
                    'workload_reduction_percentage' => $this->demoReductionFor($index),
                    'is_active' => true,
                    'notes' => 'بيانات تجريبية قابلة للتعديل.',
                ],
            );
        }
    }

    protected function roleRows(InvigilationRole $role, StaffCategory $category, int $count, array $names): array
    {
        return collect($names)
            ->take($count)
            ->map(fn (string $name): array => [
                'name' => $name,
                'staff_category' => $category,
                'invigilation_role' => $role,
            ])
            ->all();
    }

    protected function phoneFor(int $collegeNumber, int $sequence): string
    {
        return '09'.str_pad((string) (($collegeNumber * 1000000) + $sequence), 8, '0', STR_PAD_LEFT);
    }

    protected function demoReductionFor(int $index): int
    {
        return match (true) {
            $index % 17 === 0 => 50,
            $index % 11 === 0 => 25,
            $index % 29 === 0 => 100,
            default => 0,
        };
    }
}
