<?php

namespace App\Filament\Resources\InvigilatorPublicLookupSettings\Schemas;

use App\Support\ExamCollegeScope;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class InvigilatorPublicLookupSettingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('exam.sections.invigilator_public_lookup_settings'))
                    ->description(__('exam.helpers.invigilator_public_lookup_settings'))
                    ->icon(Heroicon::OutlinedEye)
                    ->iconColor('info')
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
                        Toggle::make('show_all_invigilator_assignments')
                            ->label(__('exam.fields.show_all_invigilator_assignments'))
                            ->helperText(__('exam.helpers.show_all_invigilator_assignments'))
                            ->default(false)
                            ->inline(false)
                            ->columnSpanFull(),
                        Select::make('visibility_before_minutes')
                            ->label(__('exam.fields.visibility_before_minutes'))
                            ->helperText(__('exam.helpers.visibility_before_minutes'))
                            ->options([
                                30 => __('exam.visibility_before_options.30'),
                                60 => __('exam.visibility_before_options.60'),
                                120 => __('exam.visibility_before_options.120'),
                                180 => __('exam.visibility_before_options.180'),
                            ])
                            ->default(60)
                            ->native(false)
                            ->required(),
                        Select::make('visibility_after_minutes')
                            ->label(__('exam.fields.visibility_after_minutes'))
                            ->helperText(__('exam.helpers.visibility_after_minutes'))
                            ->options([
                                60 => 'ساعة',
                                120 => 'ساعتان',
                                180 => 'ثلاث ساعات',
                                240 => 'أربع ساعات',
                            ])
                            ->default(180)
                            ->native(false)
                            ->required(),
                    ]),
            ]);
    }
}
