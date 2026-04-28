<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Services\AuditLogService;
use App\Support\ExamCollegeScope;
use App\Support\RoleNames;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected string $roleName = RoleNames::ADMIN;

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
        [$this->roleName, $collegeId] = ExamCollegeScope::enforceUserRoleAndCollege(
            $data['role_name'] ?? '',
            $data['college_id'] ?? null,
        );

        $data['college_id'] = $collegeId;

        unset($data['role_name'], $data['password_confirmation']);

        return $data;
    }

    protected function afterSave(): void
    {
        $oldRoles = $this->record->roles()->pluck('name')->all();

        $this->record->syncRoles([$this->roleName]);

        if ($oldRoles === [$this->roleName]) {
            return;
        }

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
}
