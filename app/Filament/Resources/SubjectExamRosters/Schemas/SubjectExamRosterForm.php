<?php

namespace App\Filament\Resources\SubjectExamRosters\Schemas;

use App\Models\AcademicYear;
use App\Models\Subject;
use App\Support\ExamCollegeScope;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class SubjectExamRosterForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('بيانات قائمة طلاب المادة')
                ->description('هذه القوائم هي مصدر الطلاب قبل توليد البرنامج الامتحاني. يجب رفع الطلاب المستجدين والحملة وتحديد القوائم كجاهزة قبل توليد المسودة.')
                ->columns(2)
                ->schema([
                    Select::make('college_id')
                        ->label('الكلية')
                        ->relationship('college', 'name', modifyQueryUsing: fn (Builder $query) => $query->where('is_active', true)->orderBy('name'))
                        ->default(fn (): ?int => request()->integer('college_id') ?: ExamCollegeScope::currentCollegeId())
                        ->required()
                        ->searchable()
                        ->preload()
                        ->live()
                        ->afterStateUpdated(function (Set $set): void {
                            $set('department_id', null);
                            $set('subject_id', null);
                        })
                        ->hidden(fn (): bool => ! ExamCollegeScope::isSuperAdmin()),
                    Select::make('department_id')
                        ->label('القسم')
                        ->default(fn (): ?int => request()->integer('department_id') ?: null)
                        ->relationship(
                            'department',
                            'name',
                            modifyQueryUsing: function (Builder $query, Get $get): Builder {
                                $collegeId = ExamCollegeScope::isSuperAdmin()
                                    ? $get('college_id')
                                    : ExamCollegeScope::currentCollegeId();

                                return $query
                                    ->when($collegeId, fn (Builder $departmentQuery) => $departmentQuery->where('college_id', $collegeId))
                                    ->where('is_active', true)
                                    ->orderBy('name');
                            },
                        )
                        ->searchable()
                        ->preload()
                        ->nullable()
                        ->live()
                        ->afterStateUpdated(fn (Set $set) => $set('subject_id', null)),
                    Select::make('subject_id')
                        ->label('المادة')
                        ->default(fn (): ?int => request()->integer('subject_id') ?: null)
                        ->relationship(
                            'subject',
                            'name',
                            modifyQueryUsing: function (Builder $query, Get $get): Builder {
                                $collegeId = ExamCollegeScope::isSuperAdmin()
                                    ? $get('college_id')
                                    : ExamCollegeScope::currentCollegeId();

                                return $query
                                    ->with(['department', 'studyLevel'])
                                    ->when($collegeId, fn (Builder $subjectQuery) => $subjectQuery->where('college_id', $collegeId))
                                    ->when($get('department_id'), fn (Builder $subjectQuery, int $departmentId) => $subjectQuery->where('department_id', $departmentId))
                                    ->where('is_active', true)
                                    ->orderBy('name');
                            },
                        )
                        ->getOptionLabelFromRecordUsing(fn (Subject $record): string => collect([
                            $record->name,
                            $record->department?->name,
                            $record->studyLevel?->name,
                        ])->filter()->implode(' - '))
                        ->required()
                        ->searchable()
                        ->preload(),
                    Select::make('academic_year_id')
                        ->label('العام الدراسي')
                        ->relationship('academicYear', 'name', modifyQueryUsing: fn (Builder $query) => $query->where('is_active', true)->orderByDesc('name'))
                        ->default(fn (): ?int => AcademicYear::query()->where('is_current', true)->value('id'))
                        ->searchable()
                        ->preload(),
                    Select::make('semester_id')
                        ->label('الفصل الدراسي')
                        ->relationship('semester', 'name', modifyQueryUsing: fn (Builder $query) => $query->where('is_active', true)->orderBy('sort_order')->orderBy('name'))
                        ->searchable()
                        ->preload(),
                    Select::make('study_level_id')
                        ->label('السنة / المرحلة')
                        ->default(fn (): ?int => request()->integer('study_level_id') ?: null)
                        ->relationship('studyLevel', 'name', modifyQueryUsing: fn (Builder $query) => $query->where('is_active', true)->orderBy('sort_order')->orderBy('name'))
                        ->searchable()
                        ->preload(),
                    TextInput::make('name')
                        ->label('اسم القائمة')
                        ->maxLength(255),
                    Select::make('status')
                        ->label('الحالة')
                        ->options([
                            'draft' => 'مسودة',
                            'ready' => 'جاهزة',
                            'used' => 'مستخدمة',
                            'archived' => 'مؤرشفة',
                        ])
                        ->default('draft')
                        ->required(),
                    Textarea::make('notes')
                        ->label('ملاحظات')
                        ->rows(4)
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
