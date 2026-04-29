<?php

namespace Database\Seeders;

use App\Support\RoleNames;
use App\Support\ShieldPermission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        config([
            'permission.cache.store' => 'array',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $exitCode = Artisan::call('shield:generate', [
            '--all' => true,
            '--option' => 'policies_and_permissions',
            '--ignore-existing-policies' => true,
            '--panel' => 'adminpanel',
        ]);

        if ($exitCode !== 0) {
            throw new RuntimeException('Shield permission generation failed: '.Artisan::output());
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->ensureCustomPermissions();

        $superAdminRole = Role::findOrCreate(RoleNames::SUPER_ADMIN, 'web');
        $adminRole = Role::findOrCreate(RoleNames::ADMIN, 'web');

        $allPermissions = Permission::query()->pluck('name');

        $superAdminRole->syncPermissions($allPermissions);
        $adminRole->syncPermissions($this->adminPermissions());

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function adminPermissions(): Collection
    {
        $fullAccessResources = [
            'Department',
            'ExamHall',
            'Subject',
            'SubjectExamRoster',
            'SubjectExamOffering',
            'User',
            'Invigilator',
            'InvigilatorDistributionSetting',
            'InvigilatorHallRequirement',
            'InvigilatorAssignment',
            'StudentPublicLookupSetting',
        ];

        $viewOnlyResources = [
            'StudyLevel',
            'AcademicYear',
            'Semester',
        ];

        $fullAccessActions = [
            'viewAny',
            'view',
            'create',
            'update',
            'delete',
            'deleteAny',
            'restore',
            'restoreAny',
            'forceDelete',
            'forceDeleteAny',
            'replicate',
            'reorder',
            'import',
            'run',
            'export',
        ];

        $viewOnlyActions = [
            'viewAny',
            'view',
        ];

        return collect($fullAccessResources)
            ->flatMap(fn (string $resource): array => collect($fullAccessActions)
                ->map(fn (string $action): string => ShieldPermission::resource($action, $resource))
                ->all())
            ->merge(
                collect($viewOnlyResources)
                    ->flatMap(fn (string $resource): array => collect($viewOnlyActions)
                        ->map(fn (string $action): string => ShieldPermission::resource($action, $resource))
                        ->all()),
            )
            ->merge($this->customPermissions())
            ->filter(fn (string $permission): bool => Permission::query()->where('name', $permission)->exists())
            ->values();
    }

    protected function ensureCustomPermissions(): void
    {
        collect([...$this->customPermissions(), ...$this->auditPermissions()])
            ->each(fn (string $permission): Permission => Permission::findOrCreate($permission, 'web'));
    }

    protected function customPermissions(): array
    {
        return [
            'view_invigilator_distribution',
            'run_invigilator_distribution',
            'rerun_invigilator_distribution',
            'export_invigilator_distribution',
            'view_invigilator_shortage_report',
            'view_exam_schedule_generator',
            'generate_exam_schedule_draft',
            'approve_exam_schedule_draft',
            'update_exam_schedule_draft',
            'export_exam_schedule_conflicts',
            ShieldPermission::resource('import', 'Invigilator'),
            ShieldPermission::resource('run', 'InvigilatorAssignment'),
            ShieldPermission::resource('export', 'InvigilatorAssignment'),
        ];
    }

    protected function auditPermissions(): array
    {
        return [
            'view_audit_log',
            'view_any_audit_log',
            'delete_audit_log',
            'export_audit_log',
        ];
    }
}
