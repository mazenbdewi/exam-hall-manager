<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
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
        $this->record->syncRoles([$this->roleName]);
    }
}
