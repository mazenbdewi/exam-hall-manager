<?php

namespace App\Filament\Resources\FixedExamPrograms;

use App\Filament\Resources\FixedExamPrograms\Pages\ListFixedExamPrograms;
use App\Filament\Resources\FixedExamPrograms\Pages\ViewFixedExamProgram;
use App\Filament\Resources\FixedExamPrograms\Tables\FixedExamProgramsTable;
use App\Models\FixedExamProgram;
use App\Support\ExamCollegeScope;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FixedExamProgramResource extends Resource
{
    protected static ?string $model = FixedExamProgram::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('بيانات البرنامج الثابت')
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('title')->label('العنوان')->columnSpanFull(),
                        TextEntry::make('college_name')->label('الكلية')->placeholder('—'),
                        TextEntry::make('department_name')->label('القسم')->placeholder('كل الأقسام'),
                        TextEntry::make('academic_year')->label('العام الدراسي'),
                        TextEntry::make('semester')->label('الفصل الدراسي'),
                        TextEntry::make('fixed_at')->label('تاريخ التثبيت')->dateTime('d/m/Y H:i'),
                        TextEntry::make('fixer.name')->label('ثبت بواسطة')->placeholder('—'),
                        TextEntry::make('status')
                            ->label('الحالة')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => static::statusLabel($state))
                            ->color(fn (?string $state): string => $state === 'archived' ? 'gray' : 'success'),
                        TextEntry::make('snapshot_subjects_count')
                            ->label('عدد المواد في النسخة')
                            ->state(fn (FixedExamProgram $record): int => count(data_get($record->snapshot_data, 'entries', []))),
                    ]),
                Section::make('ملاحظة')
                    ->columnSpanFull()
                    ->collapsed()
                    ->schema([
                        TextEntry::make('snapshot_notice')
                            ->label('')
                            ->state('هذه الصفحة تعرض بيانات وصفية فقط. صفحة الطباعة تقرأ جدول البرنامج من snapshot_data المحفوظة في قاعدة البيانات.'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return FixedExamProgramsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFixedExamPrograms::route('/'),
            'view' => ViewFixedExamProgram::route('/{record}'),
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('exam.navigation.reports_printing');
    }

    public static function getNavigationSort(): ?int
    {
        return 21;
    }

    public static function getNavigationLabel(): string
    {
        return 'البرامج الامتحانية المثبتة';
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getModelLabel(): string
    {
        return 'برنامج امتحاني مثبت';
    }

    public static function getPluralModelLabel(): string
    {
        return 'البرامج الامتحانية المثبتة';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return ExamCollegeScope::applyCollegeScope(
            parent::getEloquentQuery()->with(['college', 'department', 'fixer']),
        );
    }

    public static function statusLabel(?string $status): string
    {
        return match ($status) {
            'archived' => 'مؤرشف',
            default => 'مثبت',
        };
    }
}
