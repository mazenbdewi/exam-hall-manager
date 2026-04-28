<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Services\AuditLogService;
use App\Support\ExamCollegeScope;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected string $roleName;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        [$this->roleName, $collegeId] = ExamCollegeScope::enforceUserRoleAndCollege(
            $data['role_name'] ?? '',
            $data['college_id'] ?? null,
        );

        $data['college_id'] = $collegeId;

        unset($data['role_name'], $data['password_confirmation']);

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
    }
}
