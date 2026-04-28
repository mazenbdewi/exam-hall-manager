<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Services\AuditLogService;
use App\Support\ExamCollegeScope;
use App\Support\RoleNames;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Hash;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected string $roleName = RoleNames::ADMIN;

    protected bool $passwordWasChanged = false;

    protected bool $pinWasChanged = false;

    protected bool $pinWasEnabled = false;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['role_name'] = $this->record->roles->first()?->name ?? RoleNames::ADMIN;

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->passwordWasChanged = filled($data['password'] ?? null);
        $this->pinWasChanged = filled($data['security_pin'] ?? null);
        $this->pinWasEnabled = (bool) $this->record->security_pin_enabled;

        [$this->roleName, $collegeId] = ExamCollegeScope::enforceUserRoleAndCollege(
            $data['role_name'] ?? '',
            $data['college_id'] ?? null,
        );

        $data['college_id'] = $collegeId;

        if ($this->pinWasChanged) {
            $data['security_pin_hash'] = Hash::make((string) $data['security_pin']);
            $data['security_pin_set_at'] = now();
        }

        unset($data['role_name'], $data['password_confirmation'], $data['security_pin'], $data['security_pin_confirmation']);

        return $data;
    }

    protected function afterSave(): void
    {
        $oldRoles = $this->record->roles()->pluck('name')->all();

        $this->record->syncRoles([$this->roleName]);

        if ($oldRoles !== [$this->roleName]) {
            app(AuditLogService::class)->log(
                action: 'user_role.updated',
                module: 'users',
                auditable: $this->record,
                description: 'تعديل صلاحيات المستخدم',
                oldValues: [
                    'roles' => $oldRoles,
                ],
                newValues: [
                    'roles' => [$this->roleName],
                ],
            );
        }

        if ($this->passwordWasChanged) {
            app(AuditLogService::class)->log(
                action: 'password.changed',
                module: 'users',
                auditable: $this->record,
                description: 'تغيير كلمة السر',
            );
        }

        if (! $this->pinWasEnabled && $this->record->security_pin_enabled) {
            app(AuditLogService::class)->log(
                action: 'security_pin.enabled',
                module: 'security',
                auditable: $this->record,
                description: 'تم تفعيل رمز الدخول الإضافي',
            );
        }

        if ($this->pinWasEnabled && ! $this->record->security_pin_enabled) {
            app(AuditLogService::class)->log(
                action: 'security_pin.disabled',
                module: 'security',
                auditable: $this->record,
                description: 'تم تعطيل رمز الدخول الإضافي',
            );
        }

        if ($this->pinWasChanged) {
            app(AuditLogService::class)->log(
                action: 'security_pin.changed',
                module: 'security',
                auditable: $this->record,
                description: 'تم تغيير رمز الدخول الإضافي',
            );
        }
    }
}
