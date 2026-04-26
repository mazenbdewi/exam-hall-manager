<?php

namespace App\Enums;

enum InvigilationRole: string
{
    case HallHead = 'hall_head';
    case Secretary = 'secretary';
    case Regular = 'regular';
    case Reserve = 'reserve';

    public function label(): string
    {
        return __("exam.invigilation_roles.{$this->value}");
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])
            ->all();
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function fromImportValue(mixed $value): ?self
    {
        $normalized = trim((string) $value);

        return match ($normalized) {
            'hall_head', 'رئيس قاعة', 'رئيس القاعة' => self::HallHead,
            'secretary', 'أمين سر', 'امين سر', 'أمين السر' => self::Secretary,
            'regular', 'مراقب عادي' => self::Regular,
            'reserve', 'مراقب احتياط', 'احتياط' => self::Reserve,
            default => self::tryFrom($normalized),
        };
    }
}
