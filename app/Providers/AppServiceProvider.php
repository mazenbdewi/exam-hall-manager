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
use App\Models\SubjectExamRoster;
use App\Models\SubjectExamRosterStudent;
use App\Models\SystemSetting;
use App\Models\User;
use App\Observers\AuditModelObserver;
use App\Support\AdminPassword;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    private const BACKUP_ABILITIES = [
        'view-backup',
        'create-backup',
        'download-backup',
        'delete-backup',
        'restore-backup',
    ];

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
        $this->configureFilamentDateComponents();
        $this->configureBackupAuthorization();

        Password::defaults(fn () => AdminPassword::rule());

        $this->registerAuditObservers();

        FilamentTimezone::set('Asia/Damascus');

    }

    protected function configureFilamentDateComponents(): void
    {
        DatePicker::configureUsing(function (DatePicker $component): void {
            $component
                ->native(false)
                ->displayFormat('d/m/Y')
                ->format('Y-m-d')
                ->placeholder('dd/mm/yyyy');
        });

        DateTimePicker::configureUsing(function (DateTimePicker $component): void {
            $component
                ->native(false)
                ->displayFormat('d/m/Y H:i')
                ->format('Y-m-d H:i:s')
                ->seconds(false)
                ->placeholder('dd/mm/yyyy hh:mm');
        });
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
            SubjectExamRoster::class,
            SubjectExamRosterStudent::class,
            SystemSetting::class,
            StudentPublicLookupSetting::class,
        ])->each(function (string $model): void {
            if (is_subclass_of($model, Model::class)) {
                $model::observe(AuditModelObserver::class);
            }
        });
    }

    protected function configureBackupAuthorization(): void
    {
        Gate::before(function (User $user, string $ability): ?bool {
            if (! in_array($ability, self::BACKUP_ABILITIES, true)) {
                return null;
            }

            return $user->isSuperAdmin() ? true : false;
        });
    }
}
