<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Services\AuditLogService;
use App\Support\ExamCollegeScope;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Hash;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected string $roleName;

    protected bool $passwordWasCreated = false;

    protected bool $pinWasCreated = false;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        [$this->roleName, $collegeId] = ExamCollegeScope::enforceUserRoleAndCollege(
            $data['role_name'] ?? '',
            $data['college_id'] ?? null,
        );

        $data['college_id'] = $collegeId;
        $this->passwordWasCreated = filled($data['password'] ?? null);

        if (filled($data['security_pin'] ?? null)) {
            $data['security_pin_hash'] = Hash::make((string) $data['security_pin']);
            $data['security_pin_set_at'] = now();
            $this->pinWasCreated = true;
        }

        unset($data['role_name'], $data['password_confirmation'], $data['security_pin'], $data['security_pin_confirmation']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->record->syncRoles([$this->roleName]);

        app(AuditLogService::class)->log(
            action: 'user_role.assigned',
            module: 'users',
            auditable: $this->record,
            description: 'تعديل صلاحيات المستخدم',
            newValues: [
                'roles' => [$this->roleName],
            ],
        );

        if ($this->passwordWasCreated) {
            app(AuditLogService::class)->log(
                action: 'password.created_by_admin',
                module: 'users',
                auditable: $this->record,
                description: 'إنشاء كلمة سر بواسطة المدير',
            );
        }

        if ($this->record->security_pin_enabled) {
            app(AuditLogService::class)->log(
                action: 'security_pin.enabled',
                module: 'security',
                auditable: $this->record,
                description: 'تم تفعيل رمز الدخول الإضافي',
            );
        }

        if ($this->pinWasCreated) {
            app(AuditLogService::class)->log(
                action: 'security_pin.changed',
                module: 'security',
                auditable: $this->record,
                description: 'تم تغيير رمز الدخول الإضافي',
            );
        }
    }
}
