<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            CollegeSeeder::class,
            DepartmentSeeder::class,
            StudyLevelSeeder::class,
            SemesterSeeder::class,
            AcademicYearSeeder::class,
            HallSettingSeeder::class,
            SystemSettingsSeeder::class,
            RolesAndUsersSeeder::class,
            SubjectSeeder::class,
            SubjectExamOfferingSeeder::class,
            ExamStudentSeeder::class,
            ExamHallSeeder::class,
            InvigilatorSeeder::class,
        ]);
    }
}
