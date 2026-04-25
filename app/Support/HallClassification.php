<?php

namespace App\Support;

use App\Enums\ExamHallType;
use App\Models\HallSetting;
use Illuminate\Support\HtmlString;

class HallClassification
{
    public static function settings(): HallSetting
    {
        return HallSetting::current();
    }

    public static function expectedTypeForCapacity(int|string|null $capacity, ?HallSetting $settings = null): ?ExamHallType
    {
        if ($capacity === null || $capacity === '') {
            return null;
        }

        $capacity = (int) $capacity;
        $settings ??= static::settings();

        if ($capacity < $settings->large_hall_min_capacity) {
            return ExamHallType::Small;
        }

        if ($capacity < $settings->amphitheater_min_capacity) {
            return ExamHallType::Large;
        }

        return ExamHallType::Amphitheater;
    }

    public static function expectedTypeLabel(int|string|null $capacity, ?HallSetting $settings = null): ?string
    {
        return static::expectedTypeForCapacity($capacity, $settings)?->label();
    }

    public static function rulesDescription(?HallSetting $settings = null): string
    {
        $settings ??= static::settings();

        return __('exam.helpers.hall_type_rules', [
            'large' => $settings->large_hall_min_capacity,
            'amphitheater' => $settings->amphitheater_min_capacity,
        ]);
    }

    public static function expectedTypeHelperText(
        int|string|null $capacity,
        ?HallSetting $settings = null,
        ExamHallType|string|null $selectedType = null,
    ): string
    {
        $settings ??= static::settings();
        $expectedType = static::expectedTypeForCapacity($capacity, $settings);
        $selectedType = match (true) {
            $selectedType instanceof ExamHallType => $selectedType,
            is_string($selectedType) && $selectedType !== '' => ExamHallType::tryFrom($selectedType),
            default => null,
        };

        if (! $expectedType) {
            return static::rulesDescription($settings);
        }

        if (! $selectedType) {
            return __('exam.helpers.expected_hall_type', [
                'type' => $expectedType->label(),
                'large' => $settings->large_hall_min_capacity,
                'amphitheater' => $settings->amphitheater_min_capacity,
            ]);
        }

        if ($selectedType !== $expectedType) {
            return __('exam.helpers.expected_hall_type_mismatch', [
                'expected' => $expectedType->label(),
                'selected' => $selectedType->label(),
                'large' => $settings->large_hall_min_capacity,
                'amphitheater' => $settings->amphitheater_min_capacity,
            ]);
        }

        return __('exam.helpers.expected_hall_type', [
            'type' => $expectedType->label(),
            'large' => $settings->large_hall_min_capacity,
            'amphitheater' => $settings->amphitheater_min_capacity,
        ]);
    }

    public static function selectedType(
        ExamHallType|string|null $selectedType,
    ): ?ExamHallType {
        return match (true) {
            $selectedType instanceof ExamHallType => $selectedType,
            is_string($selectedType) && $selectedType !== '' => ExamHallType::tryFrom($selectedType),
            default => null,
        };
    }

    public static function hasMismatch(
        int|string|null $capacity,
        ?HallSetting $settings = null,
        ExamHallType|string|null $selectedType = null,
    ): bool {
        $expectedType = static::expectedTypeForCapacity($capacity, $settings);
        $selectedType = static::selectedType($selectedType);

        return filled($expectedType) && filled($selectedType) && ($expectedType !== $selectedType);
    }

    public static function expectedTypeHelperHtml(
        int|string|null $capacity,
        ?HallSetting $settings = null,
        ExamHallType|string|null $selectedType = null,
    ): HtmlString {
        $message = static::expectedTypeHelperText($capacity, $settings, $selectedType);

        if (static::hasMismatch($capacity, $settings, $selectedType)) {
            return new HtmlString(
                '<div class="rounded-lg border border-danger-300 bg-danger-50 px-3 py-2 text-sm font-semibold text-danger-700 dark:border-danger-500/50 dark:bg-danger-950/30 dark:text-danger-300">'
                . e($message) .
                '</div>'
            );
        }

        return new HtmlString(
            '<div class="text-sm text-gray-600 dark:text-gray-400">'
            . e($message) .
            '</div>'
        );
    }
}
