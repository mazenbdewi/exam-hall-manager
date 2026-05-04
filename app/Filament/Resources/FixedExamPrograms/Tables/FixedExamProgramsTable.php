<?php

namespace App\Filament\Resources\FixedExamPrograms\Tables;

use App\Filament\Resources\FixedExamPrograms\FixedExamProgramResource;
use App\Models\FixedExamProgram;
use App\Support\ExamCollegeScope;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class FixedExamProgramsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('fixed_at', 'desc')
            ->columns([
                TextColumn::make('college_name')
                    ->label('الكلية')
                    ->visible(fn (): bool => ExamCollegeScope::isSuperAdmin())
                    ->searchable()
                    ->sortable(),
                TextColumn::make('department_name')
                    ->label('القسم')
                    ->placeholder('كل الأقسام')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('semester')
                    ->label('الفصل الدراسي')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('academic_year')
                    ->label('العام الدراسي')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('fixed_at')
                    ->label('تاريخ التثبيت')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('fixer.name')
                    ->label('ثبت بواسطة')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => FixedExamProgramResource::statusLabel($state))
                    ->color(fn (?string $state): string => $state === 'archived' ? 'gray' : 'success')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('college_id')
                    ->label('الكلية')
                    ->relationship('college', 'name')
                    ->visible(fn (): bool => ExamCollegeScope::isSuperAdmin()),
                SelectFilter::make('department_id')
                    ->label('القسم')
                    ->relationship('department', 'name'),
                SelectFilter::make('status')
                    ->label('الحالة')
                    ->options([
                        'fixed' => 'مثبت',
                        'archived' => 'مؤرشف',
                    ]),
                TrashedFilter::make(),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('عرض')
                    ->icon('heroicon-o-eye')
                    ->url(fn (FixedExamProgram $record): string => FixedExamProgramResource::getUrl('view', ['record' => $record])),
                Action::make('print')
                    ->label('طباعة')
                    ->icon('heroicon-o-printer')
                    ->url(fn (FixedExamProgram $record): string => route('filament.adminpanel.fixed-exam-programs.print', ['fixedExamProgram' => $record]))
                    ->openUrlInNewTab(),
                Action::make('archive')
                    ->label('أرشفة')
                    ->icon('heroicon-o-archive-box')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn (FixedExamProgram $record): bool => $record->status !== 'archived')
                    ->action(fn (FixedExamProgram $record): bool => $record->update(['status' => 'archived'])),
                DeleteAction::make()
                    ->label('حذف'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
