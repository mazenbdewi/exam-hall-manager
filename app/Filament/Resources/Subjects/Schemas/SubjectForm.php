<?php

namespace App\Filament\Resources\Subjects\Schemas;

use App\Models\Department;
use App\Support\ExamCollegeScope;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class SubjectForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('exam.sections.subject_details'))
                    ->columns(2)
                    ->schema([
                        Select::make('college_id')
                            ->label(__('exam.fields.college'))
                            ->relationship(
                                name: 'college',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query) => $query->orderBy('name'),
                            )
                            ->searchable()
                            ->preload()
                            ->required()
                            ->default(fn (): ?int => ExamCollegeScope::currentCollegeId())
                            ->live()
                            ->afterStateUpdated(function (Set $set): void {
                                $set('department_id', null);
                                $set('sharedDepartments', []);
                            })
                            ->hidden(fn (): bool => ! ExamCollegeScope::isSuperAdmin()),
                        Select::make('department_id')
                            ->label(__('exam.fields.department'))
                            ->relationship(
                                name: 'department',
                                titleAttribute: 'name',
                                modifyQueryUsing: function (Builder $query, Get $get): Builder {
                                    $collegeId = ExamCollegeScope::isSuperAdmin()
                                        ? $get('college_id')
                                        : ExamCollegeScope::currentCollegeId();

                                    return $query
                                        ->when($collegeId, fn (Builder $departmentQuery) => $departmentQuery->where('college_id', $collegeId))
                                        ->orderBy('name');
                                },
                            )
                            ->searchable()
                            ->preload()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set, Get $get, mixed $state): void {
                                if ((bool) $get('is_shared_subject') && filled($state)) {
                                    $set('sharedDepartments', [(string) $state]);
                                }
                            }),
                        Select::make('study_level_id')
                            ->label(__('exam.fields.study_level'))
                            ->relationship(
                                name: 'studyLevel',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query) => $query
                                    ->where('is_active', true)
                                    ->orderBy('sort_order')
                                    ->orderBy('name'),
                            )
                            ->searchable()
                            ->preload()
                            ->required(),
                        TextInput::make('name')
                            ->label(__('exam.fields.name'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('code')
                            ->label(__('exam.fields.code'))
                            ->maxLength(255),
                        Toggle::make('is_active')
                            ->label(__('exam.fields.is_active'))
                            ->default(true)
                            ->inline(false),
                        Toggle::make('is_drawing_subject')
                            ->label(__('exam.fields.is_drawing_subject'))
                            ->helperText('فعّل هذا الخيار إذا كانت المادة تحتاج إلى مرسم - مخبر عند التوزيع.')
                            ->default(false)
                            ->dehydrated(true)
                            ->inline(false),
                        Toggle::make('is_shared_subject')
                            ->label('مادة مشتركة بين عدة أقسام')
                            ->helperText('فعّل هذا الخيار إذا كانت المادة مشتركة بين أكثر من قسم أو يدرسها طلاب من عدة أقسام.')
                            ->default(false)
                            ->live()
                            ->afterStateUpdated(function (Set $set, Get $get, bool $state): void {
                                if (! $state) {
                                    $set('sharedDepartments', []);

                                    return;
                                }

                                if (filled($get('department_id'))) {
                                    $set('sharedDepartments', [(string) $get('department_id')]);
                                }
                            })
                            ->inline(false),
                        Select::make('sharedDepartments')
                            ->label('الأقسام المشتركة في هذه المادة')
                            ->relationship(
                                name: 'sharedDepartments',
                                titleAttribute: 'name',
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
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->live()
                            ->visible(fn (Get $get): bool => (bool) $get('is_shared_subject'))
                            ->required(fn (Get $get): bool => (bool) $get('is_shared_subject'))
                            ->minItems(fn (Get $get): ?int => (bool) $get('is_shared_subject') ? 2 : null)
                            ->helperText('اختر فقط الأقسام التي تدرس هذه المادة فعليًا حتى لا يتم إنشاء تعارضات غير صحيحة أثناء توليد البرنامج.')
                            ->rule(function (Get $get): \Closure {
                                return function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                                    if (! (bool) $get('is_shared_subject')) {
                                        return;
                                    }

                                    $departmentIds = collect($value)
                                        ->filter()
                                        ->map(fn (mixed $departmentId): int => (int) $departmentId)
                                        ->unique()
                                        ->values();

                                    if ($departmentIds->count() < 2) {
                                        $fail('يجب اختيار قسمين على الأقل للمادة المشتركة.');

                                        return;
                                    }

                                    $collegeId = ExamCollegeScope::isSuperAdmin()
                                        ? $get('college_id')
                                        : ExamCollegeScope::currentCollegeId();

                                    $validCount = Department::query()
                                        ->whereIn('id', $departmentIds)
                                        ->when($collegeId, fn (Builder $query): Builder => $query->where('college_id', $collegeId))
                                        ->count();

                                    if ($validCount !== $departmentIds->count()) {
                                        $fail('كل الأقسام المشتركة يجب أن تكون من نفس كلية المادة.');
                                    }
                                };
                            }),
                        Select::make('shared_subject_scheduling_mode')
                            ->label('طريقة جدولة المادة المشتركة')
                            ->options([
                                'all_departments_together' => 'توزيعها لكافة الأقسام في نفس الموعد',
                                'separate_departments' => 'جدولة كل قسم في يوم مختلف إن أمكن',
                                'auto' => 'تلقائي حسب التعارضات والسعة',
                            ])
                            ->default('auto')
                            ->required()
                            ->visible(fn (Get $get): bool => (bool) $get('is_shared_subject')),
                        Toggle::make('is_core_subject')
                            ->label('مادة أساسية')
                            ->helperText('فعّل هذا الخيار إذا كانت المادة أساسية ويفضل وضعها في الفترة الصباحية.')
                            ->default(false)
                            ->live()
                            ->afterStateUpdated(function (Set $set, bool $state): void {
                                if ($state) {
                                    $set('preferred_exam_period', 'morning');
                                }
                            })
                            ->inline(false),
                        Select::make('preferred_exam_period')
                            ->label('الفترة المفضلة للمادة')
                            ->options([
                                'morning' => 'صباحية',
                                'mid_day' => 'وسطى',
                                'evening' => 'مسائية',
                                'none' => 'لا تفضيل',
                            ])
                            ->default('none')
                            ->required(),
                        Select::make('core_subject_priority')
                            ->label('درجة إلزام الفترة المفضلة')
                            ->options([
                                'preference' => 'تفضيل فقط',
                                'enforce_if_possible' => 'إلزام إن أمكن',
                                'strict' => 'إلزام صارم',
                            ])
                            ->default('preference')
                            ->required(),
                    ])->columnSpanFull(),
            ]);
    }
}
