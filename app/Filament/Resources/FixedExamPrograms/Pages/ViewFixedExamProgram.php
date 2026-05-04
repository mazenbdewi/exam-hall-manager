<?php

namespace App\Filament\Resources\FixedExamPrograms\Pages;

use App\Filament\Resources\FixedExamPrograms\FixedExamProgramResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\ViewRecord;

class ViewFixedExamProgram extends ViewRecord
{
    protected static string $resource = FixedExamProgramResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('print')
                ->label('طباعة البرنامج المثبت')
                ->icon('heroicon-o-printer')
                ->url(fn (): string => route('filament.adminpanel.fixed-exam-programs.print', ['fixedExamProgram' => $this->getRecord()]))
                ->openUrlInNewTab(),
            Action::make('archive')
                ->label('أرشفة')
                ->icon('heroicon-o-archive-box')
                ->color('gray')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->getRecord()->status !== 'archived')
                ->action(fn (): bool => $this->getRecord()->update(['status' => 'archived'])),
            DeleteAction::make()
                ->label('حذف'),
        ];
    }
}
