<?php

namespace App\Filament\Resources\SubjectExamRosters\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class RosterStudentsRelationManager extends RelationManager
{
    protected static string $relationship = 'rosterStudents';

    protected static ?string $modelLabel = 'طالب في قائمة المادة';

    protected static ?string $pluralModelLabel = 'طلاب قائمة المادة';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('student_number')
                ->label('الرقم الامتحاني')
                ->required()
                ->maxLength(255),
            TextInput::make('full_name')
                ->label('اسم الطالب')
                ->required()
                ->maxLength(255),
            Select::make('student_type')
                ->label('نوع الطالب')
                ->options([
                    'regular' => 'مستجد',
                    'carry' => 'حملة',
                ])
                ->default('regular')
                ->required(),
            Toggle::make('is_eligible')
                ->label('نشط')
                ->default(true),
            Textarea::make('notes')
                ->label('ملاحظات')
                ->rows(3)
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modelLabel('طالب في قائمة المادة')
            ->pluralModelLabel('طلاب قائمة المادة')
            ->recordTitleAttribute('full_name')
            ->defaultSort('student_number')
            ->columns([
                TextColumn::make('student_number')
                    ->label('الرقم الامتحاني')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('full_name')
                    ->label('اسم الطالب')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('student_type')
                    ->label('نوع الطالب')
                    ->formatStateUsing(fn (string $state): string => $state === 'carry' ? 'حملة' : 'مستجد')
                    ->badge(),
                IconColumn::make('is_eligible')
                    ->label('نشط')
                    ->boolean(),
                TextColumn::make('notes')
                    ->label('ملاحظات')
                    ->limit(40)
                    ->toggleable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->modelLabel('طالب في قائمة المادة')
                    ->pluralModelLabel('طلاب قائمة المادة')
                    ->label('إضافة طالب إلى القائمة')
                    ->modalHeading('إضافة طالب إلى القائمة')
                    ->modalSubmitActionLabel('إضافة')
                    ->createAnother(false),
            ])
            ->recordActions([
                EditAction::make()
                    ->modelLabel('طالب في قائمة المادة')
                    ->pluralModelLabel('طلاب قائمة المادة')
                    ->label('تعديل')
                    ->modalHeading('تعديل طالب في قائمة المادة')
                    ->modalSubmitActionLabel('حفظ التعديلات'),
                DeleteAction::make()
                    ->modelLabel('طالب في قائمة المادة')
                    ->pluralModelLabel('طلاب قائمة المادة')
                    ->label('حذف')
                    ->modalHeading('حذف طالب من القائمة')
                    ->modalSubmitActionLabel('حذف'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->modelLabel('طالب في قائمة المادة')
                        ->pluralModelLabel('طلاب قائمة المادة')
                        ->label('حذف المحدد')
                        ->modalHeading('حذف الطلاب المحددين')
                        ->modalSubmitActionLabel('حذف'),
                ]),
            ]);
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return 'الطلاب';
    }
}
