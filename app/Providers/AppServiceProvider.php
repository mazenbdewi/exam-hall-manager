<?php

namespace App\Providers;

use App\Models\ExamHall;
use App\Models\ExamScheduleDraft;
use App\Models\ExamScheduleDraftItem;
use App\Models\ExamStudent;
use App\Models\ExamStudentHallAssignment;
use App\Models\Invigilator;
use App\Models\InvigilatorAssignment;
use App\Models\InvigilatorDistributionSetting;
use App\Models\InvigilatorHallRequirement;
use App\Models\StudentPublicLookupSetting;
use App\Models\Subject;
use App\Models\SubjectExamOffering;
use App\Models\SystemSetting;
use App\Models\User;
use App\Observers\AuditModelObserver;
use App\Support\AdminPassword;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Carbon::setLocale(config('app.locale'));

        Password::defaults(fn () => AdminPassword::rule());

        $this->registerAuditObservers();
    }

    protected function registerAuditObservers(): void
    {
        collect([
            SubjectExamOffering::class,
            ExamScheduleDraft::class,
            ExamScheduleDraftItem::class,
            ExamHall::class,
            ExamStudent::class,
            ExamStudentHallAssignment::class,
            Invigilator::class,
            InvigilatorAssignment::class,
            InvigilatorDistributionSetting::class,
            InvigilatorHallRequirement::class,
            User::class,
            Role::class,
            Permission::class,
            Subject::class,
            SystemSetting::class,
            StudentPublicLookupSetting::class,
        ])->each(function (string $model): void {
            if (is_subclass_of($model, Model::class)) {
                $model::observe(AuditModelObserver::class);
            }
        });
    }
}
