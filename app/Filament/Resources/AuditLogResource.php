<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AuditLogResource\Pages\ListAuditLogs;
use App\Models\AuditLog;
use App\Models\College;
use App\Models\User;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $recordTitleAttribute = 'action';

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([10, 25, 50, 100])
            ->columns([
                TextColumn::make('created_at')
                    ->label('التاريخ والوقت')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('user_name')
                    ->label('المستخدم')
                    ->placeholder('-')
                    ->searchable(['user_name', 'user_email']),
                TextColumn::make('user.college.name')
                    ->label('الكلية')
                    ->placeholder('-'),
                TextColumn::make('action')
                    ->label('العملية')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('module')
                    ->label('القسم')
                    ->placeholder('-')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('description')
                    ->label('الوصف')
                    ->limit(50)
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::statusLabel($state))
                    ->color(fn (?string $state): string => match ($state) {
                        'failed' => 'danger',
                        'warning' => 'warning',
                        default => 'success',
                    })
                    ->sortable(),
                TextColumn::make('ip_address')
                    ->label('عنوان IP')
                    ->placeholder('-')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('url')
                    ->label('الرابط')
                    ->limit(45)
                    ->placeholder('-')
                    ->searchable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('user_college_id')
                    ->label('الكلية')
                    ->options(fn (): array => College::query()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['value'] ?? null, fn (Builder $query, string $collegeId): Builder => $query
                            ->whereHas('user', fn (Builder $userQuery): Builder => $userQuery->where('college_id', $collegeId))))
                    ->searchable()
                    ->preload(),
                SelectFilter::make('user_id')
                    ->label('المستخدم')
                    ->options(fn ($livewire): array => self::userFilterOptions($livewire))
                    ->getSearchResultsUsing(fn (string $search, $livewire): array => self::userFilterOptions($livewire, $search))
                    ->searchable()
                    ->preload(),
                SelectFilter::make('action')
                    ->label('العملية')
                    ->options(fn (): array => AuditLog::query()
                        ->whereNotNull('action')
                        ->distinct()
                        ->orderBy('action')
                        ->pluck('action', 'action')
                        ->all())
                    ->searchable(),
                SelectFilter::make('module')
                    ->label('القسم')
                    ->options(fn (): array => AuditLog::query()
                        ->whereNotNull('module')
                        ->distinct()
                        ->orderBy('module')
                        ->pluck('module', 'module')
                        ->all())
                    ->searchable(),
                SelectFilter::make('status')
                    ->label('الحالة')
                    ->options([
                        'success' => 'ناجح',
                        'failed' => 'فشل',
                        'warning' => 'تحذير',
                    ]),
                Filter::make('created_at')
                    ->label('تاريخ التنفيذ')
                    ->schema([
                        DatePicker::make('from')
                            ->label('التاريخ من')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->format('Y-m-d'),
                        DatePicker::make('until')
                            ->label('التاريخ إلى')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->format('Y-m-d'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date))),
                Filter::make('ip_address')
                    ->label('عنوان IP')
                    ->schema([
                        TextInput::make('value')
                            ->label('عنوان IP'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['value'] ?? null, fn (Builder $query, string $ip): Builder => $query->where('ip_address', 'like', "%{$ip}%"))),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('عرض التفاصيل')
                    ->modalHeading('تفاصيل سجل النشاط')
                    ->modalWidth('5xl')
                    ->schema(self::detailsSchema()),
            ]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('user.college');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditLogs::route('/'),
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('exam.navigation.settings');
    }

    public static function getNavigationSort(): ?int
    {
        return 92;
    }

    public static function getNavigationLabel(): string
    {
        return 'سجل النشاطات';
    }

    public static function getModelLabel(): string
    {
        return 'سجل نشاط';
    }

    public static function getPluralModelLabel(): string
    {
        return 'سجل النشاطات';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function detailsSchema(): array
    {
        return [
            Section::make('معلومات العملية')
                ->columns(3)
                ->schema([
                    TextEntry::make('created_at')->label('تاريخ التنفيذ')->dateTime('d/m/Y H:i'),
                    TextEntry::make('user_name')->label('المستخدم')->placeholder('-'),
                    TextEntry::make('user_email')->label('البريد الإلكتروني')->placeholder('-'),
                    TextEntry::make('action')->label('العملية')->badge(),
                    TextEntry::make('module')->label('القسم')->placeholder('-')->badge(),
                    TextEntry::make('status')->label('الحالة')->badge()->formatStateUsing(fn (?string $state): string => self::statusLabel($state)),
                    TextEntry::make('auditable_type')->label('نوع السجل')->placeholder('-')->columnSpan(2),
                    TextEntry::make('auditable_id')->label('معرف السجل')->placeholder('-'),
                    TextEntry::make('description')->label('الوصف')->placeholder('-')->columnSpanFull(),
                ]),
            Section::make('معلومات الطلب')
                ->columns(2)
                ->schema([
                    TextEntry::make('ip_address')->label('عنوان IP')->placeholder('-'),
                    TextEntry::make('method')->label('الطريقة')->placeholder('-'),
                    TextEntry::make('url')->label('الرابط')->placeholder('-')->columnSpanFull(),
                    TextEntry::make('user_agent')->label('المتصفح')->placeholder('-')->columnSpanFull(),
                ]),
            Section::make('القيم السابقة')
                ->schema([
                    self::jsonTextEntry('old_values', 'القيم السابقة'),
                ]),
            Section::make('القيم الجديدة')
                ->schema([
                    self::jsonTextEntry('new_values', 'القيم الجديدة'),
                ]),
            Section::make('بيانات إضافية')
                ->schema([
                    self::jsonTextEntry('metadata', 'بيانات إضافية'),
                ]),
        ];
    }

    protected static function jsonTextEntry(string $name, string $label): TextEntry
    {
        return TextEntry::make($name)
            ->label($label)
            ->state(fn (AuditLog $record): ?string => self::formatJsonState($record->getAttribute($name)))
            ->placeholder('-')
            ->copyable()
            ->extraAttributes([
                'class' => 'font-mono text-xs',
                'dir' => 'ltr',
                'style' => 'white-space: pre-wrap; word-break: break-word;',
            ]);
    }

    protected static function userFilterOptions($livewire, ?string $search = null): array
    {
        $collegeId = $livewire->getTableFilterFormState('user_college_id')['value'] ?? null;

        return User::query()
            ->when($collegeId, fn (Builder $query, string $collegeId): Builder => $query->where('college_id', $collegeId))
            ->when($search, fn (Builder $query, string $search): Builder => $query
                ->where(fn (Builder $query): Builder => $query
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")))
            ->orderBy('name')
            ->limit(50)
            ->pluck('name', 'id')
            ->all();
    }

    protected static function formatJsonState(mixed $state): ?string
    {
        if (blank($state)) {
            return null;
        }

        if (is_string($state)) {
            $decoded = json_decode($state, true);
            $state = json_last_error() === JSON_ERROR_NONE ? $decoded : $state;
        }

        if (! is_array($state)) {
            return (string) $state;
        }

        return json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
    }

    protected static function statusLabel(?string $status): string
    {
        return match ($status) {
            'failed' => 'فشل',
            'warning' => 'تحذير',
            default => 'ناجح',
        };
    }
}
