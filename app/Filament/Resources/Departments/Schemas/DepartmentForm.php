<?php

namespace App\Filament\Resources\Departments\Schemas;

use App\Models\College;
use App\Models\Department;
use App\Support\ExamCollegeScope;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class DepartmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('exam.sections.department_details'))
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
                            ->hidden(fn (): bool => ! ExamCollegeScope::isSuperAdmin()),
                        TextInput::make('name')
                            ->label(__('exam.fields.name'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('code')
                            ->label(__('exam.fields.code'))
                            ->maxLength(255),
                        TextInput::make('student_number_prefix')
                            ->label('ترميز الأرقام الجامعية')
                            ->helperText('مثال: 11 أو 12. يستخدم كبادئة للرقم الجامعي عند تعديل أرقام طلاب القوائم.')
                            ->maxLength(20)
                            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? trim($state) : null)
                            ->required(fn (Get $get): bool => self::collegeUsesStudentNumberPrefix($get('college_id')))
                            ->rules(fn (Get $get, ?Department $record): array => [
                                function (string $attribute, mixed $value, \Closure $fail) use ($get, $record): void {
                                    if (blank($value)) {
                                        return;
                                    }

                                    $exists = Department::query()
                                        ->where('college_id', self::collegeIdFromForm($get('college_id')))
                                        ->where('student_number_prefix', trim((string) $value))
                                        ->when($record?->getKey(), fn (Builder $query, int $id): Builder => $query->whereKeyNot($id))
                                        ->exists();

                                    if ($exists) {
                                        $fail('ترميز الأرقام الجامعية مستخدم مسبقاً ضمن نفس الكلية.');
                                    }
                                },
                            ]),
                        Toggle::make('is_active')
                            ->label(__('exam.fields.is_active'))
                            ->default(true)
                            ->inline(false),
                    ]),
            ]);
    }

    protected static function collegeUsesStudentNumberPrefix(mixed $collegeId): bool
    {
        $collegeId = self::collegeIdFromForm($collegeId);

        if (! $collegeId) {
            return false;
        }

        return (bool) College::query()->whereKey($collegeId)->value('enable_department_student_number_prefix');
    }

    protected static function collegeIdFromForm(mixed $collegeId): ?int
    {
        if (filled($collegeId)) {
            return (int) $collegeId;
        }

        return ExamCollegeScope::currentCollegeId();
    }
}
